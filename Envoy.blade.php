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
| Environments   local and one remote — that is the whole map
| Direction      push = local -> remote,  pull = remote -> local
|
| There is no server to choose, so no command takes a target flag. Everything
| that writes to the remote asks first.
|
| Common commands
|   envoy run code-push            fast deploy  (alias: push)
|   envoy run deploy               full deploy — composer install and migrate too
|   envoy run db-pull              remote database down to local
|   envoy run storage-pull         every declared directory, remote -> local
|   envoy run storage-sync         every declared directory, both ways
|
--}}

@setup
    /*
    | Everything below reads one object, handed over by the project's file. The
    | short names are for the task bodies; nothing is worked out here that the
    | Config object does not already know how to work out.
    */

    if (! $envoy instanceof Vitnasinec\EnvoyConfig\Config) {
        throw new RuntimeException(
            'Envoy: no configuration. The project Envoy.blade.php has to build a '
            . '$envoy = new Vitnasinec\EnvoyConfig\Config(...) before importing '
            . 'vitnasinec/laravel-envoy-config.'
        );
    }

    $remote = $envoy->remote;
    $local  = $envoy->local;
    $has_db = $envoy->hasDatabase();
    $sqlite = $envoy->isSqlite();

    /* A read-only database is one nothing may be written into by hand. */

    $write_remote = $envoy->canWriteRemote();
    $write_local  = $envoy->canWriteLocal();

    /* Transfer helpers — one place that knows about non-standard SSH ports. */

    $ssh_flag   = $remote->sshFlag();
    $scp_flag   = $remote->scpFlag();
    $rsync_opts = $envoy->rsyncOpts(isset($dry));

    /* Database. One timestamp for the whole run, not one per task. */

    $dump_latest = Vitnasinec\EnvoyConfig\Config::DUMP_LATEST;
    $dump_stamp  = $envoy->dumpStamp();
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

@task('git-reset', ['on' => 'remote', 'confirm' => true])
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

@if ($has_db)
@task('migrate', ['on' => 'remote', 'confirm' => true])
    set -e
    cd {{ $remote->path }}
    echo "## Migrating the remote database"
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
| A project can have no database at all — db: left off both environments —
| and then none of these tasks is defined, and the two stories say so instead.
|
| An end marked readOnly: true is the same idea, one end at a time: nothing
| that imports, drops or rebuilds that database is defined, and its story
| refuses. Dumping and downloading read it and are left alone, and so is the
| deploy migration, which is the only thing that still writes to it.
--}}

@if ($has_db)

@task('db-dump', ['on' => 'remote'])
    set -e
    mkdir -p {{ $remote->dumps }}
    echo "## Dumping remote database {{ $remote->db->database }}"
    MYSQL_PWD='{{ $remote->db->password }}' mysqldump \
        {{ $remote->db->connectFlags() }} \
        {{ $dump_flags }} \
        {{ $envoy->ignoreFlags($remote->db) }} \
        {{ $remote->db->database }} > {{ $remote->dumps }}/{{ $dump_latest }}
    cp {{ $remote->dumps }}/{{ $dump_latest }} {{ $remote->dumps }}/{{ $dump_stamp }}
    ls -lh {{ $remote->dumps }}/{{ $dump_latest }}
@endtask

@task('db-dump-local', ['on' => 'local'])
    set -e
    mkdir -p {{ $local->dumps }}
    echo "## Dumping local database {{ $local->db->database }}"
    MYSQL_PWD='{{ $local->db->password }}' mysqldump \
        {{ $local->db->connectFlags() }} \
        {{ $dump_flags }} \
        {{ $envoy->ignoreFlags($local->db) }} \
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

@if ($write_local)

@task('db-import', ['on' => 'local', 'confirm' => true])
    set -e
    cd {{ $local->path }}
    echo "## Rebuilding local schema from migrations on {{ $remote->branch }}"
    git checkout {{ $remote->branch }} --quiet
    {{ $local->php }} artisan migrate:fresh --drop-views --force --quiet
    echo "## Importing {{ $dump_latest }}"
    MYSQL_PWD='{{ $local->db->password }}' mysql \
        {{ $local->db->connectFlags() }} \
        {{ $local->db->database }} < {{ $local->dumps }}/{{ $dump_latest }}
    echo "## Back to {{ $local->branch }}, applying newer migrations"
    git checkout {{ $local->branch }} --quiet
    {{ $local->php }} artisan migrate --force --quiet
    {{ $local->php }} artisan optimize:clear --quiet
