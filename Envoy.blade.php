{{--
|--------------------------------------------------------------------------
| Shared Laravel Envoy template
|--------------------------------------------------------------------------
|
| Drop this file in the project root as Envoy.blade.php and edit the Config
| block below — that block is the whole configuration. Nothing else in here
| needs touching, and nothing outside it needs setting up.
|
| The one exception is the database usernames and passwords: those are read
| from the project's .env, because this file is committed and they are not.
|
| Environments   local and one remote — that is the whole map
| Direction      push = local -> remote,  pull = remote -> local
|
| There is no server to choose, so no command takes a target flag. Everything
| that writes to the remote asks first; --noconfirm answers for scripts.
|
| Common commands
|   envoy run code-push            fast deploy  (alias: push)
|   envoy run deploy               full deploy — composer install and migrate too
|   envoy run db-pull              remote database down to local
|   envoy run storage-pull         every declared directory, remote -> local
|   envoy run storage-sync         every declared directory, both ways
|
--}}

@include('vendor/autoload.php')

@setup
    /*
    |--------------------------------------------------------------------------
    | Credentials — the only thing read from .env
    |--------------------------------------------------------------------------
    | Two keys for the remote database. The local database reuses Laravel's own
    | DB_USERNAME / DB_PASSWORD, so there is nothing to add for it.
    */

    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

    $env = function (string $key) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null || $value === '' ? null : (string) $value;
    };

    /*
    |--------------------------------------------------------------------------
    | What an environment is
    |--------------------------------------------------------------------------
    | Objects, not nested arrays. Every value below ends up spliced into a shell
    | command, and a mistyped array key arrives there as an empty string — an
    | rsync with no source, a mysql with no database. The same typo against a
    | typed property is a fatal error, before any of it runs.
    |
    | Data only, no methods: before anything else, Envoy scans the raw file for
    | every name that looks like a variable and prepends an "or null" assignment
    | for each one. A method body referring to the object itself is caught by
    | that scan and compiled into a re-assignment of the one variable PHP will
    | not let you re-assign — a parse error, before a single task runs. The scan
    | reads comments too, so this paragraph cannot spell the name either.
    |
    | Anything that needs working out is worked out below, in the mechanics, as
    | a closure taking the object.
    */

    final class EnvoyDatabase
    {
        public function __construct(
            public readonly ?string $host,
            public readonly ?int $port,
            public readonly ?string $database,
            public readonly ?string $username,
            public readonly ?string $password,
        ) {
        }
    }

    final class EnvoyEnvironment
    {
        public function __construct(
            public readonly string $path,
            public readonly string $dumps,
            public readonly string $branch,
            public readonly EnvoyDatabase $db,
            public readonly string $ssh = '',
            public readonly int $port = 22,
            public readonly string $php = 'php',
            public readonly string $composer = 'composer',
            public readonly string $npm = 'npm',
        ) {
        }
    }

    /* One directory, and whether the far end mirrors deletions. */

    final class EnvoySyncDir
    {
        public function __construct(
            public readonly string $path,
            public readonly bool $delete = false,
        ) {
        }
    }

    /*
    |==========================================================================
    | Config — everything you edit is in this block
    |==========================================================================
    |
    | The remote
    |
    | ssh       user@host, or the bare host when ~/.ssh/config knows the user
    | path      project root on the server
    | dumps     where dumps are written there
    | branch    the branch the server runs — db-import borrows it to build the
    |           right schema before importing
    | php       absolute paths for hosts that don't have them on PATH, e.g.
    | composer  php: '/opt/alt/php83/usr/bin/php'
    | npm       composer: 'php ~/code/bin/composer'
    | db        the database there — and which kind of project this is. A plain
    |           name is MySQL. A path ending in .sqlite makes it a SQLite
    |           project: db-pull and db-push then transfer that file instead of
    |           dumping, and host, port, username and password go unused.
    |
    |               database: '~/code/stage1/database/database.sqlite',
    */

    $remote = new EnvoyEnvironment(
        ssh: 'exampleuser@example.pef.czu.cz',
        port: 22,
        path: '~/code/stage1',
        dumps: '~/code/temp',
        branch: 'main',
        php: 'php',
        composer: 'composer',
        npm: 'npm',
        db: new EnvoyDatabase(
            host: '127.0.0.1',
            port: 3306,
            database: 'example_db',
            username: $env('PROD_DB_USERNAME'),
            password: $env('PROD_DB_PASSWORD'),
        ),
    );

    /*
    | Local. Nothing is ever ssh'd to here, so the ssh fields keep their
    | defaults, and the branch is whichever one you are on right now. Its
    | database has to be the same kind as the remote's — two names, or two
    | .sqlite paths — because there is no transfer between the two kinds.
    */

    $local = new EnvoyEnvironment(
        path: __DIR__,
        dumps: __DIR__ . '/storage/envoy',
        branch: trim((string) shell_exec('git branch --show-current 2>/dev/null')),
        db: new EnvoyDatabase(
            host: '127.0.0.1',
            port: 3306,
            database: 'example_db',
            username: $env('DB_USERNAME'),
            password: $env('DB_PASSWORD'),
        ),
    );

    /*
    | Which directories move, and which way. Which files a project mirrors is
    | permanent per project, so it is written down here rather than remembered
    | at the keyboard: these two lists are the only thing the storage commands
    | read. Paths are project-relative, anything not listed is never touched in
    | either direction, and with both lists empty they move nothing at all.
    |
    | delete: true makes the destination an exact mirror, which deletes files
    | at the far end that were never here. Off unless asked for.
    */

    $sync_pull_dirs = [
        new EnvoySyncDir('storage/app'),
    ];

    $sync_push_dirs = [
        // new EnvoySyncDir('public/uploads', delete: true),
    ];

    /*
    | Whether deploy and code-push build assets on the server. Set it false for
    | a project with no front-end build, or one that commits built assets;
    | --build / --nobuild override it for a single run.
    */

    $build_assets = true;

    /* Tables whose data is never carried between environments. */

    $ignore_tables = [
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

    /*
    |==========================================================================
    | End of config — mechanics below
    |==========================================================================
    */

    /*
    | SQLite is not a switch to set, it is read off the two database names: one
    | ending in .sqlite is a path to a file rather than the name of a schema.
    | Both ends have to be the same kind — there is no transfer between a MySQL
    | remote and a SQLite local, so a mismatch is a typo, and says so.
    */

    $is_sqlite = fn (EnvoyDatabase $db) => str_ends_with((string) $db->database, '.sqlite');

    if ($is_sqlite($remote->db) !== $is_sqlite($local->db)) {
        throw new RuntimeException(
            'Envoy: one database is a .sqlite path and the other is not. Both ends '
            . 'must be the same kind — remote: ' . $remote->db->database
            . ', local: ' . $local->db->database
        );
    }

    $sqlite = $is_sqlite($local->db);

    if (! $sqlite && (! $remote->db->username || ! $local->db->username)) {
        throw new RuntimeException(
            'Envoy: database credentials come from .env. Set PROD_DB_USERNAME / '
            . 'PROD_DB_PASSWORD for the remote, DB_USERNAME / DB_PASSWORD for local.'
        );
    }

    if (isset($build))   $build_assets = true;
    if (isset($nobuild)) $build_assets = false;

    /*
    | Everything that writes to the remote confirms first. --noconfirm answers
    | it in advance, for a run nobody is sitting in front of.
    */

    $confirm = fn (bool $when = true) => $when && ! isset($noconfirm);

    $servers = [
        'local'  => '127.0.0.1',
        'remote' => $remote->ssh . ($remote->port !== 22 ? " -p {$remote->port}" : ''),
    ];

    /*
    |--------------------------------------------------------------------------
    | Transfer helpers — one place that knows about non-standard SSH ports
    |--------------------------------------------------------------------------
    */

    $ssh_flag = $remote->port !== 22 ? "-p {$remote->port}" : '';
    $scp_flag = $remote->port !== 22 ? "-P {$remote->port}" : '';

    $rsync_opts = trim(
        '-az --human-readable '
        . ($remote->port !== 22 ? "-e 'ssh -p {$remote->port}' " : '')
        . (isset($dry) ? '--dry-run --itemize-changes' : '')
    );

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    $dump_latest = 'dump--latest.sql';
    $dump_stamp  = 'dump--' . date('Ymd-His') . '.sql';

    // Data only — --no-create-info, plus `migrate:fresh` before every import,
    // keeps the schema owned by the migrations and never by a dump.
    $dump_flags = '--single-transaction --quick --skip-lock-tables --no-tablespaces '
        . '--default-character-set=utf8mb4 --skip-triggers --no-create-info';

    $ignore = fn (?string $database) => implode(' ', array_map(
        fn ($table) => "--ignore-table={$database}.{$table}",
        $ignore_tables
    ));

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    | No flag reaches in here. The two lists above are the whole story: what
    | moves, which way, and whether the far end mirrors deletions is decided in
    | the config block and nowhere else.
    */

    $sync_opts = fn (EnvoySyncDir $e) => $e->delete ? '--delete' : '';
@endsetup

@servers($servers)

{{--
|--------------------------------------------------------------------------
| Maintenance mode
|--------------------------------------------------------------------------
--}}

@task('down', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    {{ $remote->php }} artisan up --quiet || true
    {{ $remote->php }} artisan down --render=errors::503
@endtask

@task('up', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    {{ $remote->php }} artisan up
@endtask

@task('status', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    echo "## {{ $remote->ssh }}:{{ $remote->path }}"
    git rev-parse --abbrev-ref HEAD
    git log -1 --oneline
    git status --porcelain
@endtask

{{--
|--------------------------------------------------------------------------
| Git — the building blocks the code stories are made of
|--------------------------------------------------------------------------
--}}

@task('git-push', ['on' => 'local'])
    set -e
    echo "## Pushing {{ $local->branch }} to origin"
    git push --quiet
@endtask

@task('git-repush', ['on' => 'local'])
    set -e
    echo "## Amending last commit on {{ $local->branch }} and force pushing"
    git add -A
    git commit --amend --no-edit --quiet
    git push --force-with-lease --quiet
@endtask

{{-- The remote is moved onto your local branch; your branch never moves. --}}

@task('git-pull', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    echo "## Checking out {{ $local->branch }} on the remote and pulling"
    git fetch --quiet
    git checkout {{ $local->branch }} --quiet
    git pull --quiet
@endtask

@task('git-reset', ['on' => 'remote', 'confirm' => $confirm()])
    set -e
    cd {{ $remote->path }}
    echo "## Discarding local commits on the remote and resetting to origin"
    git fetch --quiet
    git checkout {{ $local->branch }} --quiet
    git reset --hard "origin/{{ $local->branch }}" --quiet
    chmod 644 public/index.php
@endtask

{{--
|--------------------------------------------------------------------------
| Build
|--------------------------------------------------------------------------
--}}

@task('composer-install', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    echo "## Installing composer dependencies"
    {{ $remote->composer }} install --no-progress --no-interaction --no-dev --prefer-dist --optimize-autoloader
@endtask

@task('npm-build', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    echo "## Building assets"
    {{ $remote->npm }} ci --silent || {{ $remote->npm }} install --silent
    {{ $remote->npm }} run --silent build
@endtask

@task('migrate', ['on' => 'remote', 'confirm' => $confirm()])
    set -e
    cd {{ $remote->path }}
    echo "## Migrating the remote database"
    {{ $remote->php }} artisan migrate --force --ansi
@endtask

@task('optimize', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    {{ $remote->php }} artisan optimize
@endtask

@task('clear', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    {{ $remote->php }} artisan optimize:clear
@endtask

{{--
|--------------------------------------------------------------------------
| Database — dump / transfer / import
|--------------------------------------------------------------------------
--}}

@task('db-dump', ['on' => 'remote'])
    set -e
    mkdir -p {{ $remote->dumps }}
    echo "## Dumping remote database {{ $remote->db->database }}"
    MYSQL_PWD='{{ $remote->db->password }}' mysqldump \
        --host={{ $remote->db->host }} --port={{ $remote->db->port }} \
        --user={{ $remote->db->username }} \
        {{ $dump_flags }} \
        {{ $ignore($remote->db->database) }} \
        {{ $remote->db->database }} > {{ $remote->dumps }}/{{ $dump_latest }}
    cp {{ $remote->dumps }}/{{ $dump_latest }} {{ $remote->dumps }}/{{ $dump_stamp }}
    ls -lh {{ $remote->dumps }}/{{ $dump_latest }}
@endtask

@task('db-dump-local', ['on' => 'local'])
    set -e
    mkdir -p {{ $local->dumps }}
    echo "## Dumping local database {{ $local->db->database }}"
    MYSQL_PWD='{{ $local->db->password }}' mysqldump \
        --host={{ $local->db->host }} --port={{ $local->db->port }} \
        --user={{ $local->db->username }} \
        {{ $dump_flags }} \
        {{ $ignore($local->db->database) }} \
        {{ $local->db->database }} > {{ $local->dumps }}/{{ $dump_latest }}
    cp {{ $local->dumps }}/{{ $dump_latest }} {{ $local->dumps }}/{{ $dump_stamp }}
    ls -lh {{ $local->dumps }}/{{ $dump_latest }}
@endtask

@task('db-download', ['on' => 'local'])
    set -e
    mkdir -p {{ $local->dumps }}
    echo "## Downloading dump from the remote"
    scp {{ $scp_flag }} {{ $remote->ssh }}:{{ $remote->dumps }}/{{ $dump_latest }} {{ $local->dumps }}/{{ $dump_latest }}
    cp {{ $local->dumps }}/{{ $dump_latest }} {{ $local->dumps }}/{{ $dump_stamp }}
@endtask

{{-- Sends the dump you already have. It never reaches for a fresher one. --}}

@task('db-upload', ['on' => 'local'])
    set -e
    if [ ! -f {{ $local->dumps }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $local->dumps }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-pull' first, or 'envoy run db-dump-local'"
        exit 1
    fi
    echo "## Uploading {{ $dump_latest }} to the remote"
    ssh {{ $ssh_flag }} {{ $remote->ssh }} "mkdir -p {{ $remote->dumps }}"
    scp {{ $scp_flag }} {{ $local->dumps }}/{{ $dump_latest }} {{ $remote->ssh }}:{{ $remote->dumps }}/{{ $dump_latest }}
@endtask

@task('db-import', ['on' => 'local', 'confirm' => $confirm()])
    set -e
    cd {{ $local->path }}
    echo "## Rebuilding local schema from migrations on {{ $remote->branch }}"
    git checkout {{ $remote->branch }} --quiet
    php artisan migrate:fresh --drop-views --force --quiet
    echo "## Importing {{ $dump_latest }}"
    MYSQL_PWD='{{ $local->db->password }}' mysql \
        --host={{ $local->db->host }} --port={{ $local->db->port }} \
        --user={{ $local->db->username }} \
        {{ $local->db->database }} < {{ $local->dumps }}/{{ $dump_latest }}
    echo "## Back to {{ $local->branch }}, applying newer migrations"
    git checkout {{ $local->branch }} --quiet
    php artisan migrate --force --quiet
    php artisan optimize:clear --quiet
@endtask

{{--
| Drops and rebuilds the remote database, then imports the dump--latest.sql
| already sitting there — the one the last db-push uploaded. It uploads
| nothing itself, and it always confirms.
--}}

@task('db-import-remote', ['on' => 'remote', 'confirm' => $confirm()])
    set -e
    cd {{ $remote->path }}
    if [ ! -f {{ $remote->dumps }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $remote->dumps }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-push' to send one, or 'envoy run db-upload' on its own"
        exit 1
    fi
    echo "## Rebuilding the remote schema and importing {{ $dump_latest }}"
    {{ $remote->php }} artisan migrate:fresh --drop-views --force --quiet
    MYSQL_PWD='{{ $remote->db->password }}' mysql \
        --host={{ $remote->db->host }} --port={{ $remote->db->port }} \
        --user={{ $remote->db->username }} \
        {{ $remote->db->database }} < {{ $remote->dumps }}/{{ $dump_latest }}
    {{ $remote->php }} artisan migrate --force --quiet
    {{ $remote->php }} artisan optimize:clear --quiet
@endtask

{{-- SQLite projects transfer the file itself instead of dumping. --}}

@task('db-pull-sqlite', ['on' => 'local'])
    set -e
    echo "## Downloading the remote sqlite database"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $remote->ssh }}:{{ $remote->db->database }} \
        {{ $local->db->database }}
@endtask

@task('db-push-sqlite', ['on' => 'local', 'confirm' => $confirm()])
    set -e
    echo "## Uploading local sqlite database to the remote"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $local->db->database }} \
        {{ $remote->ssh }}:{{ $remote->db->database }}
@endtask

{{--
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
--}}

@task('storage-pull', ['on' => 'local'])
    set -e
@if (! $sync_pull_dirs)
    echo '## Nothing is declared to pull — see the sync_pull_dirs list in Envoy.blade.php'
@endif
@foreach ($sync_pull_dirs as $e)
    echo "## remote:{{ $e->path }} -> local"
    mkdir -p {{ $local->path }}/{{ $e->path }}
    rsync {{ $rsync_opts }} {{ $sync_opts($e) }} \
        {{ $remote->ssh }}:{{ $remote->path }}/{{ $e->path }}/ \
        {{ $local->path }}/{{ $e->path }}/
@endforeach
@endtask

@task('storage-push', ['on' => 'local', 'confirm' => $confirm()])
    set -e
@if (! $sync_push_dirs)
    echo '## Nothing is declared to push — see the sync_push_dirs list in Envoy.blade.php'
@endif
@foreach ($sync_push_dirs as $e)
    echo "## local:{{ $e->path }} -> remote"
    ssh {{ $ssh_flag }} {{ $remote->ssh }} "mkdir -p {{ $remote->path }}/{{ $e->path }}"
    rsync {{ $rsync_opts }} {{ $sync_opts($e) }} \
        {{ $local->path }}/{{ $e->path }}/ \
        {{ $remote->ssh }}:{{ $remote->path }}/{{ $e->path }}/
@endforeach
@endtask

@task('storage-nothing-declared', ['on' => 'local'])
    echo "## No directories are declared, so there is nothing to do."
    echo '##   e.g. sync_pull_dirs = [new EnvoySyncDir("storage/app/public")];'
@endtask

{{--
|--------------------------------------------------------------------------
| Code
|--------------------------------------------------------------------------
--}}

@story('code-push')
    down
@if (isset($force))
    git-repush
    git-reset
@else
    git-push
    git-pull
@endif
@if ($build_assets)
    npm-build
@endif
    optimize
    up
@endstory

@story('deploy')
    down
@if (isset($force))
    git-repush
    git-reset
@else
    git-push
    git-pull
@endif
    composer-install
    migrate
@if ($build_assets)
    npm-build
@endif
    optimize
    up
@endstory

{{--
| An alias for the command typed the most. A story is only a list of names,
| so it carries every flag straight through: push --force is code-push --force.
--}}

@story('push')
    code-push
@endstory

{{--
|--------------------------------------------------------------------------
| Database stories
|--------------------------------------------------------------------------
--}}

@story('db-pull')
@if ($sqlite)
    db-pull-sqlite
@else
    db-dump
    db-download
    db-import
@endif
@endstory

@story('db-push')
@if ($sqlite)
    db-push-sqlite
@else
    db-dump-local
    db-upload
    db-import-remote
@endif
@endstory

{{--
|--------------------------------------------------------------------------
| Storage stories
|--------------------------------------------------------------------------
--}}

@story('storage-sync')
@if (! $sync_pull_dirs && ! $sync_push_dirs)
    storage-nothing-declared
@endif
@if ($sync_pull_dirs)
    storage-pull
@endif
@if ($sync_push_dirs)
    storage-push
@endif
@endstory
