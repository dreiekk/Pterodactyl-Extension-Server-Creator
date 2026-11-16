<?php

declare(strict_types=1);

namespace ServerCreator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use ServerCreator\Exceptions\CreatorException;
use ServerCreator\Http\Presenter;
use ServerCreator\Models\CatalogPermission;
use ServerCreator\Models\Game;
use ServerCreator\Models\OwnedServer;
use ServerCreator\Panel\Panel;
use ServerCreator\Services\Audit;
use ServerCreator\Services\Quotas;
use ServerCreator\Services\ServerManager;
use ServerCreator\Settings;
use ServerCreator\Support\Ports;
use ServerCreator\Support\Quota;

/**
 * Resize, switch the game and delete, for the owner of a server they created themselves.
 */
final class ServerController
{
    public function __construct(private readonly ServerManager $manager, private readonly Quotas $quotas, private readonly Settings $settings, private readonly Panel $panel, private readonly Audit $audit) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();
        $owned = OwnedServer::query()->with('game')->where('user_id', $user->id)->find($server->id);

        if ($owned === null) {
            return new JsonResponse(['owned' => false, 'can_manage_ports' => (int) $server->owner_id === (int) $user->id]);
        }

        $quota = $this->quotas->for($user);
        $available = $quota->remaining($this->quotas->used($user, $server));
        $game = $owned->game;

        return new JsonResponse([
            'owned' => true,
            'can_manage_ports' => (int) $server->owner_id === (int) $user->id,
            'allow_resize' => $this->settings->allowResize(),
            'allow_switch' => $this->settings->allowSwitch(),
            'allow_delete' => $this->settings->allowDelete(),
            'game' => $game?->name,
            'game_id' => $game?->id,
            'artwork' => $game?->artwork(),
            'current' => ['memory' => $server->memory, 'disk' => $server->disk, 'cpu' => $server->cpu],
            'limits' => collect(['memory', 'disk', 'cpu'])->mapWithKeys(static fn (string $resource): array => [$resource => [
                'min' => $game?->{"{$resource}_min"} ?? Quota::SMALLEST[$resource],
                'max' => $available[$resource] === Quota::UNLIMITED
                    ? ($game?->{"{$resource}_max"} ?? PHP_INT_MAX)
                    : min($game?->{"{$resource}_max"} ?? PHP_INT_MAX, $available[$resource]),
            ]])->all(),
            'quota' => collect(['memory', 'disk', 'cpu'])->mapWithKeys(static fn (string $resource): array => [$resource => [
                'limit' => $quota->limits[$resource] ?? 0,
                'available' => $available[$resource],
            ]])->all(),
            'remaining' => $available,
            'games' => $this->settings->allowSwitch()
                ? Game::query()->availableTo($user->id, CatalogPermission::query()->whereKey($user->id)->exists())->with('egg:id,name')->where('enabled', true)->whereKeyNot($game?->id ?? 0)->orderBy('sort')->orderBy('name')->get()->map(static fn (Game $item): array => Presenter::game($item))->all()
                : [],
        ]);
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        throw_unless($this->settings->allowResize(), new CreatorException('Resizing servers is turned off.', 403));

        $data = $request->validate([
            'memory' => ['required', 'integer', 'min:0'],
            'disk' => ['required', 'integer', 'min:0'],
            'cpu' => ['required', 'integer', 'min:1'],
        ]);

        $before = $server->only(['memory', 'disk', 'cpu']);
        $this->manager->resize($request->user(), $server, (int) $data['memory'], (int) $data['disk'], (int) $data['cpu']);
        $this->audit->record($request->user(), 'server.resized', $server->name, ['server_id' => $server->id, 'before' => $before, 'after' => $data]);

