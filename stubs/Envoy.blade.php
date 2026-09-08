{{--
|--------------------------------------------------------------------------
| Envoy
|--------------------------------------------------------------------------
|
| Everything you edit is in this file, and everything in this file is config.
| The tasks live in vendor/vitnasinec/laravel-envoy-config, pulled in by the
| last line — update them with composer, never by editing them here.
|
|   envoy run code-push            fast deploy  (alias: push)
|   envoy run deploy               full deploy — composer install and migrate too
|   envoy run db-pull              remote database down to local
|   envoy run storage-pull         every declared directory, remote -> local
|   envoy run storage-sync         every declared directory, both ways
|
| Project-specific tasks go at the bottom, after the import.
|
--}}

@include('vendor/autoload.php')

@setup
    use Vitnasinec\EnvoyConfig\Config;
    use Vitnasinec\EnvoyConfig\Database;
    use Vitnasinec\EnvoyConfig\Env;
    use Vitnasinec\EnvoyConfig\Environment;
    use Vitnasinec\EnvoyConfig\SyncDir;

    $envoy = new Config(

        /*
        | The remote — the one server there is.
        |
        | ssh       user@host, or the bare host when ~/.ssh/config knows the user
        | port      left out, ssh reads the port from ~/.ssh/config; a number
        |           here overrides that, which is not the same as 22
        | path      project root on the server
        | branch    the branch the server runs — db-import borrows it to build
        |           the right schema before importing
        | dumps     where dumps are written there; leave it out on a project
        |           with no database, or a SQLite one, since neither dumps
        | php       absolute paths for hosts that don't have them on PATH, e.g.
        | composer  php: '/opt/alt/php83/usr/bin/php'
        | npm       composer: 'php ~/code/bin/composer'
        | build     whether deploy and code-push build assets there. Off unless
        |           set, so a project with a front-end build says so once, here,
        |           and no flag overrides it for a run
        | db        the database there — and which kind of project this is. A
        |           plain name is MySQL; a path ending in .sqlite makes it a
        |           SQLite project, and then db-pull and db-push transfer that
        |           file instead of dumping:
        |
        |               db: new Database('~/code/stage1/database/database.sqlite'),
        |
        |           Leave db: off both environments entirely on a project that
        |           has no database — a static site, a front end, anything that
        |           only ever ships code. Then no database task exists at all,
        |           and deploy skips the migration.
        |
        | The usernames and passwords are the only thing read from .env, because
        | this file is committed and they are not.
        */

        remote: new Environment(
            ssh: 'exampleuser@example.pef.czu.cz',
            path: '~/code/stage1',
            branch: 'main',
            dumps: '~/code/temp',
            php: 'php',
            composer: 'composer',
            npm: 'npm',
            build: true,
            db: new Database(
                database: 'example_db',
                host: '127.0.0.1',
                port: 3306,
                username: Env::get('PROD_DB_USERNAME'),
                password: Env::get('PROD_DB_PASSWORD'),
            ),
        ),

        /*
        | Here. Nothing is ever ssh'd to this end, so there is nothing to say
        | about it but the database: the path is where this file sits, the dump
        | directory is storage/envoy under it, and the branch is whichever one
        | you are on right now. Its database has to be the same kind as the
        | remote's — two names, two .sqlite paths, or no db: on either end —
        | because there is no transfer between two different kinds.
        |
        | With no database it is the whole line:
        |
        |     local: Environment::local(),
        */

        local: Environment::local(
            db: new Database(
                database: 'example_db',
                username: Env::get('DB_USERNAME'),
                password: Env::get('DB_PASSWORD'),
            ),
        ),

        /*
        | Which directories move, and which way. Which files a project mirrors
        | is permanent per project, so it is written down here rather than
        | remembered at the keyboard: these two lists are the only thing the
        | storage commands read. Paths are project-relative, anything not
        | listed is never touched in either direction, and with both lists
        | empty they move nothing at all.
        |
        | delete: true makes the destination an exact mirror, which deletes
        | files at the far end that were never here. Off unless asked for.
        */

        pull: [
            new SyncDir('storage/app'),
        ],

        push: [
            // new SyncDir('public/uploads', delete: true),
        ],

        /*
        | Tables whose data is never carried between environments. The default
        | covers migrations, cache, sessions, queues, telescope and pulse — add
        | to it rather than replacing it:
        |
        |     ignoreTables: [...Config::IGNORE_TABLES, 'audits'],
        */
    );
@endsetup

@import('vitnasinec/laravel-envoy-config')

{{--
|--------------------------------------------------------------------------
| This project's own tasks
|--------------------------------------------------------------------------
| Per-project quirks — a permission_name virtual-column dance, a DevSeeder,
| a cache to warm — go here, below the import, where a package update cannot
| reach them. They read the config off the object above:
|
|     @task('dev-seed', ['on' => 'local'])
|         cd {{ $envoy->local->path }}
|         {{ $envoy->local->php }} artisan db:seed --class=DevSeeder
|     @endtask
|
| $envoy is the only name in scope here; the shorthands the shared tasks use
| are local to the imported file and do not reach back out to this one.
--}}
