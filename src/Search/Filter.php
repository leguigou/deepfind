<?php

declare(strict_types=1);

namespace DeepFind\Search;

/** Construction sûre des expressions de filtre Meilisearch (échappement des valeurs). */
final class Filter
{
    public static function quote(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    public static function eq(string $field, string $value): string
    {
        return $field . ' = ' . self::quote($value);
    }

    /** @param list<string> $values */
    public static function in(string $field, array $values): string
    {
        return $field . ' IN [' . implode(', ', array_map([self::class, 'quote'], $values)) . ']';
    }

    /** @param list<string> $values */
    public static function notIn(string $field, array $values): string
    {
        return $field . ' NOT IN [' . implode(', ', array_map([self::class, 'quote'], $values)) . ']';
    }
}
