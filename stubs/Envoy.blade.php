{{--
|--------------------------------------------------------------------------
| Envoy
|--------------------------------------------------------------------------
|
| This file is config. The tasks live in vendor/vitnasinec/laravel-envoy-config,
| pulled in by the import below — update them with composer, not by hand.
|
|   envoy run code-push            fast deploy  (alias: push)
|   envoy run deploy               full deploy — composer install and migrate too
|   envoy run db-pull              remote database down to local
|   envoy run storage-pull         every directory that remote declares, down
|   envoy run storage-sync         every direction that remote declares
|
--}}

@include('vendor/autoload.php')

@setup
    use Vitnasinec\EnvoyConfig\Config;
    use Vitnasinec\EnvoyConfig\Database;
    use Vitnasinec\EnvoyConfig\Env;
    use Vitnasinec\EnvoyConfig\Environment;
    use Vitnasinec\EnvoyConfig\Storage;

    $envoy = new Config(

        /*
        | The remote. Only usernames and passwords come from .env, because this
        | file is committed and they are not.
        |
        | Several servers is a list keyed by name, and then every command says
        | which one it means: envoy run deploy --prod. There is no default.
        |
        |     remote: [
        |         'prod' => new Environment(ssh: ..., path: ...),
        |         'dev' => new Environment(ssh: ..., path: ...),
        |     ],
        */

        remote: new Environment(
            ssh: 'exampleuser@example.pef.czu.cz',
            path: '~/code/stage1',
            build: true,
            // deployFrom: 'main',   // refuse a deploy from any other branch

            /*
            | Which directories move between this remote and here, and which
            | way. Paths are project-relative; anything not listed is never
            | touched. delete: true makes the destination an exact mirror.
            */

            storagePull: [
                new Storage('storage/app'),
            ],

            storagePush: [
                // new Storage('public/uploads', delete: true),
            ],

            db: new Database(
                database: 'example_db',
                host: '127.0.0.1',
                port: 3306,
                username: Env::get('PROD_DB_USERNAME'),
                password: Env::get('PROD_DB_PASSWORD'),
                readOnly: true,
            ),
        ),

        /*
        | Here. A plain database name is MySQL, a path ending in .sqlite makes
        | it a SQLite project, and no db: anywhere means no database tasks at
        | all — but every end has to be the same kind. readOnly: true marks a
        | database nothing may write into by hand.
        */

        local: new Environment(
            path: __DIR__,
            db: new Database(
                database: 'example_db',
                username: Env::get('DB_USERNAME'),
                password: Env::get('DB_PASSWORD'),
            ),
        ),

        /*
        | Tables whose data is never carried between environments — add to the
        | default rather than replacing it:
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
| They go below the import, where a package update cannot reach them, and
| read the config off $envoy — the only name in scope here:
|
|     @task('dev-seed', ['on' => 'local'])
|         cd {{ $envoy->local->path }}
|         {{ $envoy->local->php }} artisan db:seed --class=DevSeeder
|     @endtask
--}}
