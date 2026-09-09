{{--
|--------------------------------------------------------------------------
| The tasks — shared, and not edited per project
|--------------------------------------------------------------------------
|
| This file lives in vendor and is never edited per project. A project's own
| Envoy.blade.php builds one Config object and then imports this package by
| name on its last line — see stubs/Envoy.blade.php, which is that file. Every
| task below reads that object and nothing else.
|
| Environments   local, and one remote — or several, one chosen per run
| Direction      push = local -> remote,  pull = remote -> local
|
| Which directories mirror, and which way, is declared per remote — a project
| can take prod's uploads down and publish its own up to dev — so the storage
| commands read the lists on the remote the flag chose, and no others.
|
| A project with one remote has nothing to choose, so no command takes a flag.
| A project with several — prod and dev — names them, and then every command
| says which one it means: --prod, --dev. There is no default remote, because
| a default is how a deploy meant for dev arrives on prod. Everything that
| writes to a remote asks first, and the question names the remote.
|
| Common commands
|   envoy run code-push            fast deploy  (alias: push)
|   envoy run deploy               full deploy — composer install and migrate too
|   envoy run db-pull              remote database down to local
|   envoy run storage-pull         every directory that remote declares, down
|   envoy run storage-sync         every direction that remote declares
|
--}}

@setup
    /*
    | Everything below reads one object, handed over by the project's file. The
    | short names are for the task bodies; nothing is worked out here that the
    | Config object does not already know how to work out — which remote the
    | command line meant included.
    */

    if (! $envoy instanceof Vitnasinec\EnvoyConfig\Config) {
        throw new RuntimeException(
            'Envoy: no configuration. The project Envoy.blade.php has to build a '
            . '$envoy = new Vitnasinec\EnvoyConfig\Config(...) before importing '
            . 'vitnasinec/laravel-envoy-config.'
        );
    }

    /*
    | The remote this run is aimed at: the object, the name that selects it,
    | and that name as it reads mid-sentence — a project that never named its
    | one remote calls it "the remote" there.
    */

    $remote       = $envoy->remote;
    $remote_name  = $envoy->remoteName;
    $remote_label = $envoy->remoteLabel();

    $local  = $envoy->local;
    $has_db = $envoy->hasDatabase();
    $sqlite = $envoy->isSqlite();

    /*
    | The branch this working copy is on, asked of git rather than declared:
    | it is wherever you are standing right now, and the code tasks move a
    | remote onto it. The far end is never assumed to be anywhere — db-import
    | is the only task that cares, and it asks over ssh when it runs.
    |
    | These are the tasks that would use it, so these are the ones a detached
    | head refuses. The rest never ask what you are standing on, and a working
    | copy with no branch to name is no reason to stop them.
    */

    $local_branch = $envoy->localBranch();

    $envoy->requireBranch([
        'deploy', 'code-push', 'push', 'git-push', 'git-repush', 'git-pull',
        'git-reset', 'db-pull', 'db-import',
    ]);

    /*
    | A remote that names the branch it deploys from takes no other, and says
    | so here — before the story's first task takes the site down, rather than
    | at the checkout it would refuse. These are the names that end in one.
    */

    $envoy->guardBranch(['deploy', 'code-push', 'push', 'git-pull', 'git-reset']);

    /* A read-only database is one nothing may be written into by hand. */

    $write_remote = $envoy->canWriteRemote();
    $write_local  = $envoy->canWriteLocal();

    /*
    | What mirrors with this remote, and which way. Both lists hang off the
    | remote, so storage-sync on a prod that only declares storagePull pulls
    | and nothing else, and on a dev that only declares storagePush pushes.
    */

    $storage_pull = $remote->storagePull;
    $storage_push = $remote->storagePush;

    /* Transfer helpers — one place that knows about non-standard SSH ports. */

    $ssh_flag   = $remote->sshFlag();
    $scp_flag   = $remote->scpFlag();
    $rsync_opts = $envoy->rsyncOpts(isset($dry));

    /*
    | Database. A dump directory holds two dumps and no more, and the shell
    | that keeps it that way is $envoy->rotateDumps() — see the section below.
    */

    $dump_latest = Vitnasinec\EnvoyConfig\Config::DUMP_LATEST;
    $dump_flags  = Vitnasinec\EnvoyConfig\Config::DUMP_FLAGS;
