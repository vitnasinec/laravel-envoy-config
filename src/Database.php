<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

/**
 * One database, at one end.
 *
 * `database` says which kind of project this is: a plain name is MySQL, a path
 * ending in .sqlite is a file to be transferred rather than a schema to dump,
 * and then host, port, username and password go unused.
 */
final class Database
{
    public function __construct(
        public readonly string $database,
        public readonly ?string $host = '127.0.0.1',
        public readonly ?int $port = 3306,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
    ) {
    }

    public function isSqlite(): bool
    {
        return str_ends_with($this->database, '.sqlite');
    }

    /**
     * Where and as whom to connect. The password never appears here — it goes
     * through MYSQL_PWD instead, so it stays out of `ps` on a shared host.
     */
    public function connectFlags(): string
    {
        return "--host={$this->host} --port={$this->port} --user={$this->username}";
    }

    /**
     * @param  list<string>  $tables
     */
    public function ignoreFlags(array $tables): string
    {
        return implode(' ', array_map(
            fn (string $table): string => "--ignore-table={$this->database}.{$table}",
            $tables,
        ));
    }
}