@endtask

@endif

@if ($write_remote)

{{--
| Drops and rebuilds the remote database, then imports the dump--latest.sql
| already sitting there — the one the last db-push uploaded. It uploads
| nothing itself, and it always confirms.
--}}

@task('db-import-remote', ['on' => 'remote', 'confirm' => true])
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
        {{ $remote->db->connectFlags() }} \
        {{ $remote->db->database }} < {{ $remote->dumps }}/{{ $dump_latest }}
    {{ $remote->php }} artisan migrate --force --quiet
    {{ $remote->php }} artisan optimize:clear --quiet
@endtask

@endif

{{-- SQLite projects transfer the file itself instead of dumping. --}}

@if ($write_local)
@task('db-pull-sqlite', ['on' => 'local'])
    set -e
    echo "## Downloading the remote sqlite database"
    rsync {{ $rsync_opts }} --backup --suffix=.bak \
        {{ $remote->ssh }}:{{ $remote->db->database }} \
        {{ $local->db->database }}
@endtask
@endif

@if ($write_remote)
@task('db-push-sqlite', ['on' => 'local', 'confirm' => true])
    set -e
    echo "## Uploading local sqlite database to the remote"
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
    echo "## The remote database {{ $remote->db->database }} is read-only, so nothing may be written into it."
    echo '##   only the deploy migration writes to it; drop readOnly: true to allow the rest'
    exit 1
@endtask
@endif

@else

@task('db-not-configured', ['on' => 'local'])
    echo "## This project has no database, so there is nothing to do."
    echo '##   give db: to both environments in Envoy.blade.php to add one'
@endtask

@endif

{{--
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
| No flag reaches in here. The two lists on the Config object are the whole
| story: what moves, which way, and whether the far end mirrors deletions is
| decided in the project's file and nowhere else.
--}}

@task('storage-pull', ['on' => 'local'])
    set -e
@if (! $envoy->pull)
    echo '## Nothing is declared to pull — see the pull: list in Envoy.blade.php'
@endif
@foreach ($envoy->pull as $e)
    echo "## remote:{{ $e->path }} -> local"
    mkdir -p {{ $local->path }}/{{ $e->path }}
    rsync {{ $rsync_opts }} {{ $e->rsyncFlags() }} \
        {{ $remote->ssh }}:{{ $remote->path }}/{{ $e->path }}/ \
        {{ $local->path }}/{{ $e->path }}/
@endforeach
@endtask

@task('storage-push', ['on' => 'local', 'confirm' => true])
    set -e
@if (! $envoy->push)
    echo '## Nothing is declared to push — see the push: list in Envoy.blade.php'
@endif
@foreach ($envoy->push as $e)
    echo "## local:{{ $e->path }} -> remote"
    ssh {{ $ssh_flag }} {{ $remote->ssh }} "mkdir -p {{ $remote->path }}/{{ $e->path }}"
    rsync {{ $rsync_opts }} {{ $e->rsyncFlags() }} \
        {{ $local->path }}/{{ $e->path }}/ \
        {{ $remote->ssh }}:{{ $remote->path }}/{{ $e->path }}/
@endforeach
@endtask

@task('storage-nothing-declared', ['on' => 'local'])
    echo "## No directories are declared, so there is nothing to do."
    echo '##   e.g. pull: [new SyncDir("storage/app/public")],'
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

@story('db-push')
@if (! $has_db)
    db-not-configured
@elseif (! $write_remote)
    db-remote-read-only
@elseif ($sqlite)
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
@if (! $envoy->pull && ! $envoy->push)
    storage-nothing-declared
@endif
@if ($envoy->pull)
    storage-pull
@endif
@if ($envoy->push)
    storage-push
@endif
@endstory