@endsetup

@servers($envoy->servers())

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
    echo "## {{ $remote_name }} — {{ $remote->ssh }}:{{ $remote->path }}"
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
    echo "## Pushing {{ $local_branch }} to origin"
    git push --quiet
@endtask

@task('git-repush', ['on' => 'local'])
    set -e
    echo "## Amending last commit on {{ $local_branch }} and force pushing"
    git add -A
    git commit --amend --no-edit --quiet
    git push --force-with-lease --quiet
@endtask

{{-- The remote is moved onto your local branch; your branch never moves. --}}

@task('git-pull', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    echo "## Checking out {{ $local_branch }} on {{ $remote_label }} and pulling"
    git fetch --quiet
    git checkout {{ $local_branch }} --quiet
    git pull --quiet
@endtask

@task('git-reset', ['on' => 'remote', 'confirm' => $envoy->confirm('Discard local commits and hard reset to origin')])
    set -e
    cd {{ $remote->path }}
    echo "## Discarding local commits on {{ $remote_label }} and resetting to origin"
    git fetch --quiet
    git checkout {{ $local_branch }} --quiet
    git reset --hard "origin/{{ $local_branch }}" --quiet
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
    echo "## Installing composer dependencies on {{ $remote_label }}"
    {{ $remote->composer }} install --no-progress --no-interaction --no-dev --prefer-dist --optimize-autoloader
@endtask

@task('npm-build', ['on' => 'remote'])
    set -e
    cd {{ $remote->path }}
    echo "## Building assets on {{ $remote_label }}"
    {{ $remote->npm }} ci --silent || {{ $remote->npm }} install --silent
    {{ $remote->npm }} run --silent build
@endtask

@if ($has_db)
@task('migrate', ['on' => 'remote', 'confirm' => $envoy->confirm('Migrate the database')])
    set -e
    cd {{ $remote->path }}
    echo "## Migrating the {{ $remote_name }} database"
    {{ $remote->php }} artisan migrate --force --ansi
@endtask
@endif

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
| A project can have no database at all — db: left off every environment —
| and then none of these tasks is defined, and the two stories say so instead.
|
| An end marked readOnly: true is the same idea, one end at a time: nothing
| that imports, drops or rebuilds that database is defined, and its story
| refuses. Dumping and downloading read it and are left alone, and so is the
| deploy migration, which is the only thing that still writes to it.
|
| Read-only is per remote, so which of these tasks exists depends on the
| remote the flag chose — on a read-only prod and a writable dev, db-push is
| refused with --prod and runs with --dev.
|
| Where dumps are written is set on the database, dumps:, and defaults to
| ./storage/envoy — relative, so it is that directory under the project root
| at whichever end is writing, here and on every remote alike. The directory
| is made when it is first needed, with a .gitignore of its own that ignores
| everything in it, so no dump is ever committed at either end.
|
| A dump directory keeps two dumps: dump--latest.sql, and dump--previous.sql,
| which is the dump that was the latest one until now. Everything that writes
| a dump rotates the directory first — the latest becomes the previous, and
| every other dump there is deleted — so there is always one dump to fall back
| to and never a directory of them to clear out by hand.
--}}

@if ($has_db)

@task('db-dump', ['on' => 'remote'])
    set -e
    {{ $envoy->rotateDumps($remote->dumps()) }}
    echo "## Dumping the {{ $remote_name }} database {{ $remote->db->database }}"
    MYSQL_PWD='{{ $remote->db->password }}' mysqldump \
        {{ $remote->db->connectFlags() }} \
        {{ $dump_flags }} \
        {{ $envoy->ignoreFlags($remote->db) }} \
        {{ $remote->db->database }} > {{ $remote->dumps() }}/{{ $dump_latest }}
    ls -lh {{ $remote->dumps() }}/dump--*.sql
@endtask

@task('db-dump-local', ['on' => 'local'])
    set -e
    {{ $envoy->rotateDumps($local->dumps()) }}
    echo "## Dumping local database {{ $local->db->database }}"
    MYSQL_PWD='{{ $local->db->password }}' mysqldump \
        {{ $local->db->connectFlags() }} \
        {{ $dump_flags }} \
        {{ $envoy->ignoreFlags($local->db) }} \
        {{ $local->db->database }} > {{ $local->dumps() }}/{{ $dump_latest }}
    ls -lh {{ $local->dumps() }}/dump--*.sql
