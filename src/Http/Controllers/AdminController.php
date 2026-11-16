<?php

declare(strict_types=1);

namespace ServerCreator\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use ServerCreator\Http\Presenter;
use ServerCreator\Models\CatalogPermission;
use ServerCreator\Models\Copy;
use ServerCreator\Models\Game;
use ServerCreator\Models\OwnedServer;
use ServerCreator\Models\QuotaLimit;
use ServerCreator\Panel\Panel;
use ServerCreator\Services\Audit;
use ServerCreator\Services\Duplicator;
use ServerCreator\Services\Quotas;
use ServerCreator\Steam\Store;
use ServerCreator\Support\PopularGames;
use ServerCreator\Support\Ports;
use ServerCreator\Support\Quota;

final class AdminController
{
    public function __construct(private readonly Panel $panel, private readonly Quotas $quotas, private readonly Audit $audit) {}

    public function index(): JsonResponse
    {
        $games = Game::query()->whereNull('owner_id')->with('egg:id,name')->orderBy('sort')->orderBy('name')->get();
        $eggs = $this->panel->eggs();

        return new JsonResponse([
            'games' => $games->map(static fn (Game $game): array => Presenter::game($game)),
            'eggs' => $eggs->map(static fn ($egg): array => ['id' => $egg->id, 'name' => $egg->name, 'images' => array_values($egg->docker_images)]),
            'popular' => array_map(
                static fn (array $game): array => $game + ['artwork' => $game['steam_app_id'] ? Store::artwork($game['steam_app_id']) : null],
                PopularGames::available($eggs->map(static fn ($egg): array => ['id' => $egg->id, 'name' => $egg->name]), $games->pluck('egg_id')->map(intval(...))->all()),
            ),
            'quota' => QuotaLimit::query()->whereNull('user_id')->first()?->limits() ?? array_fill_keys(Quota::RESOURCES, 0),
            'users' => QuotaLimit::query()->with('user:id,username,email')->whereNotNull('user_id')->get()->map(static fn (QuotaLimit $row): array => [
                'id' => $row->user_id,
                'username' => $row->user->username,
                'email' => $row->user->email,
                'limits' => $row->limits(),
            ]),
            'locations' => [
                'all' => Location::query()->orderBy('short')->get(['id', 'short', 'long']),
                'selected' => $this->panel->locations(),
            ],
        ]);
    }

    public function storeGame(Request $request): JsonResponse
    {
        $data = $this->validatedGame($request, importable: true);
        $catalog = Arr::pull($data, 'catalog_id');
        $eggImported = ($data['egg_id'] ?? null) === null;
        $data['egg_id'] ??= $this->panel->importCatalogEgg($catalog)->id;

        $game = Game::query()->create($data);
        $this->audit->record($request->user(), 'game.created', $game->name, ['game_id' => $game->id, 'egg_id' => $game->egg_id, 'catalog_id' => $catalog, 'egg_imported' => $eggImported]);

        return $this->index();
    }

    public function updateGame(Request $request, int $id): JsonResponse
    {
        $game = Game::query()->whereNull('owner_id')->findOrFail($id);
        $before = $game->only(['name', 'egg_id', 'enabled', 'ports', 'default_ports', 'port_variables', 'port_range', 'memory_min', 'memory_max', 'disk_min', 'disk_max', 'cpu_min', 'cpu_max', 'databases', 'backups', 'allocations']);
        $game->update($this->validatedGame($request));
        $this->audit->record($request->user(), 'game.updated', $game->name, ['game_id' => $game->id, 'before' => $before, 'after' => $game->only(array_keys($before))]);

        return $this->index();
    }

    public function destroyGame(Request $request, int $id): JsonResponse
    {
        $game = Game::query()->whereNull('owner_id')->findOrFail($id);
        $game->delete();
        $this->audit->record($request->user(), 'game.deleted', $game->name, ['game_id' => $game->id, 'egg_id' => $game->egg_id]);

        return $this->index();
    }

