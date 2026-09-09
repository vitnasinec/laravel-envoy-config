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
|   envoy run storage-pull         every directory that remote declares, down
|   envoy run storage-sync         every direction that remote declares
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
    use Vitnasinec\EnvoyConfig\Storage;

    $envoy = new Config(

        /*
        | The remote — usually the one server there is.
        |
        | ssh          user@host, or the bare host when ~/.ssh/config knows the
        |              user
        | port         left out, ssh reads the port from ~/.ssh/config; a number
        |              here overrides that, which is not the same as 22
        | path         project root on the server
        | branch       the branch the server runs — db-import borrows it to build
        |              the right schema before importing
        | php          absolute paths for hosts that don't have them on PATH, e.g.
        | composer     php: '/opt/alt/php83/usr/bin/php'
        | npm          composer: 'php ~/code/bin/composer'
        | build        whether deploy and code-push build assets there. Off unless
        |              set, so a project with a front-end build says so once, here,
        |              and no flag overrides it for a run
        | storagePull  which directories come down from this remote, and which go
        | storagePush  up to it — spelled out below, where they sit. Every remote
        |              answers for itself, so the same project can take prod's
        |              uploads down and publish its own fixtures up to dev
        | db           the database there — and which kind of project this is. A
        |              plain name is MySQL; a path ending in .sqlite makes it a
        |              SQLite project, and then db-pull and db-push transfer that
        |              file instead of dumping:
        |
        |                  db: new Database('~/code/stage1/database/database.sqlite'),
        |
        |              Leave db: off every environment on a project that has no
        |              database — a static site, a front end, anything that only
        |              ever ships code. Then no database task exists at all, and
        |              deploy skips the migration.
        |
        |              readOnly: true on an end marks a database nothing may be
        |              written into by hand. Nothing that imports, drops or
        |              rebuilds it is defined, and the story pointed that way
        |              refuses; deploy's migration is the only thing left that
        |              writes to it. Off unless set:
        |
        |                  db: new Database('example_db', readOnly: true, ...),
        |
        |              dumps: on it says where dumps are written at that end.
        |              It defaults to ./storage/envoy — relative, so the one
        |              default means that directory under the project root
        |              wherever it is read, here and on every remote, and the
        |              directory is created with a .gitignore of its own that
        |              keeps every dump out of git. Set it only to write them
        |              somewhere else; a / or ~ path is taken as written:
        |
        |                  db: new Database('example_db', dumps: '~/code/temp', ...),
        |
        | The usernames and passwords are the only thing read from .env, because
        | this file is committed and they are not.
        |
        | More than one server — prod and dev, say — is a list instead, keyed by
        | the name each answers to. The name is the flag that picks it, so every
        | command then has to say which one it means: envoy run deploy --prod.
        | There is no default and no last-used, because a default is how a
        | deploy meant for dev arrives on prod. Everything else is per remote —
        | its own branch, port, build:, db:, readOnly:, and its own two mirror
        | lists — so a read-only prod you take files down from and a dev you
        | publish up to are two entries and nothing more:
        |
        |     remote: [
        |         'prod' => new Environment(
        |             ssh: 'exampleuser@example.pef.czu.cz',
        |             path: '~/code/prod',
        |             branch: 'main',
        |             build: true,
        |             storagePull: [new Storage('storage/app')],
        |             db: new Database(
        |                 database: 'example_prod',
        |                 username: Env::get('PROD_DB_USERNAME'),
        |                 password: Env::get('PROD_DB_PASSWORD'),
        |                 readOnly: true,
        |             ),
        |         ),
        |         'dev' => new Environment(
        |             ssh: 'exampleuser@dev.pef.czu.cz',
        |             path: '~/code/dev',
        |             branch: 'develop',
        |             storagePush: [new Storage('storage/app')],
        |             db: new Database(
        |                 database: 'example_dev',
        |                 username: Env::get('DEV_DB_USERNAME'),
        |                 password: Env::get('DEV_DB_PASSWORD'),
        |             ),
        |         ),
        |     ],
        |
        | That pair is the whole of what storage-sync does: run it against prod
        | and it brings storage/app down, run it against dev and it sends the
        | local one up. Neither remote can be asked for the other's direction,
        | because neither declares it.
        |
        | Give each its own credentials in .env — the prefix is yours to pick,
        | but naming it after the remote is the one that stays readable.
        */

        remote: new Environment(
            ssh: 'exampleuser@example.pef.czu.cz',
            path: '~/code/stage1',
            branch: 'main',
            php: 'php',
            composer: 'composer',
            npm: 'npm',
            build: true,

            /*
            | Which directories move between this remote and here, and which
            | way. Which files a project mirrors is permanent per remote, so
            | it is written down here rather than remembered at the keyboard:
            | these two lists are the only thing the storage commands read.
            | Paths are project-relative, anything not listed is never touched
            | in either direction, and with both lists empty they move nothing
            | at all. Which remote is the one thing left to the command line —
            | and it settles the direction too, since the lists are its own.
            |
            | delete: true makes the destination an exact mirror, which deletes
            | files at the far end that were never here. Off unless asked for.
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
            ),
        ),

        /*
        | Here. Nothing is ever ssh'd to this end, so there is nothing to say
        | about it but the database: the path is where this file sits, and the
        | branch is whichever one you are on right now. Its database has to be
        | the same kind as every
        | remote's — all names, all .sqlite paths, or no db: anywhere — because
        | there is no transfer between two different kinds.
        |
        | With no database it is the whole line:
        |
        |     local: Environment::local(),
        |
        | readOnly: true works here too, and then db-pull refuses instead of
        | db-push — the local database is never imported into either. The
        | mirror lists are not set here either: they say what moves between a
        | remote and here, so they live on the remote.
        */

        local: Environment::local(
            db: new Database(
                database: 'example_db',
                username: Env::get('DB_USERNAME'),
                password: Env::get('DB_PASSWORD'),
            ),
        ),

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
| are local to the imported file and do not reach back out to this one. On a
| project with several remotes $envoy->remote is already the one the flag
| chose, and $envoy->remoteName is what it is called.
--}}
