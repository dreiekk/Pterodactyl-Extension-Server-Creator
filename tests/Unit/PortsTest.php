<?php

declare(strict_types=1);

namespace ServerCreator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ServerCreator\Support\Ports;

final class PortsTest extends TestCase
{
    public function test_swap_checks_both_required_pattern_and_entire_port_set(): void
    {
        $ports = [
            ['id' => 1, 'ip' => '10.0.0.1', 'port' => 27015],
            ['id' => 2, 'ip' => '10.0.0.1', 'port' => 27016],
            ['id' => 3, 'ip' => '10.0.0.2', 'port' => 28000],
        ];
        $this->assertSame([27015, 27016], Ports::swapFit($ports, 1, 3, [], [0, 1]));
        $this->assertNull(Ports::swapFit($ports, 1, 2, [], [0, 1]));
        $this->assertNull(Ports::swapFit($ports, 1, 3, [[27015, 27016]], [0, 1]));
        $this->assertNull(Ports::swapFit($ports, 2, 3, [], [0, 1]));
        $this->assertNull(Ports::swapFit($ports, 99, 3, [], [0, 1]));
        $this->assertNull(Ports::swapFit($ports, 1, 3, [], [0, 985]));
    }

    public function test_preferred_port_sets_and_fallback_preserve_offsets_on_one_node_and_ip(): void
    {
        $free = [
            ['id' => 1, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 27014],
            ['id' => 2, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 27015],
            ['id' => 3, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 27016],
            ['id' => 4, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 27115],
            ['id' => 5, 'node' => 2, 'ip' => '10.0.0.1', 'port' => 27215],
            ['id' => 6, 'node' => 1, 'ip' => '10.0.0.2', 'port' => 27215],
        ];

        $this->assertSame([2, 3], Ports::select($free, [0, 1], 27015));
        $this->assertSame([2, 4], Ports::select($free, [0, 100], 27015));
        $this->assertSame([4, 2], Ports::select($free, [0, -100], 27115));
        $this->assertSame([2, 4], Ports::select($free, [0, 100], 12345));
        $this->assertNull(Ports::select($free, [0, 200], 27015));
        $this->assertSame([27015, 27115], Ports::runFrom([
            ['id' => 2, 'ip' => '10.0.0.1', 'port' => 27015],
            ['id' => 4, 'ip' => '10.0.0.1', 'port' => 27115],
        ], 2, 2, [[27000, 27200]], [0, 100]));
        $this->assertNull(Ports::runFrom([
            ['id' => 2, 'ip' => '10.0.0.1', 'port' => 27015],
            ['id' => 4, 'ip' => '10.0.0.1', 'port' => 27115],
        ], 2, 2, [[27000, 27100]], [0, 100]));
    }

    public function test_runs_need_consecutive_ports_on_one_node_and_ip(): void
    {
        $free = [
            ['id' => 1, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 2456],
            ['id' => 3, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 2459],
            ['id' => 2, 'node' => 1, 'ip' => '10.0.0.2', 'port' => 2457],
            ['id' => 4, 'node' => 2, 'ip' => '10.0.0.1', 'port' => 2457],
            ['id' => 6, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 2461],
            ['id' => 5, 'node' => 1, 'ip' => '10.0.0.1', 'port' => 2460],
        ];

        $this->assertSame([[3, 5]], Ports::runs($free, 2));
        $this->assertSame([[3, 5, 6]], Ports::runs($free, 3));
        $this->assertSame([], Ports::runs($free, 4));
    }

    public function test_runs_do_not_share_allocations(): void
    {
        $free = array_map(static fn (int $port): array => ['id' => $port, 'node' => 1, 'ip' => '10.0.0.1', 'port' => $port], [7777, 7778, 7779, 7780, 7781]);

        $this->assertSame([[7777, 7778], [7779, 7780]], Ports::runs($free, 2));
        $this->assertCount(5, Ports::runs($free, 1));
    }

    public function test_ranges_parse_ports_and_ranges(): void
    {
        $this->assertSame([[25565, 25600], [27015, 27015]], Ports::ranges(' 25565 - 25600 ,27015, '));
        $this->assertSame([], Ports::ranges(''));
        $this->assertSame('25565-25600, 27015', Ports::format([[25565, 25600], [27015, 27015]]));
    }

    public function test_ranges_reject_anything_but_ports(): void
    {
        foreach (['25600-25565', '0', '65536', '25565-70000', 'minecraft', '25565:25600', '-25565'] as $value) {
            $this->assertNull(Ports::ranges($value), $value);
        }
    }

    public function test_run_from_needs_the_ports_in_a_row_on_the_primary_ip_within_the_ranges(): void
    {
        $held = [
            ['id' => 1, 'ip' => '10.0.0.1', 'port' => 2456],
            ['id' => 2, 'ip' => '10.0.0.1', 'port' => 2457],
            ['id' => 3, 'ip' => '10.0.0.2', 'port' => 2458],
        ];

        $this->assertSame([2456, 2457], Ports::runFrom($held, 1, 2, []));
        $this->assertSame([2456, 2457], Ports::runFrom($held, 1, 2, [[2400, 2499]]));
        $this->assertSame([2457], Ports::runFrom($held, 2, 1, [[2457, 2457]]));
        $this->assertNull(Ports::runFrom($held, 1, 3, []), 'the third port is on another IP');
        $this->assertNull(Ports::runFrom($held, 2, 2, []), 'runs start at the primary port');
        $this->assertNull(Ports::runFrom($held, 1, 2, [[2456, 2456]]), 'the second port is out of range');
        $this->assertNull(Ports::runFrom($held, 1, 1, [[25565, 25600]]));
        $this->assertNull(Ports::runFrom($held, 9, 1, []), 'the primary is not held');
    }

    public function test_fill_writes_extra_ports_into_existing_variables_only(): void
    {
        $environment = ['SERVER_PORT' => '1', 'QUERY_PORT' => '27015', 'VERSION' => 'latest'];

        $this->assertSame(
            ['SERVER_PORT' => '1', 'QUERY_PORT' => '2457', 'VERSION' => 'latest'],
            Ports::fill($environment, [2456, 2457, 2458], ['QUERY_PORT', 'RCON_PORT']),
        );
    }
}