    public function updateQuota(Request $request): JsonResponse
    {
        $limits = $this->validatedQuota($request);
        $previous = QuotaLimit::query()->whereNull('user_id')->first()?->limits();
        QuotaLimit::query()->updateOrCreate(['user_id' => null], Quota::attributesFor($limits));
        $this->audit->record($request->user(), 'quota.default_updated', 'Default quota', ['before' => $previous, 'after' => $limits]);

        return $this->index();
    }

    public function updateLocations(Request $request): JsonResponse
    {
        $data = $request->validate(['locations' => ['present', 'array'], 'locations.*' => ['integer', 'exists:locations,id']]);
        $before = $this->panel->locations();
        $locations = array_values(array_unique(array_map(intval(...), $data['locations'])));
        $this->panel->setLocations($locations);
        $this->audit->record($request->user(), 'locations.updated', 'Placement locations', ['before' => $before, 'after' => $locations]);

        return $this->index();
    }

    public function users(Request $request): JsonResponse
    {
        $query = (string) $request->query('query', '');

        return new JsonResponse(['users' => User::query()
            ->when($query !== '', static fn ($builder) => $builder->where(static fn ($inner) => $inner->where('username', 'like', "%{$query}%")->orWhere('email', 'like', "%{$query}%")))
            ->orderBy('username')
            ->limit(25)
            ->get(['id', 'username', 'email'])]);
    }

    public function user(int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $own = QuotaLimit::query()->where('user_id', $user->id)->first();

        return new JsonResponse([
            'custom' => $own?->limits(),
            'can_import_eggs' => CatalogPermission::query()->whereKey($user->id)->exists(),
            'quota' => $this->quotas->summary($user),
            'servers' => OwnedServer::query()->with(['server' => static fn ($query) => $query->without('allocation')->select(['id', 'name']), 'game:id,name'])->where('user_id', $user->id)->get()->map(static fn (OwnedServer $owned): array => [
                'id' => $owned->server->id,
                'name' => $owned->server->name,
                'game' => $owned->game?->name,
            ]),
        ]);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $limits = $this->validatedQuota($request);
        $previous = QuotaLimit::query()->where('user_id', $user->id)->first()?->limits();
        QuotaLimit::query()->updateOrCreate(['user_id' => $user->id], Quota::attributesFor($limits));
        $this->audit->record($request->user(), 'quota.user_updated', $user->username, ['user_id' => $user->id, 'before' => $previous, 'after' => $limits]);

        return $this->user($id);
    }

    public function resetUser(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $previous = QuotaLimit::query()->where('user_id', $id)->first()?->limits();
        QuotaLimit::query()->where('user_id', $id)->delete();
        if ($previous !== null) {
            $this->audit->record($request->user(), 'quota.user_reset', $user->username, ['user_id' => $id, 'before' => $previous]);
        }

        return $this->user($id);
    }

    public function updateCatalogPermission(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $data = $request->validate(['allowed' => ['required', 'boolean']]);
        $previous = CatalogPermission::query()->whereKey($user->id)->exists();
        if ($data['allowed']) {
            CatalogPermission::query()->firstOrCreate(['user_id' => $user->id]);
        } else {
            CatalogPermission::query()->whereKey($user->id)->delete();
        }
        if ($previous !== (bool) $data['allowed']) {
            $this->audit->record($request->user(), 'catalog.permission_changed', $user->username, ['user_id' => $user->id, 'allowed' => (bool) $data['allowed']]);
        }

        return $this->user($id);
    }

    public function steam(Request $request, Store $store): JsonResponse
    {
        return new JsonResponse(['results' => $store->search((string) $request->query('term', ''))]);
    }

