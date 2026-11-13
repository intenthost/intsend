<?php

declare(strict_types=1);

namespace IntentMail;

/**
 * Env — zero-dependency .env parser.
 *
 * Reads KEY=VALUE pairs from a file and populates $_ENV / getenv().
 * Supports:
 *   - # comments (full-line and inline)
 *   - Quoted values: "value" or 'value'
 *   - Multiline values are NOT supported (keep it simple)
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            // .env is optional in production (use real env vars)
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Must have an = sign
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip inline comments (outside quotes)
            $value = self::stripInlineComment($value);

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            // Don't overwrite real environment variables
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false) {
            return $v;
        }
        return $_ENV[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v !== '' ? (int) $v : $default;
    }

    // ── Private helpers ──────────────────────

    private static function stripInlineComment(string $value): string
    {
        // If the value is not quoted, strip everything after a bare # space
        if (
            !str_starts_with($value, '"') &&
            !str_starts_with($value, "'")
        ) {
            $pos = strpos($value, ' #');
            if ($pos !== false) {
                $value = substr($value, 0, $pos);
            }
        }
        return trim($value);
    }
}

