<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

use RuntimeException;

/**
 * The whole configuration — the remotes, and this end.
 *
 * The project's Envoy.blade.php builds one of these and imports the tasks; the
 * tasks read nothing but this object. Anything derived — which remote the
 * command line meant, whether a project is SQLite, which --ignore-table flags a
 * dump needs, what rsync is handed — is worked out here, so no task has to work
 * anything out for itself.
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

    /** What a lone remote is called when the project never named it. */
    public const DEFAULT_REMOTE = 'remote';

    /**
     * Names no remote may take, because `--<name>` already means something else
     * to Envoy or to these tasks and could never select it.
     */
    private const RESERVED_NAMES = [
        'local', 'force', 'dry', 'continue', 'pretend', 'path', 'conf', 'help',
        'quiet', 'version', 'verbose', 'ansi', 'no-ansi', 'no-interaction',
    ];

    /**
     * Every remote there is, under the name that selects it. One is the usual
     * case; two — prod and dev — is why this is a list at all.
     *
     * @var array<string, Environment>
     */
    public readonly array $remotes;

    /** The name of the remote this run is aimed at. */
    public readonly string $remoteName;

    /**
     * The remote this run is aimed at — the only one there is, or the one the
     * flag on the command line chose. Every task is rendered against it.
     */
    public readonly Environment $remote;

    /**
     * @param  Environment|array<string, Environment>  $remote  one remote, or several
     *                                                          under the names that select them
     * @param  list<string>  $ignoreTables
     */
    public function __construct(
        Environment|array $remote,
        public readonly Environment $local,
        public readonly array $ignoreTables = self::IGNORE_TABLES,
    ) {
        $this->remotes = $remote instanceof Environment
            ? [self::DEFAULT_REMOTE => $remote]
            : $remote;

        /*
        | The file is checked before the command line is, so a broken config is
        | reported as a broken config whichever remote you would have picked —
        | a missing ssh: on dev is not news about the flag you forgot.
        */

        $this->validateNames();
        $this->validate();

        $this->remoteName = $this->chooseRemote();
        $this->remote = $this->remotes[$this->remoteName];
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
     * Whether the chosen remote's database may be written to by hand. A
     * read-only one is never imported into and never rebuilt — db-push refuses,
     * and the deploy migration is the only thing left that touches it.
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

    /**
     * The remote's name as it reads in a sentence. A project that never named
     * its one remote calls it "the remote" there, the way it always has; one
     * that named it says the name, which is the point of having named it.
     */
    public function remoteLabel(): string
    {
        return $this->remoteName === self::DEFAULT_REMOTE ? 'the remote' : $this->remoteName;
    }

    /**
     * A confirmation that names the remote it is about. With one remote that is
     * a formality; with two it is the whole reason for asking.
     */
    public function confirm(string $what): string
    {
        return $what.' on '.$this->remoteLabel().'?';
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

    /**
     * Which remote this run means. One remote is not a choice and takes no
     * flag; more than one is, and the flag is the only thing that makes it —
     * there is no default, because a default is how you deploy to prod meaning
     * dev.
     */
    private function chooseRemote(): string
    {
        $names = array_keys($this->remotes);

        if (count($names) === 1) {
            return $names[0];
        }

        $chosen = array_values(array_intersect($names, CommandLine::flags()));

        if (count($chosen) === 1) {
            return $chosen[0];
        }

        if ($chosen !== []) {
            throw new RuntimeException(
                'Envoy: '.$this->flagList($chosen).' were given together, and a command '
                .'runs against one remote. Pass the one you mean.'
            );
        }

        /*
        | Nothing is about to run — `envoy tasks` is only reading the list — so
        | there is nothing to aim, and the first remote renders it.
        */

        if (! CommandLine::runsATask()) {
            return $names[0];
        }

        throw new RuntimeException(
            'Envoy: this project has more than one remote, so every command has to say '
            .'which one it means: '.$this->flagList($names).'. For example, envoy run '
            .'deploy --'.$names[0].'.'
        );
    }

    /**
     * @param  list<string>  $names
     */
    private function flagList(array $names): string
    {
        return implode(', ', array_map(
            static fn (string $name): string => '--'.$name,
            $names,
        ));
    }

    private function validateNames(): void
    {
        if ($this->remotes === []) {
            throw new RuntimeException(
                'Envoy: there are no remotes. Give remote: one Environment, or several '
                ."under the names that select them: ['prod' => ..., 'dev' => ...]."
            );
        }

        foreach ($this->remotes as $name => $environment) {
            if (! $environment instanceof Environment) {
                throw new RuntimeException(
                    'Envoy: remote: takes one Environment, or a list of them keyed by '
                    ."name, e.g. ['prod' => new Environment(...), 'dev' => new Environment(...)]."
                );
            }

            if (! is_string($name)) {
                throw new RuntimeException(
                    'Envoy: every remote in the list needs a name, because the name is '
                    .'the flag that selects it: '
                    ."['prod' => new Environment(...), 'dev' => new Environment(...)]."
                );
            }

            if (! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
                throw new RuntimeException(
                    'Envoy: a remote is named by the flag that selects it, so the name '
                    .'has to look like one — a lowercase letter, then letters, digits '
                    .'and dashes. Got: '.$name
                );
            }

            if (in_array($name, self::RESERVED_NAMES, true)) {
                throw new RuntimeException(
                    'Envoy: --'.$name.' already means something else, so a remote called '
                    .$name.' could never be selected. Pick another name.'
                );
            }
        }
    }

    private function validate(): void
    {
        foreach ($this->remotes as $name => $remote) {
            $this->validateRemote($name, $remote);
        }

        /*
        | The mirror lists say what moves between a remote and here, so they
        | only mean anything on a remote. On local they would name directories
        | nothing ever reads, which is worse than a refusal.
        */

        if ($this->local->storagePull !== [] || $this->local->storagePush !== []) {
            throw new RuntimeException(
                'Envoy: storagePull: and storagePush: belong on a remote, not on local — '
                .'they say which directories move between that remote and here. Move them '
                .'to the Environment for the remote they mirror with.'
            );
        }
    }

    private function validateRemote(string $name, Environment $remote): void
    {
        if (($remote->db === null) !== ($this->local->db === null)) {
            throw new RuntimeException(
                'Envoy: '.$name.' and local disagree about whether there is a database. '
                .'Give db: to both environments, or to neither — there is nothing to '
                .'transfer between a database and no database.'
            );
        }

        if ($remote->db !== null && $this->local->db !== null) {
            $this->validateDatabases($name, $remote, $this->local->db);
        }

        if ($remote->ssh === '') {
            throw new RuntimeException(
                'Envoy: the remote '.$name.' has no ssh address. Set ssh: to user@host, '
                .'or to the bare host when ~/.ssh/config knows the user.'
            );
        }

        foreach ([...$remote->storagePull, ...$remote->storagePush] as $dir) {
            if (! $dir instanceof SyncDir) {
                throw new RuntimeException(
                    'Envoy: the storagePull: and storagePush: lists on '.$name.' take '
                    ."SyncDir objects, e.g. new SyncDir('storage/app')."
                );
            }
        }
    }

    private function validateDatabases(string $name, Environment $remote, Database $local): void
    {
        /** @var Database $db */
        $db = $remote->db;

        if ($db->isSqlite() !== $local->isSqlite()) {
            throw new RuntimeException(
                'Envoy: one database is a .sqlite path and the other is not. Every end '
                .'must be the same kind — '.$name.': '.$db->database
                .', local: '.$local->database
            );
        }

        if ($db->isSqlite()) {
            return;
        }

        if (! $db->username || ! $local->username) {
            throw new RuntimeException(
                'Envoy: database credentials come from .env, and one of '.$name.' and '
                .'local has no username. A remote reads its own pair — e.g. '
                .strtoupper(str_replace('-', '_', $name)).'_DB_USERNAME / '
                .strtoupper(str_replace('-', '_', $name)).'_DB_PASSWORD — and local '
                .'reuses DB_USERNAME / DB_PASSWORD.'
            );
        }

        if ($remote->dumps === '' || $this->local->dumps === '') {
            throw new RuntimeException(
                'Envoy: a MySQL project dumps to a directory, and one of '.$name.' and '
                .'local has no dumps: path. Set it on every remote; the local end '
                .'defaults to storage/envoy.'
            );
        }
    }
}
