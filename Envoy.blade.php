{{--
|--------------------------------------------------------------------------
| Shared Laravel Envoy template
|--------------------------------------------------------------------------
|
| Drop this file in the project root as Envoy.blade.php, copy the matching
| keys from envoy.env.example into the project's .env, and it should work
| without editing anything in here.
|
| Environments   local, dev, prod   (always these three names)
| Direction      push = local -> remote,  pull = remote -> local
| Target         pushes go to dev, pulls come from prod, unless you say
|                otherwise with --prod / --dev / --on=
|
| Production is the source of truth, dev is the safe place to write, so the
| direction of a command already implies which server it means. Imports never
| run on production at all — the task refuses rather than prompts.
|
| Where this project's data flows is declared once in ENVOY_SYNC_ALL:
|
|   ENVOY_SYNC_ALL="db-pull, storage-push --dir=storage/app, storage-pull --dir=storage/media"
|
| The directories can live on their own key instead, which ENVOY_SYNC_ALL then
| pulls in wherever it says storage-sync:
|
|   ENVOY_SYNC_ALL="db-pull, storage-sync"
|   ENVOY_STORAGE_SYNC="storage-push --dir=storage/app, storage-pull --dir=storage/media"
|
| Common commands
|   envoy run code-push            fast deploy to dev
|   envoy run deploy --prod        full deploy to production
|   envoy run db-pull              production database down to local
|   envoy run storage-push --dir=storage/app/public
|   envoy run sync-all             the whole ENVOY_SYNC_ALL declaration
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
    | Remote environments
    |--------------------------------------------------------------------------
    | Only the ones with a *_SSH_HOST in .env are registered, so a project
    | with production only needs no changes here.
    */

    $environments = [];

    foreach (['prod', 'dev'] as $name) {
        $p = strtoupper($name) . '_';

        if (! $cfg($p . 'SSH_HOST')) {
            continue;
        }

        $environments[$name] = [
            'name'     => $name,
            'user'     => $cfg($p . 'SSH_USER'),
            'host'     => $cfg($p . 'SSH_HOST'),
            'port'     => (int) $cfg($p . 'SSH_PORT', 22),
            'path'     => rtrim($cfg($p . 'PATH', '~/' . basename(__DIR__)), '/'),
            'dumps'    => rtrim($cfg($p . 'DUMP_PATH', $cfg($p . 'PATH', '~') . '/storage/envoy'), '/'),
            'php'      => $cfg($p . 'PHP', 'php'),
            'composer' => $cfg($p . 'COMPOSER', 'composer'),
            'npm'      => $cfg($p . 'NPM', 'npm'),
            'branch'   => $cfg($p . 'BRANCH', $name === 'prod' ? 'main' : 'dev'),
            'db' => [
                'host'     => $cfg($p . 'DB_HOST', '127.0.0.1'),
                'port'     => (int) $cfg($p . 'DB_PORT', 3306),
                'database' => $cfg($p . 'DB_DATABASE'),
                'username' => $cfg($p . 'DB_USERNAME'),
                'password' => $cfg($p . 'DB_PASSWORD'),
                'sqlite'   => $cfg($p . 'DB_SQLITE_PATH'),
            ],
        ];

        $environments[$name]['ssh'] = $environments[$name]['user']
            ? $environments[$name]['user'] . '@' . $environments[$name]['host']
            : $environments[$name]['host'];
    }

    if (! $environments) {
        throw new RuntimeException(
            'Envoy: no remote is configured. Set PROD_SSH_HOST (and optionally '
            . 'DEV_SSH_HOST) in .env — see envoy.env.example.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Local environment — reuses Laravel's own DB_* keys
    |--------------------------------------------------------------------------
    */

    $local = [
        'name'   => 'local',
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
    |--------------------------------------------------------------------------
    | Which remote does each direction mean?
    |--------------------------------------------------------------------------
    | Pushes go to dev, pulls come from prod. That is the whole rule, and it is
    | why `storage-push` and `storage-pull` need no flag to do the safe thing.
    | An explicit --prod / --dev / --on= pins both directions at once, so one
    | flag still overrides everything. Projects with a single remote get that
    | remote for both directions.
    */

    $pick = function (string $preferred) use ($environments) {
        return $environments[$preferred] ?? reset($environments);
    };

    $push_env = $pick('dev');
    $pull_env = $pick('prod');

    if (isset($prod)) $push_env = $pull_env = $pick('prod');
    if (isset($dev))  $push_env = $pull_env = $pick('dev');

    if (isset($on)) {
        if (! isset($environments[$on])) {
            throw new RuntimeException(
                "Envoy: environment [{$on}] is not configured. "
                . 'Set ' . strtoupper((string) $on) . '_SSH_HOST in .env. '
                . 'Configured: ' . implode(', ', array_keys($environments)) . '.'
            );
        }

        $push_env = $pull_env = $environments[$on];
    }

    $pushing_to_prod = $push_env['name'] === 'prod';

    // code-sync runs this file again for each remote. The confirmation was
    // already given once, up front, so the nested runs must not ask again.
    $confirm = fn (bool $when = true) => $when && ! isset($noconfirm);

    $servers = ['local' => '127.0.0.1'];

    foreach (['push' => $push_env, 'pull' => $pull_env] as $role => $e) {
        $servers[$role] = $e['ssh'] . ($e['port'] !== 22 ? " -p {$e['port']}" : '');
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer helpers — one place that knows about non-standard SSH ports
    |--------------------------------------------------------------------------
    */

    $ssh_flag  = fn (array $e) => $e['port'] !== 22 ? "-p {$e['port']}" : '';
    $scp_flag  = fn (array $e) => $e['port'] !== 22 ? "-P {$e['port']}" : '';
    $rsync_ssh = fn (array $e) => $e['port'] !== 22 ? "-e 'ssh -p {$e['port']}'" : '';

    $rsync_opts = fn (array $e) => trim('-az --human-readable ' . $rsync_ssh($e)
        . (isset($progress) ? ' --info=progress2' : '')
        . (isset($dry) ? ' --dry-run --itemize-changes' : ''));

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    $sqlite = strtolower((string) $cfg('DB_CONNECTION', 'mysql')) === 'sqlite';

    $dump_latest = 'dump--latest.sql';
    $dump_stamp  = 'dump--' . date('Ymd-His') . '.sql';

    // Data only + `migrate:fresh` before import keeps the schema owned by
    // migrations. Set ENVOY_DB_DUMP_SCHEMA=true for a full structural dump.
    $dump_schema = (bool) $cfg('ENVOY_DB_DUMP_SCHEMA', false);

    $dump_flags = trim(implode(' ', [
        '--single-transaction --quick --skip-lock-tables --no-tablespaces',
        '--default-character-set=utf8mb4 --skip-triggers',
        $dump_schema ? '' : '--no-create-info',
    ]));

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
    | `db-import` with no flag means the local database — but the push default
    | is dev, so it cannot read $push_env. It looks at the flags itself, and
    | production is refused outright rather than confirmed.
    */

    $import_remote = isset($dev) || isset($prod) || (isset($on) && $on !== 'local');

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
    | Which way the data flows
    |--------------------------------------------------------------------------
    | Where the source of truth lives differs per project, so declare it once
    | in .env instead of remembering it at the keyboard. An entry is the
    | command you would have typed, flags and all:
    |
    |   ENVOY_SYNC_ALL="db-pull, storage-push --dir=public/uploads --delete"
    |
    | Entries are comma separated. Each is db-pull, db-push, a storage-pull
    | / storage-push naming the one directory it moves, or storage-sync for
    | the directories declared in ENVOY_STORAGE_SYNC:
    |
    |   --dir=<path>  one project-relative directory, required
    |   --delete      mirror deletions at the destination (off by default)
    |
    | Anything not listed is never touched — leave out db-pull/db-push and only
    | files move.
    */

    $sync_pull_dirs = [];
    $sync_push_dirs = [];
    $sync_db        = null;

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

    /*
    | The directories can live on a key of their own, ENVOY_STORAGE_SYNC, so a
    | project that moves several of them does not turn ENVOY_SYNC_ALL into one
    | long line. ENVOY_SYNC_ALL then just says storage-sync where they belong:
    |
    |   ENVOY_SYNC_ALL="db-pull, storage-sync"
    |   ENVOY_STORAGE_SYNC="storage-push --dir=storage/app, storage-pull --dir=storage/media"
    |
    | Naming the directories in ENVOY_SYNC_ALL still works and still wins —
    | leave storage-sync out and ENVOY_STORAGE_SYNC is not read at all.
    |
    | There is no built-in directory behind any of this: an undeclared
    | storage-sync moves nothing, and so does a storage-pull / storage-push
    | run with no --dir and nothing declared. Files move where you said so
    | and nowhere else.
    */

    $storage_spec = (string) $cfg('ENVOY_STORAGE_SYNC', '');

    $sync_parse = function (string $spec, string $source) use (&$sync_parse, &$sync_db, &$storage_spec, $sync_assign, $sync_path) {
        $commands = $source === 'ENVOY_SYNC_ALL'
            ? ['db-pull', 'db-push', 'storage-pull', 'storage-push', 'storage-sync']
            : ['storage-pull', 'storage-push'];

        foreach (preg_split('/[,\n]+/', trim($spec), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $tokens  = preg_split('/\s+/', $entry, -1, PREG_SPLIT_NO_EMPTY);
            $command = strtolower(array_shift($tokens));

            if (! in_array($command, $commands, true)) {
                throw new RuntimeException(
                    "Envoy: {$source} entry [{$entry}] must start with "
                    . implode(', ', array_slice($commands, 0, -1)) . ' or ' . end($commands)
                    . ', one entry per comma.'
                );
            }

            // storage-sync stands in for whatever ENVOY_STORAGE_SYNC declares,
            // in the place ENVOY_SYNC_ALL puts it.
            if ($command === 'storage-sync') {
                if ($tokens) {
                    throw new RuntimeException(
                        "Envoy: {$source} entry [{$entry}] takes no flags — "
                        . 'they belong on the entries in ENVOY_STORAGE_SYNC.'
                    );
                }

                $sync_parse($storage_spec, 'ENVOY_STORAGE_SYNC');

                continue;
            }

            [$what, $direction] = explode('-', $command, 2);

            $delete_entry = false;
            $path_entry   = null;

            foreach ($tokens as $flag) {
                if ($flag === '--delete') {
                    $delete_entry = true;
                } elseif (str_starts_with($flag, '--dir=')) {
                    $path_entry = $sync_path(substr($flag, strlen('--dir=')), "{$source} entry [{$entry}]");
                } else {
                    throw new RuntimeException(
                        "Envoy: {$source} entry [{$entry}] has unknown flag [{$flag}]. "
                        . 'Allowed: --dir=<path>, --delete.'
                    );
                }
            }

            if ($what === 'db') {
                if ($tokens) {
                    throw new RuntimeException(
                        "Envoy: {$source} entry [{$entry}] — flags apply to storage entries only."
                    );
                }

                $sync_db = $direction;

                continue;
            }

            if ($path_entry === null) {
                throw new RuntimeException(
                    "Envoy: {$source} entry [{$entry}] names no directory. "
                    . 'Add --dir=<path>, e.g. storage-pull --dir=storage/app/public.'
                );
            }

            $sync_assign(['path' => $path_entry, 'delete' => $delete_entry], $direction);
        }
    };

    // The key used to be ENVOY_SYNC. A stale one would be ignored silently and
    // the default direction used instead, which is how a db-push project ends
    // up pulling, so say so rather than guess.
    if ($cfg('ENVOY_SYNC') !== null && $cfg('ENVOY_SYNC_ALL') === null) {
        throw new RuntimeException('Envoy: ENVOY_SYNC has been renamed to ENVOY_SYNC_ALL. Rename the key in .env.');
    }

    $sync_parse((string) $cfg('ENVOY_SYNC_ALL', 'db-pull, storage-sync'), 'ENVOY_SYNC_ALL');

    /*
    | --dir=public/uploads is the ad-hoc escape hatch: it transfers exactly
    | that project-relative directory, whether or not ENVOY_SYNC_ALL mentions
    | it, and ignores the declaration entirely.
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

@task('down', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    {{ $push_env['php'] }} artisan up --quiet || true
    {{ $push_env['php'] }} artisan down --render=errors::503
@endtask

@task('up', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    {{ $push_env['php'] }} artisan up
@endtask

@task('status', ['on' => 'pull'])
    set -e
    cd {{ $pull_env['path'] }}
    echo "## {{ $pull_env['name'] }} @ {{ $pull_env['ssh'] }}:{{ $pull_env['path'] }}"
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

@task('git-pull', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    echo "## Checking out {{ $local['branch'] }} on {{ $push_env['name'] }} and pulling"
    git fetch --quiet
    git checkout {{ $local['branch'] }} --quiet
    git pull --quiet
@endtask

@task('git-reset', ['on' => 'push', 'confirm' => $confirm()])
    set -e
    cd {{ $push_env['path'] }}
    echo "## Discarding local commits on {{ $push_env['name'] }} and resetting to origin"
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

@task('composer-install', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    echo "## Installing composer dependencies"
@if ($pushing_to_prod)
    {{ $push_env['composer'] }} install --no-progress --no-interaction --no-dev --prefer-dist --optimize-autoloader
@else
    {{ $push_env['composer'] }} install --no-progress --no-interaction
@endif
@endtask

@task('npm-build', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    echo "## Building assets"
    {{ $push_env['npm'] }} ci --silent || {{ $push_env['npm'] }} install --silent
    {{ $push_env['npm'] }} run --silent build
@endtask

@task('migrate', ['on' => 'push', 'confirm' => $confirm($pushing_to_prod)])
    set -e
    cd {{ $push_env['path'] }}
    echo "## Migrating {{ $push_env['name'] }} database"
    {{ $push_env['php'] }} artisan migrate --force --ansi
@endtask

@task('optimize', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    {{ $push_env['php'] }} artisan optimize
@endtask

@task('clear', ['on' => 'push'])
    set -e
    cd {{ $push_env['path'] }}
    {{ $push_env['php'] }} artisan optimize:clear
@endtask

{{--
|--------------------------------------------------------------------------
| Database — dump / transfer / import
|--------------------------------------------------------------------------
--}}

@task('db-dump', ['on' => 'pull'])
    set -e
    mkdir -p {{ $pull_env['dumps'] }}
    echo "## Dumping {{ $pull_env['name'] }} database {{ $pull_env['db']['database'] }}"
    MYSQL_PWD='{{ $pull_env['db']['password'] }}' mysqldump \
        --host={{ $pull_env['db']['host'] }} --port={{ $pull_env['db']['port'] }} \
        --user={{ $pull_env['db']['username'] }} \
        {{ $dump_flags }} \
        {{ $ignore($pull_env['db']['database']) }} \
        {{ $pull_env['db']['database'] }} > {{ $pull_env['dumps'] }}/{{ $dump_latest }}
    cp {{ $pull_env['dumps'] }}/{{ $dump_latest }} {{ $pull_env['dumps'] }}/{{ $dump_stamp }}
    ls -lh {{ $pull_env['dumps'] }}/{{ $dump_latest }}
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
    echo "## Downloading dump from {{ $pull_env['name'] }}"
    scp {{ $scp_flag($pull_env) }} {{ $pull_env['ssh'] }}:{{ $pull_env['dumps'] }}/{{ $dump_latest }} {{ $local['dumps'] }}/{{ $dump_latest }}
    cp {{ $local['dumps'] }}/{{ $dump_latest }} {{ $local['dumps'] }}/{{ $dump_stamp }}
@endtask

{{-- Sends the dump you already have. It never reaches for a fresher one. --}}

@task('db-upload', ['on' => 'local'])
    set -e
@if ($pushing_to_prod)
    echo "## Refusing: dumps are never uploaded to production."
    exit 1
@else
    if [ ! -f {{ $local['dumps'] }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $local['dumps'] }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-pull' first, or 'envoy run db-dump-local'"
        exit 1
    fi
    echo "## Uploading {{ $dump_latest }} to {{ $push_env['name'] }}"
    ssh {{ $ssh_flag($push_env) }} {{ $push_env['ssh'] }} "mkdir -p {{ $push_env['dumps'] }}"
    scp {{ $scp_flag($push_env) }} {{ $local['dumps'] }}/{{ $dump_latest }} {{ $push_env['ssh'] }}:{{ $push_env['dumps'] }}/{{ $dump_latest }}
@endif
@endtask

@task('db-import-local', ['on' => 'local', 'confirm' => $confirm()])
    set -e
    cd {{ $local['path'] }}
    echo "## Rebuilding local schema from migrations on {{ $pull_env['branch'] }}"
    git checkout {{ $pull_env['branch'] }} --quiet
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
| The one guard that matters. Every path that ends in a remote import —
| db-import --prod, db-push --prod, db-sync --prod — comes through here, and
| Blade renders it as a refusal rather than a prompt when the target is prod.
--}}

@task('db-import-remote', ['on' => 'push', 'confirm' => $confirm()])
@if ($pushing_to_prod)
    echo "## Refusing: imports never run on production."
    exit 1
@else
    set -e
    cd {{ $push_env['path'] }}
    if [ ! -f {{ $push_env['dumps'] }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $push_env['name'] }}:{{ $push_env['dumps'] }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-push' to send one, or 'envoy run db-upload' on its own"
        exit 1
    fi
    echo "## Rebuilding {{ $push_env['name'] }} schema and importing {{ $dump_latest }}"
    {{ $push_env['php'] }} artisan migrate:fresh --drop-views --force --quiet
    MYSQL_PWD='{{ $push_env['db']['password'] }}' mysql \
        --host={{ $push_env['db']['host'] }} --port={{ $push_env['db']['port'] }} \
        --user={{ $push_env['db']['username'] }} \
        {{ $push_env['db']['database'] }} < {{ $push_env['dumps'] }}/{{ $dump_latest }}
    {{ $push_env['php'] }} artisan migrate --force --quiet
    {{ $push_env['php'] }} artisan optimize:clear --quiet
@endif
@endtask

{{-- SQLite projects transfer the file itself instead of dumping. --}}

@task('db-pull-sqlite', ['on' => 'local'])
    set -e
    echo "## Downloading {{ $pull_env['name'] }} sqlite database"
    rsync {{ $rsync_opts($pull_env) }} --backup --suffix=.bak \
        {{ $pull_env['ssh'] }}:{{ $pull_env['db']['sqlite'] ?: $pull_env['path'] . '/database/database.sqlite' }} \
        {{ $local['db']['sqlite'] }}
@endtask

@task('db-push-sqlite', ['on' => 'local', 'confirm' => $confirm()])
    set -e
@if ($pushing_to_prod)
    echo "## Refusing: databases are never pushed to production."
    exit 1
@else
    echo "## Uploading local sqlite database to {{ $push_env['name'] }}"
    rsync {{ $rsync_opts($push_env) }} --backup --suffix=.bak \
        {{ $local['db']['sqlite'] }} \
        {{ $push_env['ssh'] }}:{{ $push_env['db']['sqlite'] ?: $push_env['path'] . '/database/database.sqlite' }}
@endif
@endtask

@task('db-nothing-declared', ['on' => 'local'])
    echo "## ENVOY_SYNC_ALL declares no db-pull or db-push, so there is nothing to do."
    echo '##   e.g. ENVOY_SYNC_ALL="db-pull, storage-sync"'
@endtask

{{--
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
--}}

@task('storage-pull', ['on' => 'local'])
    set -e
@if (! $sync_pull_dirs)
    echo "## Nothing is declared to pull — see ENVOY_SYNC_ALL / ENVOY_STORAGE_SYNC in .env, or pass --dir=<path>"
@endif
@foreach ($sync_pull_dirs as $e)
    echo "## {{ $pull_env['name'] }}:{{ $e['path'] }} -> local"
    mkdir -p {{ $local['path'] }}/{{ $e['path'] }}
    rsync {{ $rsync_opts($pull_env) }} {{ $e['opts'] }} \
        {{ $pull_env['ssh'] }}:{{ $pull_env['path'] }}/{{ $e['path'] }}/ \
        {{ $local['path'] }}/{{ $e['path'] }}/
@endforeach
@endtask

@task('storage-push', ['on' => 'local', 'confirm' => $confirm()])
    set -e
@if (! $sync_push_dirs)
    echo "## Nothing is declared to push — see ENVOY_SYNC_ALL / ENVOY_STORAGE_SYNC in .env, or pass --dir=<path>"
@endif
@foreach ($sync_push_dirs as $e)
    echo "## local:{{ $e['path'] }} -> {{ $push_env['name'] }}"
    ssh {{ $ssh_flag($push_env) }} {{ $push_env['ssh'] }} "mkdir -p {{ $push_env['path'] }}/{{ $e['path'] }}"
    rsync {{ $rsync_opts($push_env) }} {{ $e['opts'] }} \
        {{ $local['path'] }}/{{ $e['path'] }}/ \
        {{ $push_env['ssh'] }}:{{ $push_env['path'] }}/{{ $e['path'] }}/
@endforeach
@endtask

{{--
| storage-sync moves files in both declared directions, but only ever against
| one server — the push target, so it can no more write to production without
| --prod than storage-push can.
--}}

@task('storage-sync-pull', ['on' => 'local'])
    set -e
@foreach ($sync_pull_dirs as $e)
    echo "## {{ $push_env['name'] }}:{{ $e['path'] }} -> local"
    mkdir -p {{ $local['path'] }}/{{ $e['path'] }}
    rsync {{ $rsync_opts($push_env) }} {{ $e['opts'] }} \
        {{ $push_env['ssh'] }}:{{ $push_env['path'] }}/{{ $e['path'] }}/ \
        {{ $local['path'] }}/{{ $e['path'] }}/
@endforeach
@endtask

@task('storage-nothing-declared', ['on' => 'local'])
    echo "## No directories are declared, so there is nothing to do."
    echo '##   e.g. ENVOY_SYNC_ALL="db-pull, storage-pull --dir=storage/app/public"'
    echo '##   or   ENVOY_SYNC_ALL="db-pull, storage-sync" with the directories in ENVOY_STORAGE_SYNC'
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
| One Envoy run resolves one server, so pushing the same code to both means
| running this file again for each. The confirmation is asked once, here, and
| --noconfirm keeps the nested runs from asking a second time.
|
| The two merges level the branches first — main into dev, then dev into main —
| so both servers end up on the same code. A conflict stops the run on the
| branch it happened on; resolve it and run code-sync again.
--}}

@task('code-sync', ['on' => 'local', 'confirm' => $confirm()])
    set -e
@if (count($environments) < 2)
    echo "## Refusing: code-sync needs both remotes, and only {{ implode(', ', array_keys($environments)) }} is configured."
    exit 1
@else
    ENVOY=vendor/bin/envoy
    [ -x "$ENVOY" ] || ENVOY="$(command -v envoy)" || { echo "## Cannot find envoy"; exit 1; }
    cd {{ $local['path'] }}
    echo "## Merging {{ $environments['prod']['branch'] }} into {{ $environments['dev']['branch'] }}"
    git checkout {{ $environments['dev']['branch'] }} --quiet
    git merge {{ $environments['prod']['branch'] }} --no-edit --quiet
    echo "## Merging {{ $environments['dev']['branch'] }} into {{ $environments['prod']['branch'] }}"
    git checkout {{ $environments['prod']['branch'] }} --quiet
    git merge {{ $environments['dev']['branch'] }} --no-edit --quiet
    echo "## branch {{ $environments['prod']['branch'] }} -> prod server"
    "$ENVOY" run code-push --prod --noconfirm {{ isset($force) ? '--force' : '' }} {{ isset($nobuild) ? '--nobuild' : '' }}
    echo "## branch {{ $environments['dev']['branch'] }} -> dev server"
    git checkout {{ $environments['dev']['branch'] }} --quiet
    "$ENVOY" run code-push --dev --noconfirm {{ isset($force) ? '--force' : '' }} {{ isset($nobuild) ? '--nobuild' : '' }}
    echo "## Done — you are back on {{ $environments['dev']['branch'] }}"
@endif
@endtask

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
    db-import-local
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
| Local unless you name a remote; production is refused either way. Nothing
| is uploaded: it imports the dump--latest.sql already sitting on the target,
| the one the last db-push put there.
--}}

@story('db-import')
@if ($import_remote)
    db-import-remote
@else
    db-import-local
@endif
@endstory

@story('db-sync')
@if ($sync_db === 'pull')
    db-pull
@elseif ($sync_db === 'push')
    db-push
@else
    db-nothing-declared
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
    storage-sync-pull
@endif
@if ($sync_push_dirs)
    storage-push
@endif
@endstory

{{--
|--------------------------------------------------------------------------
| Sync
|--------------------------------------------------------------------------
|
| The whole ENVOY_SYNC_ALL declaration in one run: the database first, then
| the files, each in the direction that key declares for it. Nothing here
| decides anything on its own — an undeclared half simply says so and moves
| nothing, so on a project that only mirrors files this is storage-sync.
--}}

@story('sync-all')
    db-sync
    storage-sync
@endstory
