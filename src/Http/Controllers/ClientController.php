<?php

declare(strict_types=1);

namespace ServerCreator\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Eggs\EggCatalogService;
use ServerCreator\Exceptions\CreatorException;
use ServerCreator\Http\Presenter;
use ServerCreator\Models\CatalogEgg;
use ServerCreator\Models\CatalogPermission;
use ServerCreator\Models\Game;
use ServerCreator\Models\OwnedServer;
use ServerCreator\Panel\Panel;
use ServerCreator\Services\Audit;
use ServerCreator\Services\Quotas;
use ServerCreator\Services\ServerManager;
use ServerCreator\Support\CatalogPorts;
use ServerCreator\Support\Quota;

final class ClientController
{
    public function __construct(private readonly Quotas $quotas, private readonly ServerManager $manager, private readonly Audit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canImport = CatalogPermission::query()->whereKey($user->id)->exists();
        $summary = $this->quotas->summary($user);

        return new JsonResponse([
            'quota' => $summary,
            'can_import_eggs' => $canImport,
            'games' => $summary['limits']['servers'] === Quota::UNLIMITED || $summary['limits']['servers'] > 0
                ? Game::query()->availableTo($user->id, $canImport)->with('egg')->where('enabled', true)->orderBy('sort')->orderBy('name')->get()->map(static fn (Game $game): array => Presenter::game($game, true))
                : [],
            'servers' => OwnedServer::query()->with(['server' => static fn ($query) => $query->without('allocation')->select(['id', 'uuidShort', 'name']), 'game:id,name'])->where('user_id', $user->id)->get()->map(static fn (OwnedServer $owned): array => [
                'identifier' => $owned->server->uuidShort,
                'name' => $owned->server->name,
                'game' => $owned->game?->name,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'game' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'memory' => ['required', 'integer', 'min:0'],
            'disk' => ['required', 'integer', 'min:0'],
            'cpu' => ['required', 'integer', 'min:0'],
            'environment' => ['present', 'array'],
            'environment.*' => ['nullable', 'string', 'max:1000'],
            'databases' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'backups' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'allocations' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $user = $request->user();
        $game = Game::query()->availableTo($user->id, CatalogPermission::query()->whereKey($user->id)->exists())->with('egg')->where('enabled', true)->findOrFail($data['game']);
        $server = $this->manager->create($request->user(), $game, [
            'name' => $data['name'],
            'memory' => (int) $data['memory'],
            'disk' => (int) $data['disk'],
            'cpu' => (int) $data['cpu'],
            'environment' => array_map(strval(...), array_filter($data['environment'], static fn ($value): bool => $value !== null)),
            ...($game->owner_id !== null ? [
                'databases' => (int) ($data['databases'] ?? 0),
                'backups' => (int) ($data['backups'] ?? 0),
                'allocations' => (int) ($data['allocations'] ?? 0),
            ] : []),
        ]);
        $this->audit->record($user, 'server.created', $server->name, [
            'server_id' => $server->id, 'game_id' => $game->id, 'game' => $game->name, 'egg_id' => $game->egg_id,
            'memory' => (int) $server->memory, 'disk' => (int) $server->disk, 'cpu' => (int) $server->cpu,
            'databases' => (int) $server->database_limit, 'backups' => (int) $server->backup_limit, 'allocations' => (int) $server->allocation_limit,
        ]);

        return new JsonResponse(['identifier' => $server->uuidShort], 201);
    }

    public function catalog(Request $request, EggCatalogService $catalog): JsonResponse
    {
        $this->authorizeCatalog($request);

        return new JsonResponse(['eggs' => array_map(static fn ($entry): array => $entry->toArray(), $catalog->all())]);
    }

    public function catalogPorts(Request $request, EggCatalogService $catalog): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate(['catalog_id' => ['required', 'string', 'max:191']]);
        $entry = collect($catalog->all())->first(static fn ($entry): bool => $entry->id === $data['catalog_id']);
        throw_unless($entry, new CreatorException('The selected egg is no longer in the catalog.', 404));

        $ports = Cache::remember('ext:server-creator:catalog-ports:'.hash('sha256', $entry->id), 3600, static function () use ($entry): ?array {
            try {
                $response = Http::accept('text/html')->connectTimeout(3)->timeout(10)->withoutRedirecting()
                    ->get('https://eggs.pterodactyl.io/egg/'.rawurlencode($entry->id).'/');
            } catch (ConnectionException) {
                throw new CreatorException('Could not reach the egg catalog. Please enter the ports manually.', 502);
            }
            throw_unless($response->successful(), new CreatorException('Could not retrieve ports from the egg catalog. Please enter them manually.', 502));

            return CatalogPorts::fromPage($response->body());
        });

        return new JsonResponse(['default_ports' => $ports]);
    }

    public function importCatalog(Request $request, EggCatalogService $catalog, Panel $panel): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate([
            'catalog_id' => ['required', 'string', 'max:191'],
            'ports' => ['required', 'integer', 'min:1', 'max:10'],
            'default_ports' => ['present', 'array', 'list', 'max:10'],
            'default_ports.*' => ['required', 'integer', 'distinct', 'min:1', 'max:65535'],
            'port_variables' => ['present', 'array', 'list', 'max:9'],
            'port_variables.*' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9_]{1,191}$/'],
        ]);
        if ($data['default_ports'] !== [] && count($data['default_ports']) !== (int) $data['ports']) {
            throw ValidationException::withMessages(['default_ports' => 'Enter one default port for each required port.']);
        }
        if (count($data['port_variables']) > (int) $data['ports'] - 1) {
            throw ValidationException::withMessages(['port_variables' => 'Only secondary ports can have variables.']);
        }

        $entry = collect($catalog->all())->first(static fn ($entry): bool => $entry->id === $data['catalog_id']);
        throw_unless($entry, new CreatorException('The selected egg is no longer in the catalog.', 404));
        $user = $request->user();

        $game = Cache::lock('ext:server-creator:catalog:'.hash('sha256', $entry->id), 120)->block(15, function () use ($request, $data, $entry, $panel, $user): Game {
            $this->authorizeCatalog($request);

            return DB::transaction(function () use ($data, $entry, $panel, $user): Game {
                $mapping = CatalogEgg::query()->find($entry->id);
                $egg = $mapping ? \Pterodactyl\Models\Egg::query()->findOrFail($mapping->egg_id) : $panel->importCatalogEgg($entry->id);
                if (! $mapping) {
                    CatalogEgg::query()->create(['catalog_id' => $entry->id, 'egg_id' => $egg->id]);
                }

                $editable = $egg->variables()->where('user_viewable', true)->where('user_editable', true)->pluck('env_variable')->all();
                if (array_diff($data['port_variables'], $editable) !== []) {
                    throw ValidationException::withMessages(['port_variables' => 'Secondary port variables must be editable variables provided by this egg.']);
                }

                $game = Game::query()->firstOrCreate(['owner_id' => $user->id, 'egg_id' => $egg->id], [
                    'name' => mb_substr($entry->name, 0, 191),
                    'description' => mb_substr($entry->description, 0, 1000),
                    'memory_min' => Quota::SMALLEST['memory'], 'memory_max' => 2147483647,
                    'disk_min' => Quota::SMALLEST['disk'], 'disk_max' => 2147483647,
                    'cpu_min' => Quota::SMALLEST['cpu'], 'cpu_max' => 2147483647,
                    'databases' => 0, 'backups' => 0, 'allocations' => 0,
                    'ports' => (int) $data['ports'],
                    'default_ports' => array_map(intval(...), $data['default_ports']),
                    'port_variables' => $data['port_variables'],
                    'enabled' => true,
                ]);
                if (! $mapping) {
                    Activity::event('admin:egg.import')->subject($egg)->property(['name' => $egg->name, 'catalog_id' => $entry->id, 'via' => 'server-creator'])->log();
                }
                if ($game->enabled) {
                    $this->audit->record($user, 'catalog.selected', $entry->name, [
                        'catalog_id' => $entry->id, 'egg_id' => $egg->id, 'game_id' => $game->id,
                        'egg_imported' => ! $mapping, 'profile_created' => $game->wasRecentlyCreated,
                    ]);
                }

                return $game;
            });
        });

        $game->ensureCatalogSelectable();

        return new JsonResponse(['game' => Presenter::game($game->load('egg'), true)], 201);
    }

    private function authorizeCatalog(Request $request): void
    {
        throw_unless(CatalogPermission::query()->whereKey($request->user()->id)->exists(), new CreatorException('You do not have permission to import catalog eggs.', 403));
    }
}
