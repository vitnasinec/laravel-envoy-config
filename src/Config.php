<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

use RuntimeException;

/**
 * The whole configuration — two environments, two mirror lists, one flag.
 *
 * The project's Envoy.blade.php builds one of these and imports the tasks; the
 * tasks read nothing but this object. Anything derived — whether a project is
 * SQLite, which --ignore-table flags a dump needs, what rsync is handed — is
 * worked out here, so no task has to work anything out for itself.
 */
final class Config
{
    /** Tables whose data is never carried between environments. */
    public const IGNORE_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
    ];

    /**
     * Data only, plus `migrate:fresh` before every import, keeps the schema
     * owned by the migrations and never by a dump.
     */
    public const DUMP_FLAGS = '--single-transaction --quick --skip-lock-tables --no-tablespaces '
        .'--default-character-set=utf8mb4 --skip-triggers --no-create-info';

    public const DUMP_LATEST = 'dump--latest.sql';

    /**
     * @param  list<SyncDir>  $pull   directories that move remote -> local
     * @param  list<SyncDir>  $push   directories that move local -> remote
     * @param  bool  $build           whether deploy and code-push build assets on the server
     * @param  list<string>  $ignoreTables
     */
    public function __construct(
        public readonly Environment $remote,
        public readonly Environment $local,
        public readonly array $pull = [],
        public readonly array $push = [],
        public readonly bool $build = true,
        public readonly array $ignoreTables = self::IGNORE_TABLES,
    ) {
        $this->validate();
    }

    /**
     * SQLite is not a switch to set, it is read off the two database names: one
     * ending in .sqlite is a path to a file rather than the name of a schema.
     */
    public function isSqlite(): bool
    {
        return $this->local->db->isSqlite();
    }

    /** @return array<string, string> */
    public function servers(): array
    {
        return [
            'local' => '127.0.0.1',
            'remote' => trim($this->remote->ssh.' '.$this->remote->sshFlag()),
        ];
    }

    public function rsyncOpts(bool $dry = false): string
    {
        return trim(implode(' ', array_filter([
            '-az --human-readable',
            $this->remote->rsyncShell(),
            $dry ? '--dry-run --itemize-changes' : '',
        ])));
    }

    /** A second copy of every dump, kept under the time it was taken. */
    public function dumpStamp(): string
    {
        return 'dump--'.date('Ymd-His').'.sql';
    }

    public function ignoreFlags(Database $db): string
    {
        return $db->ignoreFlags($this->ignoreTables);
    }

    private function validate(): void
    {
        if ($this->remote->db->isSqlite() !== $this->local->db->isSqlite()) {
            throw new RuntimeException(
                'Envoy: one database is a .sqlite path and the other is not. Both ends '
                .'must be the same kind — remote: '.$this->remote->db->database
                .', local: '.$this->local->db->database
            );
        }

        if (! $this->isSqlite() && (! $this->remote->db->username || ! $this->local->db->username)) {
            throw new RuntimeException(
                'Envoy: database credentials come from .env. Set PROD_DB_USERNAME / '
                .'PROD_DB_PASSWORD for the remote, DB_USERNAME / DB_PASSWORD for local.'
            );
        }

        if ($this->remote->ssh === '') {
            throw new RuntimeException(
                'Envoy: the remote has no ssh address. Set ssh: to user@host, or to the '
                .'bare host when ~/.ssh/config knows the user.'
            );
        }

        foreach ([...$this->pull, ...$this->push] as $dir) {
            if (! $dir instanceof SyncDir) {
                throw new RuntimeException(
                    'Envoy: the pull and push lists take SyncDir objects, e.g. '
                    ."new SyncDir('storage/app')."
                );
            }
        }
    }
}
