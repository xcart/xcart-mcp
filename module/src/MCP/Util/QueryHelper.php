<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Util;

class QueryHelper
{
    /**
     * Escape LIKE wildcard characters (% and _) in a user-provided search term.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    /**
     * Wrap an escaped value with % wildcards for a contains-match LIKE query.
     */
    public static function likeContains(string $value): string
    {
        return '%' . self::escapeLike($value) . '%';
    }
}