@endtask

@task('db-download', ['on' => 'local'])
    set -e
    {{ $envoy->rotateDumps($local->dumps()) }}
    echo "## Downloading the dump from {{ $remote_label }}"
    scp {{ $scp_flag }} {{ $remote->ssh }}:{{ $remote->dumps() }}/{{ $dump_latest }} {{ $local->dumps() }}/{{ $dump_latest }}
@endtask

{{-- Sends the dump you already have. It never reaches for a fresher one. --}}

@task('db-upload', ['on' => 'local'])
    set -e
    if [ ! -f {{ $local->dumps() }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $local->dumps() }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-pull' first, or 'envoy run db-dump-local'"
        exit 1
    fi
    echo "## Uploading {{ $dump_latest }} to {{ $remote_label }}"
    ssh {{ $ssh_flag }} {{ $remote->ssh }} "{{ $envoy->rotateDumps($remote->dumps()) }}"
    scp {{ $scp_flag }} {{ $local->dumps() }}/{{ $dump_latest }} {{ $remote->ssh }}:{{ $remote->dumps() }}/{{ $dump_latest }}
@endtask

@if ($write_local)

@task('db-import', ['on' => 'local', 'confirm' => true])
    set -e
    cd {{ $local->path }}
    remote_branch=$(ssh {{ $ssh_flag }} {{ $remote->ssh }} "cd {{ $remote->path }} && git rev-parse --abbrev-ref HEAD")
    if [ "$remote_branch" = HEAD ]; then
        echo "## {{ $remote_label }} is on a detached HEAD, so there is no branch to build the schema from"
        echo "##   check one out there, or run 'envoy run git-pull' to put it back on yours"
        exit 1
    fi
    echo "## Rebuilding local schema from migrations on $remote_branch, which {{ $remote_label }} is on"
    git checkout "$remote_branch" --quiet
    {{ $local->php }} artisan migrate:fresh --drop-views --force --quiet
    echo "## Importing {{ $dump_latest }}"
    MYSQL_PWD='{{ $local->db->password }}' mysql \
        {{ $local->db->connectFlags() }} \
        {{ $local->db->database }} < {{ $local->dumps() }}/{{ $dump_latest }}
    echo "## Back to {{ $local_branch }}, applying newer migrations"
    git checkout {{ $local_branch }} --quiet
    {{ $local->php }} artisan migrate --force --quiet
    {{ $local->php }} artisan optimize:clear --quiet
@endtask

@endif

@if ($write_remote)

{{--
| Drops and rebuilds the chosen remote's database, then imports the
| dump--latest.sql already sitting there — the one the last db-push uploaded.
| It uploads nothing itself, and it always confirms, by name.
--}}

@task('db-import-remote', ['on' => 'remote', 'confirm' => $envoy->confirm('Drop the database, rebuild it from migrations and import the dump')])
    set -e
    cd {{ $remote->path }}
    if [ ! -f {{ $remote->dumps() }}/{{ $dump_latest }} ]; then
        echo "## No dump at {{ $remote->dumps() }}/{{ $dump_latest }}"
        echo "##   run 'envoy run db-push' to send one, or 'envoy run db-upload' on its own"
        exit 1
    fi
    echo "## Rebuilding the {{ $remote_name }} schema and importing {{ $dump_latest }}"
    {{ $remote->php }} artisan migrate:fresh --drop-views --force --quiet
    MYSQL_PWD='{{ $remote->db->password }}' mysql \
        {{ $remote->db->connectFlags() }} \
        {{ $remote->db->database }} < {{ $remote->dumps() }}/{{ $dump_latest }}
    {{ $remote->php }} artisan migrate --force --quiet
    {{ $remote->php }} artisan optimize:clear --quiet
@endtask

@endif

{{-- SQLite projects transfer the file itself instead of dumping. --}}

@if ($write_local)
@task('db-pull-sqlite', ['on' => 'local'])
    set -e
    echo "## Downloading the sqlite database from {{ $remote_label }}"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $remote->ssh }}:{{ $remote->db->database }} \
        {{ $local->db->database }}
@endtask
@endif

