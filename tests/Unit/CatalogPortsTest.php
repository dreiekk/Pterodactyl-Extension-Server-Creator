<?php

declare(strict_types=1);

namespace ServerCreator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ServerCreator\Support\CatalogPorts;

final class CatalogPortsTest extends TestCase
{
    public function test_extracts_ordered_ports_and_deduplicates_protocols(): void
    {
        $html = '<div id="ports"><ul><li id="port-0"><span>Game</span><span>27015</span></li><li id="port-1"><span>Query</span><span>27016</span></li><li id="port-2"><span>UDP</span><span>27015</span></li></ul></div>';
        self::assertSame([27015, 27016], CatalogPorts::fromPage($html));
    }

    public function test_refuses_missing_ambiguous_or_invalid_ports(): void
    {
        foreach (['', '<div id="ports"></div>', '<div id="ports"><li id="port-0"><span>Game</span><span>27015-27020</span></li></div>', '<div id="ports"><li id="port-0"><span>Game</span><span>0</span></li></div>', '<div id="ports"><li id="port-0"><span>Game</span><span>65536</span></li></div>', '<div id="ports"><li id="port-0"><span>Game</span></li></div>'] as $html) {
            self::assertNull(CatalogPorts::fromPage($html));
        }
    }

    public function test_refuses_partial_or_oversized_lists(): void
    {
        $row = static fn (string $value): string => '<li id="port-0"><span>Port</span><span>'.$value.'</span></li>';
        self::assertNull(CatalogPorts::fromPage('<div id="ports">'.$row('27015').$row('custom').'</div>'));
        self::assertNull(CatalogPorts::fromPage('<div id="ports">'.implode('', array_map($row, array_map(strval(...), range(1, 11)))).'</div>'));
    }
}
