<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

use RuntimeException;

/**
 * The whole configuration — two environments and two mirror lists.
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
     * @param  list<string>  $ignoreTables
     */
    public function __construct(
        public readonly Environment $remote,
        public readonly Environment $local,
        public readonly array $pull = [],
        public readonly array $push = [],
        public readonly array $ignoreTables = self::IGNORE_TABLES,
    ) {
        $this->validate();
    }

    /**
     * Whether there is a database at all. A project can have none — a static
     * site, a front end, anything that only ever ships code — and then not one
     * database task is defined, rather than each of them failing when run.
     */
    public function hasDatabase(): bool
    {
        return $this->local->db !== null;
    }

    /**
     * Whether the remote database may be written to by hand. A read-only one is
     * never imported into and never rebuilt — db-push refuses, and the deploy
     * migration is the only thing left that touches it.
     */
    public function canWriteRemote(): bool
    {
        return $this->remote->db !== null && ! $this->remote->db->readOnly;
    }

    /** The same question at this end: whether db-pull may import into local. */
    public function canWriteLocal(): bool
    {
        return $this->local->db !== null && ! $this->local->db->readOnly;
    }

    /**
     * SQLite is not a switch to set, it is read off the two database names: one
     * ending in .sqlite is a path to a file rather than the name of a schema.
     */
    public function isSqlite(): bool
    {
        return $this->local->db?->isSqlite() ?? false;
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
        if (($this->remote->db === null) !== ($this->local->db === null)) {
            throw new RuntimeException(
                'Envoy: one end has a database and the other does not. Give db: to both '
                .'environments, or to neither — there is nothing to transfer between a '
                .'database and no database.'
            );
        }

        if ($this->remote->db !== null && $this->local->db !== null) {
            $this->validateDatabases($this->remote->db, $this->local->db);
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

    private function validateDatabases(Database $remote, Database $local): void
    {
        if ($remote->isSqlite() !== $local->isSqlite()) {
            throw new RuntimeException(
                'Envoy: one database is a .sqlite path and the other is not. Both ends '
                .'must be the same kind — remote: '.$remote->database
                .', local: '.$local->database
            );
        }

        if ($remote->isSqlite()) {
            return;
        }

        if (! $remote->username || ! $local->username) {
            throw new RuntimeException(
                'Envoy: database credentials come from .env. Set PROD_DB_USERNAME / '
                .'PROD_DB_PASSWORD for the remote, DB_USERNAME / DB_PASSWORD for local.'
            );
        }

        if ($this->remote->dumps === '' || $this->local->dumps === '') {
            throw new RuntimeException(
                'Envoy: a MySQL project dumps to a directory, and one of the two ends '
                .'has no dumps: path. Set it on the remote; the local end defaults to '
                .'storage/envoy.'
            );
        }
    }
}
