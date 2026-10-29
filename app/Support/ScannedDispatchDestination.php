<?php

namespace App\Support;

/** Navigation only: a reference never grants access to the dispatch. */
final class ScannedDispatchDestination
{
    public static function id(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) return null;
        $value = (string) $value;
        if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $value)
            || (float) $value > 2147483647) return null;
        return (int) $value;
    }

    public static function destination(mixed $value): string
    {
        $id = self::id($value);
        return $id === null ? '/dashboard' : '/dispatches/' . $id;
    }

    public static function carry(string $path, mixed $value): string
    {
        $id = self::id($value);
        return $id === null ? $path : $path . (str_contains($path, '?') ? '&' : '?') . 'next_dispatch=' . $id;
    }

    public static function nativeReference(): ?int
    {
        $filename = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        // Explicit logout never forwards navigation intent.
        if ($filename === 'logout.php') return null;
        return self::id($filename === 'dispatch-detail.php' ? ($_GET['id'] ?? null) : ($_GET['next_dispatch'] ?? null));
    }
}
