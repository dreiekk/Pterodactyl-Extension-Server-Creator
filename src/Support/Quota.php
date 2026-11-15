<?php

declare(strict_types=1);

namespace ServerCreator\Support;

/**
 * What a user may still create. A limit of 0 allows nothing; -1 is unlimited.
 */
final readonly class Quota
{
    public const array RESOURCES = ['servers', 'memory', 'disk', 'cpu', 'databases', 'backups', 'allocations'];

    public const int UNLIMITED = -1;

    /** The smallest size a self-created server may have; 0 would mean unlimited to Pterodactyl. */
    public const array SMALLEST = ['memory' => 64, 'disk' => 128, 'cpu' => 10];

    /** @param array<string, int> $limits */
    public function __construct(public array $limits) {}

    /**
     * Store unlimited flags separately because the existing numeric quota columns are unsigned.
     *
     * @param  array<string, int>  $limits
     * @return array<string, mixed>
     */
    public static function attributesFor(array $limits): array
    {
        $unlimited = [];
        foreach ($limits as $resource => $value) {
            if ($value === self::UNLIMITED) {
                $unlimited[] = $resource;
                $limits[$resource] = 0;
            }
        }

        return $limits + ['unlimited' => $unlimited];
    }

    /** @param array<string, int> $limits @param list<string> $unlimited @return array<string, int> */
    public static function restore(array $limits, array $unlimited): array
    {
        foreach ($unlimited as $resource) {
            if (in_array($resource, self::RESOURCES, true)) {
                $limits[$resource] = self::UNLIMITED;
            }
        }

        return $limits;
    }

    /**
     * @param  array<string, int>  $used
     * @return array<string, int>
     */
    public function remaining(array $used): array
    {
        $remaining = [];

        foreach (self::RESOURCES as $resource) {
            $limit = $this->limits[$resource] ?? 0;
            $remaining[$resource] = $limit === self::UNLIMITED ? self::UNLIMITED : max(0, $limit - ($used[$resource] ?? 0));
        }

        return $remaining;
    }

    /**
     * The first resource the request does not fit into, or null when everything fits.
     *
     * @param  array<string, int>  $request  One server's resources, without the `servers` count.
     * @param  array<string, int>  $used  For a resize, without the server being resized.
     */
    public function exceeded(array $request, array $used, bool $newServer = true): ?string
    {
        $remaining = $this->remaining($used);

        if ($newServer && $remaining['servers'] !== self::UNLIMITED && $remaining['servers'] < 1) {
            return 'servers';
        }

        foreach ($request as $resource => $amount) {
            if ($resource !== 'servers' && in_array($resource, self::RESOURCES, true) && $remaining[$resource] !== self::UNLIMITED && $amount > $remaining[$resource]) {
                return $resource;
            }
        }

        return null;
    }
}
