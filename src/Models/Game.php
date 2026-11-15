<?php

declare(strict_types=1);

namespace ServerCreator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Egg;
use ServerCreator\Steam\Store;
use ServerCreator\Support\PopularGames;

/**
 * @property int $id
 * @property int $egg_id
 * @property string $name
 * @property string|null $description
 * @property string|null $image
 * @property int|null $steam_app_id
 * @property string|null $docker_image
 * @property int $memory_min
 * @property int $memory_max
 * @property int $disk_min
 * @property int $disk_max
 * @property int $cpu_min
 * @property int $cpu_max
 * @property int $databases
 * @property int $backups
 * @property int $allocations
 * @property int $ports
 * @property list<string>|null $port_variables
 * @property string|null $port_range
 * @property list<int>|null $default_ports
 * @property int $sort
 * @property bool $enabled
 * @property Egg $egg
 */
final class Game extends Model
{
    protected $table = 'ext_server_creator_games';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $attributes = ['enabled' => true, 'ports' => 1];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'steam_app_id' => 'integer', 'ports' => 'integer', 'port_variables' => 'array', 'default_ports' => 'array'];
    }

    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    /** A custom image wins over Steam artwork. */
    public function artwork(): ?string
    {
        return $this->image ?: ($this->steam_app_id ? Store::artwork($this->steam_app_id) : null);
    }

    /** @return list<int> */
    public function preferredPorts(): array
    {
        $ports = $this->default_ports ?: PopularGames::defaultPorts($this->egg?->name ?? '');

        return count($ports) === $this->ports ? $ports : [];
    }

    /** @return list<int> */
    public function portOffsets(): array
    {
        $ports = $this->preferredPorts();

        return $ports === [] ? range(0, max(1, $this->ports) - 1) : array_map(static fn (int $port): int => $port - $ports[0], $ports);
    }
}
