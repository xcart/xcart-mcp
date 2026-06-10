<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

class CategoryPathBuilder
{
    private const MAX_DEPTH = 20;

    public static function build(object $category): string
    {
        $parts = [];
        $current = $category;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $name = $current->getName();
            if ($name) {
                $parts[] = $name;
            }
            $current = $current->getParent();
            $depth++;
        }

        return implode(' > ', array_reverse($parts));
    }
}
