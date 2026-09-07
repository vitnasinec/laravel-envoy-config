<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

use Dotenv\Dotenv;

/**
 * The project's .env — read for the database usernames and passwords, and for
 * nothing else. Everything else is configured in Envoy.blade.php, which is
 * committed; these two pairs are not.
 */
final class Env
{
    private static ?string $loaded = null;

    /**
     * @param  string|null  $root  where the .env sits; the project root by default
     */
    public static function get(string $key, ?string $root = null): ?string
    {
        self::load($root ?? (string) getcwd());

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null || $value === '' ? null : (string) $value;
    }

    private static function load(string $root): void
    {
        if (self::$loaded === $root) {
            return;
        }

        Dotenv::createImmutable($root)->safeLoad();

        self::$loaded = $root;
    }
}
