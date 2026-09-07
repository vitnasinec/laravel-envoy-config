{{--
|--------------------------------------------------------------------------
| Shared Laravel Envoy template
|--------------------------------------------------------------------------
|
| Drop this file in the project root as Envoy.blade.php, copy the matching
| keys from envoy.env.example into the project's .env, and it should work
| without editing anything in here.
|
| Environments   local and one remote — that is the whole map
| Direction      push = local -> remote,  pull = remote -> local
|
| There is no server to choose, so no command takes a target flag. Everything
| that writes to the remote asks first; --noconfirm answers for scripts.
|
| The directories this project mirrors are declared once in ENVOY_STORAGE_SYNC:
|
|   ENVOY_STORAGE_SYNC="storage-push --dir=storage/app, storage-pull --dir=storage/media"
|
| Common commands
|   envoy run code-push            fast deploy  (alias: push)
|   envoy run deploy               full deploy — composer install and migrate too
|   envoy run db-pull              remote database down to local
|   envoy run storage-push --dir=storage/app/public
|   envoy run storage-sync         every declared directory, both ways
|
--}}

@include('vendor/autoload.php')

@setup
    /*
    |--------------------------------------------------------------------------
    | Config
    |--------------------------------------------------------------------------
    */

    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

    $cfg = function (string $key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value,
        };
    };

    $csv = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

    /*
    |--------------------------------------------------------------------------
    | The remote
    |--------------------------------------------------------------------------
    | One server, on the PROD_ keys. PROD_SSH_HOST is what makes the rest of
    | this file mean anything, so its absence is an error, not a default.
    */

    if (! $cfg('PROD_SSH_HOST')) {
        throw new RuntimeException(
            'Envoy: no remote is configured. Set PROD_SSH_HOST in .env — see envoy.env.example.'
        );
    }

    $remote = [
        'user'     => $cfg('PROD_SSH_USER'),
        'host'     => $cfg('PROD_SSH_HOST'),
        'port'     => (int) $cfg('PROD_SSH_PORT', 22),
        'path'     => rtrim($cfg('PROD_PATH', '~/' . basename(__DIR__)), '/'),
        'dumps'    => rtrim($cfg('PROD_DUMP_PATH', $cfg('PROD_PATH', '~') . '/storage/envoy'), '/'),
        'php'      => $cfg('PROD_PHP', 'php'),
        'composer' => $cfg('PROD_COMPOSER', 'composer'),
        'npm'      => $cfg('PROD_NPM', 'npm'),
        'branch'   => $cfg('PROD_BRANCH', 'main'),
        'db' => [
            'host'     => $cfg('PROD_DB_HOST', '127.0.0.1'),
            'port'     => (int) $cfg('PROD_DB_PORT', 3306),
            'database' => $cfg('PROD_DB_DATABASE'),
            'username' => $cfg('PROD_DB_USERNAME'),
            'password' => $cfg('PROD_DB_PASSWORD'),
            'sqlite'   => $cfg('PROD_DB_SQLITE_PATH'),
        ],
    ];

    $remote['ssh'] = $remote['user']
        ? $remote['user'] . '@' . $remote['host']
        : $remote['host'];

    /*
    |--------------------------------------------------------------------------
    | Local environment — reuses Laravel's own DB_* keys
    |--------------------------------------------------------------------------
    */

    $local = [
        'path'   => __DIR__,
        'dumps'  => rtrim($cfg('ENVOY_DUMP_PATH', __DIR__ . '/storage/envoy'), '/'),
        'branch' => trim((string) shell_exec('git branch --show-current 2>/dev/null')),
        'db' => [
            'host'     => $cfg('DB_HOST', '127.0.0.1'),
            'port'     => (int) $cfg('DB_PORT', 3306),
            'database' => $cfg('DB_DATABASE'),
            'username' => $cfg('DB_USERNAME'),
            'password' => $cfg('DB_PASSWORD'),
            'sqlite'   => $cfg('DB_SQLITE_PATH', __DIR__ . '/database/database.sqlite'),
        ],
    ];

    /*
    | Everything that writes to the remote confirms first. --noconfirm answers
    | it in advance, for a run nobody is sitting in front of.
    */

    $confirm = fn (bool $when = true) => $when && ! isset($noconfirm);

    $servers = [
        'local'  => '127.0.0.1',
        'remote' => $remote['ssh'] . ($remote['port'] !== 22 ? " -p {$remote['port']}" : ''),
    ];

    /*
    |--------------------------------------------------------------------------
    | Transfer helpers — one place that knows about non-standard SSH ports
    |--------------------------------------------------------------------------
    */

    $ssh_flag = $remote['port'] !== 22 ? "-p {$remote['port']}" : '';
    $scp_flag = $remote['port'] !== 22 ? "-P {$remote['port']}" : '';

    $rsync_opts = trim(
        '-az --human-readable '
        . ($remote['port'] !== 22 ? "-e 'ssh -p {$remote['port']}' " : '')
        . (isset($dry) ? '--dry-run --itemize-changes' : '')
    );

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    $sqlite = strtolower((string) $cfg('DB_CONNECTION', 'mysql')) === 'sqlite';

    $dump_latest = 'dump--latest.sql';
    $dump_stamp  = 'dump--' . date('Ymd-His') . '.sql';

    // Data only — --no-create-info, plus `migrate:fresh` before every import,
    // keeps the schema owned by the migrations and never by a dump.
    $dump_flags = '--single-transaction --quick --skip-lock-tables --no-tablespaces '
        . '--default-character-set=utf8mb4 --skip-triggers --no-create-info';

    $ignore_tables = $csv($cfg(
        'ENVOY_DB_IGNORE_TABLES',
        'migrations,cache,cache_locks,sessions,jobs,job_batches,failed_jobs,'
        . 'telescope_entries,telescope_entries_tags,telescope_monitoring,'
        . 'pulse_aggregates,pulse_entries,pulse_values'
    ));

    $ignore = fn (?string $database) => implode(' ', array_map(
        fn ($table) => "--ignore-table={$database}.{$table}",
        $ignore_tables
    ));

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    | Not every project builds on the server — some have no front-end build at
    | all, some ship compiled assets in the repo. Left unset, this detects a
    | "build" script in package.json; set ENVOY_BUILD_ASSETS to decide
    | explicitly, or override per run with --build / --nobuild.
    */

    $build_assets = $cfg('ENVOY_BUILD_ASSETS');

    if ($build_assets === null) {
        $package = __DIR__ . '/package.json';

        $build_assets = is_file($package)
            && isset(json_decode((string) file_get_contents($package), true)['scripts']['build']);
    }

    $build_assets = (bool) $build_assets;

    if (isset($build))   $build_assets = true;
    if (isset($nobuild)) $build_assets = false;

    /*
    |--------------------------------------------------------------------------
    | Which directories move, and which way
    |--------------------------------------------------------------------------
    | Which files a project mirrors differs per project, so declare it once in
    | .env instead of remembering it at the keyboard. An entry is the command
    | you would have typed, flags and all:
    |
    |   ENVOY_STORAGE_SYNC="storage-pull --dir=storage/media, storage-push --dir=public/uploads --delete"
    |
    | Entries are comma separated, each a storage-pull or storage-push naming
    | the one directory it moves:
    |
    |   --dir=<path>  one project-relative directory, required
    |   --delete      mirror deletions at the destination (off by default)
    |
    | Anything not listed is never touched. There is no built-in directory
    | behind any of this either: with nothing declared, storage-pull and
    | storage-push move nothing until you pass --dir. Files move where you
    | said so and nowhere else.
    */

    $sync_pull_dirs = [];
    $sync_push_dirs = [];

    $sync_assign = function (array $entry, string $direction) use (&$sync_pull_dirs, &$sync_push_dirs) {
        $drop = fn (array $list) => array_values(array_filter(
            $list,
            fn ($e) => $e['path'] !== $entry['path']
        ));

        $sync_pull_dirs = $drop($sync_pull_dirs);
        $sync_push_dirs = $drop($sync_push_dirs);

        if ($direction === 'pull') {
            $sync_pull_dirs[] = $entry;
        } else {
            $sync_push_dirs[] = $entry;
        }
    };

    // One directory per --dir, so a comma is always an entry separator and
    // never part of a path.
    $sync_path = function (string $path, string $context) {
        $path = trim(trim($path), '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, ',')) {
            throw new RuntimeException(
                "Envoy: {$context} needs one project-relative directory, "
                . 'e.g. --dir=storage/app/public.'
            );
        }

        return $path;
    };

    foreach (preg_split('/[,\n]+/', (string) $cfg('ENVOY_STORAGE_SYNC', ''), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        $entry = trim($entry);

        if ($entry === '') {
            continue;
        }

        $tokens  = preg_split('/\s+/', $entry, -1, PREG_SPLIT_NO_EMPTY);
        $command = strtolower(array_shift($tokens));

        if (! in_array($command, ['storage-pull', 'storage-push'], true)) {
            throw new RuntimeException(
                "Envoy: ENVOY_STORAGE_SYNC entry [{$entry}] must start with "
                . 'storage-pull or storage-push, one entry per comma.'
            );
        }

        $direction    = explode('-', $command, 2)[1];
        $delete_entry = false;
        $path_entry   = null;

        foreach ($tokens as $flag) {
            if ($flag === '--delete') {
                $delete_entry = true;
            } elseif (str_starts_with($flag, '--dir=')) {
                $path_entry = $sync_path(substr($flag, strlen('--dir=')), "ENVOY_STORAGE_SYNC entry [{$entry}]");
            } else {
                throw new RuntimeException(
                    "Envoy: ENVOY_STORAGE_SYNC entry [{$entry}] has unknown flag [{$flag}]. "
                    . 'Allowed: --dir=<path>, --delete.'
                );
            }
        }

        if ($path_entry === null) {
            throw new RuntimeException(
                "Envoy: ENVOY_STORAGE_SYNC entry [{$entry}] names no directory. "
                . 'Add --dir=<path>, e.g. storage-pull --dir=storage/app/public.'
            );
        }

        $sync_assign(['path' => $path_entry, 'delete' => $delete_entry], $direction);
    }

    /*
    | --dir=public/uploads is the ad-hoc escape hatch: it transfers exactly
    | that project-relative directory, whether or not ENVOY_STORAGE_SYNC
    | mentions it, and ignores the declaration entirely.
    */

    if (isset($dir)) {
        $ad_hoc = [[
            'path'   => $sync_path((string) $dir, "--dir=[{$dir}]"),
            'delete' => false,
        ]];

        $sync_pull_dirs = $ad_hoc;
        $sync_push_dirs = $ad_hoc;
    }

    /*
    | rsync --delete makes the destination an exact mirror, which deletes files
    | at the far end that were never here. Off everywhere unless asked for:
    | --delete on the entry, or --delete for the whole run.
    */

    $force_delete = isset($delete);

    $sync_resolve = fn (array $list) => array_map(
        fn (array $e) => $e + ['opts' => $force_delete || $e['delete'] ? '--delete' : ''],
        $list
    );

    $sync_pull_dirs = $sync_resolve($sync_pull_dirs);
    $sync_push_dirs = $sync_resolve($sync_push_dirs);
@endsetup

@servers($servers)

{{--
|--------------------------------------------------------------------------
| Maintenance mode
|--------------------------------------------------------------------------
--}}

@task('down', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    {{ $remote['php'] }} artisan up --quiet || true
    {{ $remote['php'] }} artisan down --render=errors::503
@endtask

@task('up', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    {{ $remote['php'] }} artisan up
@endtask

@task('status', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    echo "## {{ $remote['ssh'] }}:{{ $remote['path'] }}"
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
    echo "## Pushing {{ $local['branch'] }} to origin"
    git push --quiet
@endtask

@task('git-repush', ['on' => 'local'])
    set -e
    echo "## Amending last commit on {{ $local['branch'] }} and force pushing"
    git add -A
    git commit --amend --no-edit --quiet
    git push --force-with-lease --quiet
@endtask

{{-- The remote is moved onto your local branch; your branch never moves. --}}

@task('git-pull', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    echo "## Checking out {{ $local['branch'] }} on the remote and pulling"
    git fetch --quiet
    git checkout {{ $local['branch'] }} --quiet
    git pull --quiet
@endtask

@task('git-reset', ['on' => 'remote', 'confirm' => $confirm()])
    set -e
    cd {{ $remote['path'] }}
    echo "## Discarding local commits on the remote and resetting to origin"
    git fetch --quiet
    git checkout {{ $local['branch'] }} --quiet
    git reset --hard "origin/{{ $local['branch'] }}" --quiet
    chmod 644 public/index.php
@endtask

{{--
|--------------------------------------------------------------------------
| Build
|--------------------------------------------------------------------------
--}}

@task('composer-install', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    echo "## Installing composer dependencies"
    {{ $remote['composer'] }} install --no-progress --no-interaction --no-dev --prefer-dist --optimize-autoloader
@endtask

@task('npm-build', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    echo "## Building assets"
    {{ $remote['npm'] }} ci --silent || {{ $remote['npm'] }} install --silent
    {{ $remote['npm'] }} run --silent build
@endtask

@task('migrate', ['on' => 'remote', 'confirm' => $confirm()])
    set -e
    cd {{ $remote['path'] }}
    echo "## Migrating the remote database"
    {{ $remote['php'] }} artisan migrate --force --ansi
@endtask

@task('optimize', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    {{ $remote['php'] }} artisan optimize
@endtask

@task('clear', ['on' => 'remote'])
    set -e
    cd {{ $remote['path'] }}
    {{ $remote['php'] }} artisan optimize:clear
@endtask

{{--
|--------------------------------------------------------------------------
| Database — dump / transfer / import
|--------------------------------------------------------------------------
--}}

@task('db-dump', ['on' => 'remote'])
    set -e
    mkdir -p {{ $remote['dumps'] }}
    echo "## Dumping remote database {{ $remote['db']['database'] }}"
    MYSQL_PWD='{{ $remote['db']['password'] }}' mysqldump \
        --host={{ $remote['db']['host'] }} --port={{ $remote['db']['port'] }} \
        --user={{ $remote['db']['username'] }} \
        {{ $dump_flags }} \
        {{ $ignore($remote['db']['database']) }} \
        {{ $remote['db']['database'] }} > {{ $remote['dumps'] }}/{{ $dump_latest }}
    cp {{ $remote['dumps'] }}/{{ $dump_latest }} {{ $remote['dumps'] }}/{{ $dump_stamp }}
    ls -lh {{ $remote['dumps'] }}/{{ $dump_latest }}
@endtask

@task('db-dump-local', ['on' => 'local'])
    set -e
    mkdir -p {{ $local['dumps'] }}
    echo "## Dumping local database {{ $local['db']['database'] }}"
    MYSQL_PWD='{{ $local['db']['password'] }}' mysqldump \
        --host={{ $local['db']['host'] }} --port={{ $local['db']['port'] }} \
        --user={{ $local['db']['username'] }} \
        {{ $dump_flags }} \
        {{ $ignore($local['db']['database']) }} \
        {{ $local['db']['database'] }} > {{ $local['dumps'] }}/{{ $dump_latest }}
    cp {{ $local['dumps'] }}/{{ $dump_latest }} {{ $local['dumps'] }}/{{ $dump_stamp }}
    ls -lh {{ $local['dumps'] }}/{{ $dump_latest }}
@endtask

@task('db-download', ['on' => 'local'])
    set -e
    mkdir -p {{ $local['dumps'] }}
    echo "## Downloading dump from the remote"
    scp {{ $scp_flag }} {{ $remote['ssh'] }}:{{ $remote['dumps'] }}/{{ $dump_latest }} {{ $local['dumps'] }}/{{ $dump_latest }}
    cp {{ $local['dumps'] }}/{{ $dump_latest }} {{ $local['dumps'] }}/{{ $dump_stamp }}
@endtask

{{-- Sends the dump you already have. It never reaches for a fresher one. --}}

@task('db-upload', ['on' => 'local'])
    set -e
    if [ ! -f {{ $local['dumps'] }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $local['dumps'] }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-pull' first, or 'envoy run db-dump-local'"
        exit 1
    fi
    echo "## Uploading {{ $dump_latest }} to the remote"
    ssh {{ $ssh_flag }} {{ $remote['ssh'] }} "mkdir -p {{ $remote['dumps'] }}"
    scp {{ $scp_flag }} {{ $local['dumps'] }}/{{ $dump_latest }} {{ $remote['ssh'] }}:{{ $remote['dumps'] }}/{{ $dump_latest }}
@endtask

@task('db-import', ['on' => 'local', 'confirm' => $confirm()])
    set -e
    cd {{ $local['path'] }}
    echo "## Rebuilding local schema from migrations on {{ $remote['branch'] }}"
    git checkout {{ $remote['branch'] }} --quiet
    php artisan migrate:fresh --drop-views --force --quiet
    echo "## Importing {{ $dump_latest }}"
    MYSQL_PWD='{{ $local['db']['password'] }}' mysql \
        --host={{ $local['db']['host'] }} --port={{ $local['db']['port'] }} \
        --user={{ $local['db']['username'] }} \
        {{ $local['db']['database'] }} < {{ $local['dumps'] }}/{{ $dump_latest }}
    echo "## Back to {{ $local['branch'] }}, applying newer migrations"
    git checkout {{ $local['branch'] }} --quiet
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
    cd {{ $remote['path'] }}
    if [ ! -f {{ $remote['dumps'] }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $remote['dumps'] }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-push' to send one, or 'envoy run db-upload' on its own"
        exit 1
    fi
    echo "## Rebuilding the remote schema and importing {{ $dump_latest }}"
    {{ $remote['php'] }} artisan migrate:fresh --drop-views --force --quiet
    MYSQL_PWD='{{ $remote['db']['password'] }}' mysql \
        --host={{ $remote['db']['host'] }} --port={{ $remote['db']['port'] }} \
        --user={{ $remote['db']['username'] }} \
        {{ $remote['db']['database'] }} < {{ $remote['dumps'] }}/{{ $dump_latest }}
    {{ $remote['php'] }} artisan migrate --force --quiet
    {{ $remote['php'] }} artisan optimize:clear --quiet
@endtask

{{-- SQLite projects transfer the file itself instead of dumping. --}}

@task('db-pull-sqlite', ['on' => 'local'])
    set -e
    echo "## Downloading the remote sqlite database"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $remote['ssh'] }}:{{ $remote['db']['sqlite'] ?: $remote['path'] . '/database/database.sqlite' }} \
        {{ $local['db']['sqlite'] }}
@endtask

@task('db-push-sqlite', ['on' => 'local', 'confirm' => $confirm()])
    set -e
    echo "## Uploading local sqlite database to the remote"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $local['db']['sqlite'] }} \
        {{ $remote['ssh'] }}:{{ $remote['db']['sqlite'] ?: $remote['path'] . '/database/database.sqlite' }}
@endtask

{{--
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
--}}

@task('storage-pull', ['on' => 'local'])
    set -e
@if (! $sync_pull_dirs)
    echo "## Nothing is declared to pull — see ENVOY_STORAGE_SYNC in .env, or pass --dir=<path>"
@endif
@foreach ($sync_pull_dirs as $e)
    echo "## remote:{{ $e['path'] }} -> local"
    mkdir -p {{ $local['path'] }}/{{ $e['path'] }}
    rsync {{ $rsync_opts }} {{ $e['opts'] }} \
        {{ $remote['ssh'] }}:{{ $remote['path'] }}/{{ $e['path'] }}/ \
        {{ $local['path'] }}/{{ $e['path'] }}/
@endforeach
@endtask

@task('storage-push', ['on' => 'local', 'confirm' => $confirm()])
    set -e
@if (! $sync_push_dirs)
    echo "## Nothing is declared to push — see ENVOY_STORAGE_SYNC in .env, or pass --dir=<path>"
@endif
@foreach ($sync_push_dirs as $e)
    echo "## local:{{ $e['path'] }} -> remote"
    ssh {{ $ssh_flag }} {{ $remote['ssh'] }} "mkdir -p {{ $remote['path'] }}/{{ $e['path'] }}"
    rsync {{ $rsync_opts }} {{ $e['opts'] }} \
        {{ $local['path'] }}/{{ $e['path'] }}/ \
        {{ $remote['ssh'] }}:{{ $remote['path'] }}/{{ $e['path'] }}/
@endforeach
@endtask

@task('storage-nothing-declared', ['on' => 'local'])
    echo "## No directories are declared, so there is nothing to do."
    echo '##   e.g. ENVOY_STORAGE_SYNC="storage-pull --dir=storage/app/public"'
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
