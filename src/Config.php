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

    /** The dump before that one, and the rest of the history a dump dir keeps. */
    public const DUMP_PREVIOUS = 'dump--previous.sql';

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
     * What git said this working copy is on, as it said it — a name, `HEAD`
     * for a detached one, or nothing at all where git could not answer. Asking
     * is a subprocess, and every task that needs the branch needs the same
     * answer, so it is asked once.
     */
    private ?string $branch = null;

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
     * The branch this working copy is on.
     *
     * It is read from git rather than declared, because it is not a decision a
     * project makes once — it is wherever you happen to be standing, and it
     * changes several times a day. A branch written into the config could only
     * ever agree with HEAD by luck, and the tasks that use it would then do the
     * thing it said instead of the thing you meant: `git push` pushes the
     * branch you are on, so a remote checking out a declared `main` would be
     * deploying something you never pushed.
     *
     * Envoy renders this file here, in this working copy, so here is the one
     * end git can be asked about directly. What the far end is on is the far
     * end's business, and db-import asks it over ssh at the moment it matters.
     *
     * The answer is passed on as git gave it, `HEAD` and all: every task body
     * is rendered on every run, including the ones this run is not about, so a
     * working copy with no branch to name cannot be an error here. It is one
     * in requireBranch(), for the tasks that would have used it.
     */
    public function localBranch(): string
    {
        return $this->branch ??= trim((string) shell_exec(
            'git -C '.escapeshellarg($this->local->path).' rev-parse --abbrev-ref HEAD 2>/dev/null'
        ));
    }

    /**
     * Refuse the tasks that need a branch, when there is no branch to give
     * them.
     *
     * Which those are is the task file's own business, so it hands them over.
     * The rest are none of this method's concern: a detached head is no reason
     * to refuse storage-pull or db-push, neither of which asks what you are
     * standing on, and `envoy tasks` names no task at all.
     *
     * @param  list<string>  $tasks  the names that would use the branch
     */
    public function requireBranch(array $tasks): void
    {
        $branch = $this->localBranch();

        if (($branch !== '' && $branch !== 'HEAD') || ! in_array(CommandLine::task(), $tasks, true)) {
            return;
        }

        if ($branch === 'HEAD') {
            throw new RuntimeException(
                'Envoy: this working copy is on a detached HEAD, and '.CommandLine::task()
                .' is one of the commands that needs the branch you are on. Check one out '
                .'first.'
            );
        }

        throw new RuntimeException(
            'Envoy: git could not say which branch '.$this->local->path.' is on. The '
            ."path: on local has to be this project's working copy — every code task "
            .'here is a git command run inside it.'
        );
    }

    /**
     * Refuse, before anything runs, to move a protected remote onto the wrong
     * branch.
     *
     * A remote with deployFrom: set deploys from that branch and no other. The
     * check is here rather than in the task that would do the checkout because
     * the code stories take the site down first: a refusal at git-pull would
     * arrive with the site already in maintenance mode, and this one arrives
     * before the story starts.
     *
     * Which tasks those are is the task file's own business, so it hands them
     * over — everything that ends in a checkout on the far end. Nothing else is
     * touched: a protected prod still takes storage-pull, db-pull and status
     * from wherever you are standing, because none of them moves it.
     *
     * @param  list<string>  $tasks  the names that move a remote's checkout
     */
    public function guardBranch(array $tasks): void
    {
        $wanted = $this->remote->deployFrom;

        if ($wanted === null || ! in_array(CommandLine::task(), $tasks, true)) {
            return;
        }

        $branch = $this->localBranch();

        if ($branch === $wanted) {
            return;
        }

        throw new RuntimeException(
            'Envoy: '.$this->remoteLabel().' deploys from '.$wanted.', and this working '
            .'copy is on '.$branch.'. Check '.$wanted.' out here, or drop deployFrom: '
            .'from that remote to deploy it from wherever you are.'
        );
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

    /**
     * What every rsync in the file is handed. `--itemize-changes` says of every
     * file both its path and what became of it — `>f+++++++` sent as new,
     * `>f.s.....` sent over one that was already there, `cd+++++++` a directory
     * made, `*deleting` one removed at the destination by a `delete: true`
     * entry — because a transfer that prints nothing is one you cannot tell
     * from a transfer that did nothing, and a name on its own does not say
     * whether the far end gained a file or lost one.
     *
     * It is this rather than `--info=name` because macOS ships openrsync now,
     * which has no `--info` at all and would refuse the whole command.
     *
     * A dry run is then the same output and no transfer, so --dry adds only
     * `--dry-run`: it is the reading you would get, taken without moving
     * anything.
     */
    public function rsyncOpts(bool $dry = false): string
    {
        return trim(implode(' ', array_filter([
            '-az --human-readable --itemize-changes',
            $this->remote->rsyncShell(),
            $dry ? '--dry-run' : '',
        ])));
    }

    /**
     * Make room in a dump directory for the dump about to be written into it:
     * the directory is created if it is not there, the dump sitting in it now
     * becomes the backup, and every other dump in it goes. Two is the whole
     * history a dump directory keeps — the dump just taken, and the one before
     * it — so it stays the same size for ever instead of growing until someone
     * remembers to empty it.
     *
     * The directory gets a .gitignore of its own, ignoring everything in
     * itself, because the default dump directory sits inside the project and a
     * dump is never committed. It is written at both ends, and rewritten every
     * time, so the directory arrives ignored rather than waiting for someone to
     * add a line to the project's .gitignore — on a remote that matters more
     * than here, since an untracked dump there is what makes a deploy's
     * checkout fail.
     *
     * This is shell rather than a task of its own because every one of the
     * four things that writes a dump has to do it first — dumping either end,
     * downloading one, uploading one — and two of those happen over ssh, at
     * the far end, where a local task could not reach.
     */
    public function rotateDumps(string $dir): string
    {
        $latest = $dir.'/'.self::DUMP_LATEST;
        $previous = $dir.'/'.self::DUMP_PREVIOUS;

        return implode("\n", [
            'mkdir -p '.$dir,
            "printf '%s\\n' '*' '!.gitignore' > ".$dir.'/.gitignore',
            'if [ -f '.$latest.' ]; then mv -f '.$latest.' '.$previous.'; fi',
            'find '.$dir." -maxdepth 1 -type f -name 'dump--*.sql'"
                .' -not -name '.self::DUMP_LATEST.' -not -name '.self::DUMP_PREVIOUS.' -delete',
        ]);
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

        if ($this->local->deployFrom !== null) {
            throw new RuntimeException(
                'Envoy: deployFrom: belongs on a remote, not on local — it says which '
                .'branch that remote may be moved onto. This end is the one doing the '
                .'moving, and it is on whatever branch you have checked out.'
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

        if ($remote->deployFrom === '') {
            throw new RuntimeException(
                'Envoy: deployFrom: on '.$name.' is an empty string, which is not a '
                .'branch. Name the branch it deploys from, or leave deployFrom: off to '
                .'deploy it from any of them.'
            );
        }

        if ($remote->ssh === '') {
            throw new RuntimeException(
                'Envoy: the remote '.$name.' has no ssh address. Set ssh: to user@host, '
                .'or to the bare host when ~/.ssh/config knows the user.'
            );
        }

        foreach ([...$remote->storagePull, ...$remote->storagePush] as $dir) {
            if (! $dir instanceof Storage) {
                throw new RuntimeException(
                    'Envoy: the storagePull: and storagePush: lists on '.$name.' take '
                    ."Storage objects, e.g. new Storage('storage/app')."
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

        if ($db->dumps === '' || $local->dumps === '') {
            throw new RuntimeException(
                'Envoy: a MySQL project dumps to a directory, and one of '.$name.' and '
                .'local has an empty dumps: path. Leave dumps: off the Database to write '
                .'them to '.Database::DUMPS.' under that end\'s project root, or give a '
                .'path of your own.'
            );
        }
    }
}
