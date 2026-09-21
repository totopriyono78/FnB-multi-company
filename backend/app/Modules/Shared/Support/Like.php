<?php

namespace App\Modules\Shared\Support;

/** Pola pencarian LIKE/ILIKE yang aman dari karakter khusus (%, _, \\). */
final class Like
{
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape($term).'%';
    }

    public static function escape(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