@if ($write_remote)
@task('db-push-sqlite', ['on' => 'local', 'confirm' => $envoy->confirm('Overwrite the sqlite database')])
    set -e
    echo "## Uploading the local sqlite database to {{ $remote_label }}"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $local->db->database }} \
        {{ $remote->ssh }}:{{ $remote->db->database }}
@endtask
@endif

{{-- What a read-only end says instead, and it stops the story it is in. --}}

@if (! $write_local)
@task('db-local-read-only', ['on' => 'local'])
    echo "## The local database {{ $local->db->database }} is read-only, so nothing may be written into it."
    echo '##   drop readOnly: true from the local db: in Envoy.blade.php to allow it'
    exit 1
@endtask
@endif

@if (! $write_remote)
@task('db-remote-read-only', ['on' => 'local'])
    echo "## The {{ $remote_name }} database {{ $remote->db->database }} is read-only, so nothing may be written into it."
    echo '##   only the deploy migration writes to it; drop readOnly: true to allow the rest'
    exit 1
@endtask
@endif

@else

@task('db-not-configured', ['on' => 'local'])
    echo "## This project has no database, so there is nothing to do."
    echo '##   give db: to every environment in Envoy.blade.php to add one'
@endtask

@endif

{{--
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
| No path flag reaches in here. The chosen remote's two lists are the whole
| story: what moves, which way, and whether the far end mirrors deletions is
| decided in the project's file and nowhere else. Which remote it moves to or
| from is the only thing the command line decides — and, because the lists
| hang off that remote, the direction is decided along with it.
--}}

@task('storage-pull', ['on' => 'local'])
    set -e
@if (! $storage_pull)
    echo "## Nothing is declared to pull from {{ $remote_label }} — see its storagePull: list"
@endif
@foreach ($storage_pull as $e)
    echo "## {{ $remote_name }}:{{ $e->path }} -> local"
    mkdir -p {{ $local->path }}/{{ $e->path }}
    rsync {{ $rsync_opts }} {{ $e->rsyncFlags() }} \
        {{ $remote->ssh }}:{{ $remote->path }}/{{ $e->path }}/ \
        {{ $local->path }}/{{ $e->path }}/
@endforeach
@endtask

@task('storage-push', ['on' => 'local', 'confirm' => $envoy->confirm('Push every declared directory')])
    set -e
@if (! $storage_push)
    echo "## Nothing is declared to push to {{ $remote_label }} — see its storagePush: list"
@endif
@foreach ($storage_push as $e)
    echo "## local:{{ $e->path }} -> {{ $remote_name }}"
    ssh {{ $ssh_flag }} {{ $remote->ssh }} "mkdir -p {{ $remote->path }}/{{ $e->path }}"
    rsync {{ $rsync_opts }} {{ $e->rsyncFlags() }} \
        {{ $local->path }}/{{ $e->path }}/ \
        {{ $remote->ssh }}:{{ $remote->path }}/{{ $e->path }}/
@endforeach
@endtask

@task('storage-nothing-declared', ['on' => 'local'])
    echo "## No directories are declared on {{ $remote_label }}, so there is nothing to do."
    echo '##   e.g. storagePull: [new Storage("storage/app/public")],'
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
@if ($remote->build)
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
@if ($has_db)
    migrate
@endif
@if ($remote->build)
    npm-build
@endif
    optimize
    up
@endstory

{{--
| An alias for the command typed the most. A story is only a list of names,
| so it carries every flag straight through: push --prod is code-push --prod.
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
@if (! $has_db)
    db-not-configured
@elseif (! $write_local)
    db-local-read-only
@elseif ($sqlite)
    db-pull-sqlite
@else
    db-dump
    db-download
    db-import
@endif
@endstory

{{--
| Sends the dump that is already in the local dump directory — it never makes
| a fresh one, so what lands on the remote is whatever dump--latest.sql you
| have, from a db-pull or a db-dump-local you ran yourself. Run db-dump-local
| first when you mean to send the local database as it stands now.
--}}

@story('db-push')
@if (! $has_db)
    db-not-configured
@elseif (! $write_remote)
    db-remote-read-only
@elseif ($sqlite)
    db-push-sqlite
@else
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
@if (! $storage_pull && ! $storage_push)
    storage-nothing-declared
@endif
@if ($storage_pull)
    storage-pull
@endif
@if ($storage_push)
    storage-push
@endif
@endstory
