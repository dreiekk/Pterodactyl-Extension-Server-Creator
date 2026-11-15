<?php

declare(strict_types=1);

namespace ServerCreator\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use ServerCreator\Exceptions\CreatorException;
use ServerCreator\Models\Game;
use ServerCreator\Models\OwnedServer;
use ServerCreator\Panel\Panel;
use ServerCreator\Support\Ports;
use ServerCreator\Support\Quota;

/**
 * Creating, resizing, switching and deleting the servers users made themselves.
 */
final readonly class ServerManager
{
    private const array NAMES = ['servers' => 'servers', 'memory' => 'memory', 'disk' => 'disk space', 'cpu' => 'CPU', 'databases' => 'databases', 'backups' => 'backups', 'allocations' => 'ports'];

    public function __construct(private Panel $panel, private Quotas $quotas) {}

    /** @param array{name: string, memory: int, disk: int, cpu: int, environment: array<string, string>} $input */
    public function create(User $user, Game $game, array $input): Server
    {
        $this->assertWithin($game, $input);

        return Cache::lock("ext:server-creator:user:{$user->id}", 60)->block(15, function () use ($user, $game, $input): Server {
            $resources = ['memory' => $input['memory'], 'disk' => $input['disk'], 'cpu' => $input['cpu'], 'databases' => $game->databases, 'backups' => $game->backups, 'allocations' => $game->allocations];
            $this->assertQuota($this->quotas->for($user)->exceeded($resources, $this->quotas->used($user)));

            $egg = $game->egg;
            $environment = $this->panel->environment($egg, $input['environment']);

            try {
                $ranges = Ports::ranges((string) $game->port_range) ?? [];
                $preferred = $game->preferredPorts();
                $allocations = $game->ports > 1 || $ranges !== [] || $preferred !== [] ? $this->panel->allocationsForGame($egg, $input['memory'], $input['disk'], $this->panel->locations(), $game->portOffsets(), $ranges, $preferred[0] ?? null) : [];

                $environment = Ports::fill($environment, array_map(static fn (Allocation $allocation): int => $allocation->port, $allocations), $game->port_variables ?? []);

                $server = $this->panel->create([
                    'name' => $input['name'],
                    'description' => '',
                    'owner_id' => $user->id,
                    'egg_id' => $egg->id,
                    'image' => $game->docker_image ?: (string) (array_values($egg->docker_images)[0] ?? ''),
                    'startup' => $egg->startup,
                    'environment' => $environment,
                    'memory' => $input['memory'],
                    'swap' => 0,
                    'disk' => $input['disk'],
                    'io' => 500,
                    'cpu' => $input['cpu'],
                    'threads' => null,
                    'oom_disabled' => true,
                    'skip_scripts' => false,
                    'start_on_completion' => true,
                    'database_limit' => $game->databases,
                    'allocation_limit' => $game->allocations,
                    'backup_limit' => $game->backups,
                ], $this->panel->locations(), $allocations);
            } catch (NoViableNodeException|NoViableAllocationException) {
                throw new CreatorException('No node has room for this server right now. Try smaller resources or ask an administrator.');
            }

            OwnedServer::query()->create(['server_id' => $server->id, 'user_id' => $user->id, 'game_id' => $game->id]);

            return $server;
        });
    }

    public function resize(User $user, Server $server, int $memory, int $disk, int $cpu): Server
    {
        $owned = $this->owned($user, $server);
        $this->assertWithin($owned->game, compact('memory', 'disk', 'cpu'));

        $resources = ['memory' => $memory, 'disk' => $disk, 'cpu' => $cpu, 'databases' => (int) $server->database_limit, 'backups' => (int) $server->backup_limit, 'allocations' => (int) $server->allocation_limit];
        $this->assertQuota($this->quotas->for($user)->exceeded($resources, $this->quotas->used($user, $server), false));

        if (! $server->node->isViable(max(0, $memory - $server->memory), max(0, $disk - $server->disk))) {
            throw new CreatorException('The node this server runs on has no room for that much.');
        }

        return $this->panel->resize($server, $memory, $disk, $cpu);
    }

    /**
     * Moves the server to another game: its egg, image, feature limits, sizes and ports. Sizes, quota, node room
     * and free ports are checked before anything changes.
     *
     * @param  array{memory: int, disk: int, cpu: int, keep_variables: bool, reinstall: bool}  $input
     */
    public function switchGame(User $user, Server $server, Game $game, array $input): Server
    {
        $owned = $this->owned($user, $server);
        throw_if($owned->game_id === $game->id, new CreatorException('The server already runs this game.'));
        $this->assertWithin($game, $input);

        return Cache::lock("ext:server-creator:user:{$user->id}", 60)->block(15, function () use ($user, $server, $game, $input, $owned): Server {
            $limits = ['databases' => $game->databases, 'allocations' => $game->allocations, 'backups' => $game->backups];
            $this->assertQuota($this->quotas->for($user)->exceeded(['memory' => $input['memory'], 'disk' => $input['disk'], 'cpu' => $input['cpu']] + $limits, $this->quotas->used($user, $server), false));

            if (! $server->node->isViable(max(0, $input['memory'] - $server->memory), max(0, $input['disk'] - $server->disk))) {
                throw new CreatorException('The node this server runs on has no room for that much.');
            }

            throw_unless($this->panel->isStopped($server), new CreatorException('Stop the server before switching the game.', 409));

            $ranges = Ports::ranges((string) $game->port_range) ?? [];

            if (Ports::runFrom($this->panel->allocationsOf($server), $server->allocation_id, $game->ports, $ranges, $game->portOffsets()) === null) {
                try {
                    $this->panel->freePortsOnNode($server, $game->portOffsets(), $ranges);
                } catch (NoViableAllocationException) {
                    throw new CreatorException('The node this server runs on has no free ports for this game. Ask an administrator.');
                }
            }

            $previous = $owned->game?->name;
            // Bound first, so the egg change keeps this game instead of picking one by egg.
            $owned->update(['game_id' => $game->id]);

            if ($server->egg_id !== $game->egg_id) {
                $server = $this->panel->changeEgg($server, $game->egg, $input['keep_variables']);
            }

            $image = $game->docker_image ?: (string) (array_values($game->egg->docker_images)[0] ?? '');

            if ($image !== '' && $server->image !== $image) {
                $server = $this->panel->setImage($server, $image);
            }

            $server = $this->fitPorts($this->panel->resize($server, $input['memory'], $input['disk'], $input['cpu'], $limits), $game);
            $this->panel->log('server:startup.edit', $server, ['variable' => 'Game', 'old' => $previous, 'new' => $game->name]);

            if ($input['reinstall']) {
                $this->panel->reinstall($server);
            }

            return $server;
        });
    }

    /**
     * Gives the server the ports its game needs: when its own do not fit the game's range and port pattern,
     * free ones on its node replace all of them. The extra ports go into the game's port variables.
     *
     * @throws NoViableAllocationException
     */
    public function fitPorts(Server $server, Game $game): Server
    {
        $ranges = Ports::ranges((string) $game->port_range) ?? [];
        $ports = Ports::runFrom($this->panel->allocationsOf($server), $server->allocation_id, $game->ports, $ranges, $game->portOffsets());

        if ($ports === null) {
            $run = $this->panel->freePortsOnNode($server, $game->portOffsets(), $ranges);
            $server = $this->panel->replaceAllocations($server, $run);
            $ports = array_map(static fn (Allocation $allocation): int => $allocation->port, $run);
        }

        $environment = $this->panel->variableValues($server);
        $this->panel->setVariables($server, array_diff_assoc(Ports::fill($environment, $ports, $game->port_variables ?? []), $environment));

        return $server;
    }

    public function delete(User $user, Server $server): void
    {
        $this->owned($user, $server);
        $this->panel->delete($server);
    }

    /** @param list<int> $ids */
    public function updateAllocations(User $user, Server $server, int $primary, array $ids): void
    {
        throw_unless((int) $server->owner_id === (int) $user->id, new CreatorException('Only the server owner can manage its ports.', 403));

        Cache::lock("ext:server-creator:user:{$user->id}", 60)->block(15, function () use ($user, $server, $primary, $ids): void {
            DB::transaction(function () use ($user, $server, $primary, $ids): void {
                $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
                throw_unless((int) $server->owner_id === (int) $user->id, new CreatorException('Only the server owner can manage its ports.', 403));
                throw_unless($this->panel->isStopped($server), new CreatorException('Stop the server before changing its ports.', 409));
                $game = OwnedServer::query()->with('game')->find($server->id)?->game;
                $ranges = Ports::ranges((string) $game?->port_range) ?? [];
                $held = $this->panel->allocationsOf($server);
                $max = max((int) $server->allocation_limit, (int) ($game?->ports ?? 1), count($held));
                throw_if(count($ids) > $max, new CreatorException('The server has reached its port limit.'));
                throw_unless(in_array($primary, $ids, true), new CreatorException('Select a primary port.'));

                $allocations = Allocation::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                throw_unless($allocations->count() === count($ids), new CreatorException('One or more ports are no longer available.', 409));

                foreach ($allocations as $allocation) {
                    throw_if((int) $allocation->node_id !== (int) $server->node_id || ($allocation->server_id !== null && (int) $allocation->server_id !== (int) $server->id), new CreatorException('One or more ports are no longer available on this node.', 409));
                    $inRange = $ranges === [] || array_filter($ranges, static fn (array $range): bool => $allocation->port >= $range[0] && $allocation->port <= $range[1]) !== [];
                    throw_unless($inRange || (int) $allocation->server_id === (int) $server->id, new CreatorException('A selected port is outside the game port range.'));
                }

                $selected = $allocations->map(static fn (Allocation $allocation): array => ['id' => (int) $allocation->id, 'ip' => (string) $allocation->ip, 'port' => (int) $allocation->port])->values()->all();
                $ports = Ports::runFrom($selected, $primary, $game?->ports ?? 1, $ranges, $game?->portOffsets());
                throw_if($ports === null, new CreatorException('Select all required ports in the game port pattern and range on the primary IP.'));

                $ordered = [$allocations[$primary]];
                foreach ($ids as $id) {
                    if ($id !== $primary) {
                        $ordered[] = $allocations[$id];
                    }
                }
                $server = $this->panel->replaceAllocations($server, $ordered);
                if ($game !== null) {
                    $environment = $this->panel->variableValues($server);
                    $this->panel->setVariables($server, array_diff_assoc(Ports::fill($environment, $ports, $game->port_variables ?? []), $environment));
                }
            });
        });
    }

    public function swapAllocations(User $user, Server $server, int $otherId): void
    {
        throw_if($server->id === $otherId, new CreatorException('Choose another server.'));
        throw_unless((int) $server->owner_id === (int) $user->id, new CreatorException('Only the server owner can manage its ports.', 403));

        Cache::lock("ext:server-creator:user:{$user->id}", 60)->block(15, function () use ($user, $server, $otherId): void {
            $servers = DB::transaction(function () use ($user, $server, $otherId): array {
                $servers = Server::query()->without('allocation')->whereIn('id', [$server->id, $otherId])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $first = $servers->get($server->id);
                $second = $servers->get($otherId);
                throw_unless($first !== null && $second !== null && (int) $first->owner_id === (int) $user->id && (int) $second->owner_id === (int) $user->id, new CreatorException('Both servers must belong to you.', 403));
                throw_unless($first->node_id === $second->node_id, new CreatorException('Both servers must be on the same node.'));
                throw_unless($this->panel->isStopped($first) && $this->panel->isStopped($second), new CreatorException('Stop both servers before swapping their ports.', 409));

                $allocations = Allocation::query()->whereIn('server_id', [$first->id, $second->id])
                    ->orderBy('id')->lockForUpdate()->get();
                $firstPorts = $allocations->where('server_id', $first->id)->values();
                $secondPorts = $allocations->where('server_id', $second->id)->values();
                throw_if($allocations->contains(static fn (Allocation $allocation): bool => $allocation->node_id !== $first->node_id), new CreatorException('Both port sets must be on the servers\' node.', 409));
                $games = OwnedServer::query()->with('game')->whereIn('server_id', [$first->id, $second->id])->get()->keyBy('server_id');
                $firstGame = $games->get($first->id)?->game;
                $secondGame = $games->get($second->id)?->game;

                $firstValues = $this->assertSwapFits($first, $firstGame, $secondPorts->all(), $second->allocation_id, $firstPorts->count());
                $secondValues = $this->assertSwapFits($second, $secondGame, $firstPorts->all(), $first->allocation_id, $secondPorts->count());

                // The panel has a non-null, unique primary allocation: use a locked spare while exchanging them.
                $spare = $allocations->first(static fn (Allocation $allocation): bool => $allocation->id !== $first->allocation_id && $allocation->id !== $second->allocation_id);
                $spare ??= Allocation::query()->onNodes([$first->node_id])->unassigned()
                    ->whereNotIn('id', Server::query()->without('allocation')->select('allocation_id'))
                    ->orderBy('id')->lockForUpdate()->first();
                throw_if($spare === null, new CreatorException('A spare allocation on this node is needed to swap these ports. Ask an administrator.', 409));

                $firstPrimary = $first->allocation_id;
                $secondPrimary = $second->allocation_id;
                $first->forceFill(['allocation_id' => $spare->id])->saveOrFail();
                $second->forceFill(['allocation_id' => $firstPrimary])->saveOrFail();
                $first->forceFill(['allocation_id' => $secondPrimary])->saveOrFail();

                Allocation::query()->whereKey($firstPorts->modelKeys())->update(['server_id' => $second->id]);
                Allocation::query()->whereKey($secondPorts->modelKeys())->update(['server_id' => $first->id]);
                foreach ([[$first, $firstGame, $firstValues, $second], [$second, $secondGame, $secondValues, $first]] as [$target, $game, $ports, $source]) {
                    if ($game !== null) {
                        $environment = $this->panel->variableValues($target);
                        $this->panel->setVariables($target, array_diff_assoc(Ports::fill($environment, $ports, $game->port_variables ?? []), $environment));
                    }
                    $this->panel->log('server:allocation.primary', $target, ['allocation' => $allocations->firstWhere('id', $target->allocation_id)->toString(), 'swapped_with' => $source->name]);
                }

                return [$first, $second];
            });

            foreach ($servers as $changed) {
                $this->panel->sync($changed);
            }
        });
    }

    public function owned(User $user, Server $server): OwnedServer
    {
        return OwnedServer::query()->with('game')->where('user_id', $user->id)->where('server_id', $server->id)->firstOr(
            static fn () => throw new CreatorException('Only servers you created yourself can be changed here.', 403),
        );
    }

    /**
     * @param  list<Allocation>  $incoming
     * @return list<int>
     */
    private function assertSwapFits(Server $server, ?Game $game, array $incoming, int $primary, int $currentCount): array
    {
        $limit = max((int) $server->allocation_limit, (int) ($game?->ports ?? 1), $currentCount);
        $ranges = Ports::ranges((string) $game?->port_range) ?? [];
        $rows = array_map(static fn (Allocation $allocation): array => ['id' => (int) $allocation->id, 'ip' => (string) $allocation->ip, 'port' => (int) $allocation->port], $incoming);
        $ports = Ports::swapFit($rows, $primary, $limit, $ranges, $game?->portOffsets() ?? [0]);
        throw_if($ports === null, new CreatorException("The port set does not fit {$server->name}'s port limit, range or required game port pattern."));

        return $ports;
    }

    /**
     * Servers whose game was deleted still may not drop to 0, which Pterodactyl treats as unlimited.
     *
     * @param  array{memory: int, disk: int, cpu: int}  $resources
     */
    private function assertWithin(?Game $game, array $resources): void
    {
        foreach (['memory', 'disk', 'cpu'] as $resource) {
            $min = $game?->{"{$resource}_min"} ?? Quota::SMALLEST[$resource];
            $max = $game?->{"{$resource}_max"} ?? PHP_INT_MAX;

            if ($resources[$resource] < $min || $resources[$resource] > $max) {
                throw new CreatorException($game === null
                    ? sprintf('%s has to be at least %d.', ucfirst(self::NAMES[$resource]), $min)
                    : sprintf('%s has to be between %d and %d.', ucfirst(self::NAMES[$resource]), $min, $max));
            }
        }
    }

    private function assertQuota(?string $exceeded): void
    {
        if ($exceeded !== null) {
            throw new CreatorException($exceeded === 'servers' ? 'You cannot create more servers.' : 'You do not have enough '.self::NAMES[$exceeded].' left.');
        }
    }
}
