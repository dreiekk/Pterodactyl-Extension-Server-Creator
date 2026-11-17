<?php

declare(strict_types=1);

namespace ServerCreator\Support;

use DOMDocument;
use DOMXPath;

final class CatalogPorts
{
    /**
     * The catalog renders its port metadata as rows in the ports section. Only use
     * a complete list of individual ports; ranges and descriptive values need
     * the user to choose an appropriate layout.
     *
     * @return list<int>|null Null means the page has no unambiguous port list.
     */
    public static function fromPage(string $html): ?array
    {
        if ($html === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $rows = $xpath->query('//*[@id="ports"]//li[starts-with(@id, "port-")]');
        if ($rows === false || $rows->length === 0) {
            return null;
        }

        $ports = [];
        foreach ($rows as $row) {
            $values = $xpath->query('./span', $row);
            if ($values === false || $values->length !== 2) {
                return null;
            }
            $value = mb_trim($values->item(1)->textContent);
            if (! preg_match('/^[0-9]{1,5}$/D', $value) || (int) $value < 1 || (int) $value > 65535) {
                return null;
            }
            $ports[] = (int) $value;
        }

        $ports = array_values(array_unique($ports));

        return count($ports) <= 10 ? $ports : null;
    }
}