    public function duplicate(Request $request, Duplicator $duplicator, int $id): JsonResponse
    {
        $source = Server::query()->with('node')->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'owner' => ['required', 'integer', 'exists:users,id'],
            'files' => ['boolean'],
        ]);

        $server = $duplicator->duplicate($source, $request->user(), $data['name'], User::query()->findOrFail($data['owner']), (bool) ($data['files'] ?? false));
        $this->audit->record($request->user(), 'server.duplicated', $server->name, [
            'server_id' => $server->id, 'source_id' => $source->id, 'source' => $source->name,
            'owner_id' => (int) $data['owner'], 'files_requested' => (bool) ($data['files'] ?? false),
        ]);

        return new JsonResponse(['id' => $server->id, 'identifier' => $server->uuidShort], 201);
    }

    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);

        return new JsonResponse($this->audit->page(isset($data['before']) ? (int) $data['before'] : null));
    }

    public function copies(int $id): JsonResponse
    {
        $names = static fn ($query) => $query->without('allocation')->select(['id', 'name']);

        return new JsonResponse(['copies' => Copy::query()
            ->with(['source' => $names, 'target' => $names])
            ->where(static fn ($query) => $query->where('source_id', $id)->orWhere('target_id', $id))
            ->latest()
            ->limit(5)
            ->get()
            ->map(static fn (Copy $copy): array => [
                'id' => $copy->id,
                'source' => $copy->source->name,
                'target' => ['id' => $copy->target_id, 'name' => $copy->target->name],
                'step' => $copy->step,
                'error' => $copy->error,
                'created_at' => $copy->created_at->toAtomString(),
            ])]);
    }

    /**
     * With `importable`, a game from the popular list may leave out the egg, which is then imported from the catalog.
     *
     * @return array<string, mixed>
     */
    private function validatedGame(Request $request, bool $importable = false): array
    {
        $data = $request->validate([
            'egg_id' => $importable ? ['required_without:catalog_id', 'nullable', 'integer', 'exists:eggs,id'] : ['required', 'integer', 'exists:eggs,id'],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'url:https', 'max:500'],
            'steam_app_id' => ['nullable', 'integer', 'min:1'],
            'docker_image' => ['nullable', 'string', 'max:191', 'regex:/^~?[\w\.\/\-:@ ]*$/'],
            'memory_min' => ['required', 'integer', 'min:64'],
            'memory_max' => ['required', 'integer', 'gte:memory_min', 'max:1048576'],
            'disk_min' => ['required', 'integer', 'min:128'],
            'disk_max' => ['required', 'integer', 'gte:disk_min', 'max:10485760'],
            'cpu_min' => ['required', 'integer', 'min:10'],
            'cpu_max' => ['required', 'integer', 'gte:cpu_min', 'max:10000'],
            'databases' => ['required', 'integer', 'min:0', 'max:100'],
            'backups' => ['required', 'integer', 'min:0', 'max:100'],
            'allocations' => ['required', 'integer', 'min:0', 'max:100'],
            'ports' => ['integer', 'min:1', 'max:10'],
            'port_variables' => ['nullable', 'array', 'max:9'],
            'port_variables.*' => ['string', 'distinct', 'regex:/^[A-Za-z0-9_]{1,191}$/'],
            'default_ports' => ['nullable', 'array', 'list', 'max:10'],
            'default_ports.*' => ['required', 'integer', 'distinct', 'min:1', 'max:65535'],
            'port_range' => ['nullable', 'string', 'max:191', static function (string $attribute, mixed $value, Closure $fail): void {
                if (Ports::ranges((string) $value) === null) {
                    $fail('Enter ports and ranges like 25565-25600, 27015.');
                }
            }],
            'sort' => ['integer', 'min:-1000', 'max:1000'],
            'enabled' => ['boolean'],
            ...($importable ? ['catalog_id' => ['nullable', 'string', Rule::in(array_keys(PopularGames::GAMES))]] : []),
        ]);

        if (! empty($data['default_ports']) && count($data['default_ports']) !== (int) ($data['ports'] ?? 1)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['default_ports' => 'Enter one default port for each required port.']);
        }

        if (array_key_exists('default_ports', $data)) {
            $data['default_ports'] = array_map(intval(...), $data['default_ports'] ?? []);
        }

        if (array_key_exists('port_range', $data)) {
            $data['port_range'] = Ports::format(Ports::ranges((string) $data['port_range']) ?? []) ?: null;
        }

        return $data + ['sort' => 0, 'enabled' => true, 'ports' => 1, 'port_variables' => []];
    }

    /** @return array<string, int> */
    private function validatedQuota(Request $request): array
    {
        return array_map(intval(...), $request->validate(
            collect(Quota::RESOURCES)->mapWithKeys(static fn (string $resource): array => [$resource => ['required', 'integer', 'min:-1', 'max:100000000']])->all(),
        ));
    }
}