        return $this->index($request, $server->refresh());
    }

    public function game(Request $request, Server $server): JsonResponse
    {
        throw_unless($this->settings->allowSwitch(), new CreatorException('Switching the game is turned off.', 403));

        $data = $request->validate([
            'game' => ['required', 'integer'],
            'memory' => ['required', 'integer', 'min:0'],
            'disk' => ['required', 'integer', 'min:0'],
            'cpu' => ['required', 'integer', 'min:1'],
            'keep_variables' => ['required', 'boolean'],
            'reinstall' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $game = Game::query()->availableTo($user->id, CatalogPermission::query()->whereKey($user->id)->exists())->with('egg')->where('enabled', true)->find((int) $data['game']) ?? throw new CreatorException('This game cannot be picked.', 422);

        $previousEgg = $server->egg_id;
        $this->manager->switchGame($request->user(), $server, $game, [
            'memory' => (int) $data['memory'],
            'disk' => (int) $data['disk'],
            'cpu' => (int) $data['cpu'],
            'keep_variables' => (bool) $data['keep_variables'],
            'reinstall' => (bool) $data['reinstall'],
        ]);
        $this->audit->record($user, 'server.game_switched', $server->name, [
            'server_id' => $server->id, 'previous_egg_id' => $previousEgg, 'game_id' => $game->id, 'game' => $game->name,
            'egg_id' => $game->egg_id, 'memory' => (int) $data['memory'], 'disk' => (int) $data['disk'], 'cpu' => (int) $data['cpu'],
            'keep_variables' => (bool) $data['keep_variables'], 'reinstall' => (bool) $data['reinstall'],
        ]);

        return $this->index($request, $server->refresh());
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        throw_unless($this->settings->allowDelete(), new CreatorException('Deleting servers is turned off.', 403));
        throw_unless($request->input('name') === $server->name, new CreatorException('Type the server name to confirm.'));

        $this->manager->delete($request->user(), $server);
        $this->audit->record($request->user(), 'server.deleted', $server->name, ['server_id' => $server->id]);

        return new JsonResponse(status: 204);
    }

    public function allocations(Request $request, Server $server): JsonResponse
    {
        throw_unless((int) $server->owner_id === (int) $request->user()->id, new CreatorException('Only the server owner can manage its ports.', 403));
        $game = OwnedServer::query()->with('game')->find($server->id)?->game;
        $held = $this->panel->allocationsOf($server);

        return new JsonResponse([
            'primary' => $server->allocation_id,
            'required' => $game?->ports ?? 1,
            'offsets' => $game?->portOffsets() ?? [0],
            'range' => $game?->port_range,
            'limit' => max((int) $server->allocation_limit, (int) ($game?->ports ?? 1), count($held)),
            'allocations' => $this->panel->selectableAllocations($server, Ports::ranges((string) $game?->port_range) ?? []),
            'swap_servers' => $this->panel->swapCandidates($server),
        ]);
    }

    public function updateAllocations(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'primary' => ['required', 'integer', 'min:1'],
            'allocations' => ['required', 'array', 'min:1', 'max:101'],
            'allocations.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $before = $this->panel->allocationsOf($server);
        $this->manager->updateAllocations($request->user(), $server, (int) $data['primary'], array_map(intval(...), $data['allocations']));
        $this->audit->record($request->user(), 'server.ports_changed', $server->name, ['server_id' => $server->id, 'before' => $before, 'after' => $this->panel->allocationsOf($server), 'primary' => (int) $data['primary']]);

        return $this->allocations($request, $server->refresh());
    }

    public function swapAllocations(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate(['server' => ['required', 'integer', 'min:1']]);
        $before = $this->panel->allocationsOf($server);
        $this->manager->swapAllocations($request->user(), $server, (int) $data['server']);
        $this->audit->record($request->user(), 'server.ports_swapped', $server->name, ['server_id' => $server->id, 'other_server_id' => (int) $data['server'], 'before' => $before, 'after' => $this->panel->allocationsOf($server)]);

        return $this->allocations($request, $server->refresh());
    }
}
