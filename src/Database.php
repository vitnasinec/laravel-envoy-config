<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

/**
 * One database, at one end.
 *
 * `database` says which kind of project this is: a plain name is MySQL, a path
 * ending in .sqlite is a file to be transferred rather than a schema to dump,
 * and then host, port, username and password go unused.
 *
 * Where dumps are written lives here too, rather than on the Environment,
 * because a dump directory is a fact about the database: an environment
 * without one never writes a dump, and a SQLite one transfers the file itself.
 */
final class Database
{
    /**
     * Where dumps are written unless the project says otherwise. It is
     * relative, so the one default means something at every end — that
     * directory under local's project root, and under each remote's — and a
     * project that keeps its dumps where the project is never sets it at all.
     */
    public const DUMPS = './storage/envoy';

    /**
     * @param  string  $dumps  where dumps are written at this end. A relative
     *                         path hangs off the project root at that end,
     *                         which is what the default does; one starting with
     *                         / or ~ is taken exactly as written, for a host
     *                         that keeps its dumps off the project directory.
     * @param  bool  $readOnly  a database that is never written to by hand.
     *                          Nothing that imports, drops or rebuilds it is
     *                          defined at all — only the deploy migration still
     *                          runs against it. Off unless the project says so.
     */
    public function __construct(
        public readonly string $database,
        public readonly ?string $host = '127.0.0.1',
        public readonly ?int $port = 3306,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly string $dumps = self::DUMPS,
        public readonly bool $readOnly = false,
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
