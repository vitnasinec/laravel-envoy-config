# laravel-envoy-config

One Envoy setup for every project. The tasks live in `vendor` and update with
composer; the project keeps a single **Config object** — the remote or remotes,
the local database, which directories mirror with each remote — and imports the
rest.

```php
$envoy = new Config(
    remote: new Environment(
        ssh: 'me@example.com',
        path: '~/code/stage1',
        storagePull: [new SyncDir('storage/app')],
        ...
    ),
    local: Environment::local(db: new Database('example_db', ...)),
);
```

The only thing read from `.env` is the database usernames and passwords,
because `Envoy.blade.php` is committed and those are not.

## Install

```sh
composer require --dev vitnasinec/laravel-envoy-config
cp vendor/vitnasinec/laravel-envoy-config/stubs/Envoy.blade.php Envoy.blade.php
```

`laravel/envoy` comes with it. Then edit the config block, append the two keys
from `.env.example` to the project's `.env`, and add the dump directory to
`.gitignore`:

```
/storage/envoy
```

A project with no database needs neither of those last two steps — see
[No database](#no-database).

Updating is `composer update vitnasinec/laravel-envoy-config` — the project's
`Envoy.blade.php` is yours and is never touched.

## Conventions

| | |
|---|---|
| Environments | `local`, and one remote — or several, one chosen per run |
| Command names | `<subject>-<verb>`: `code-push`, `db-pull`, `storage-sync` |
| Direction | **pull = remote → local**, **push = local → remote**, same as git |
| What mirrors | declared on the remote it mirrors with, so each remote has its own directions |
| Target | one remote takes no flag; several take one on every command, and there is no default |
| Writes to the remote | always confirm first, and the question names the remote |
| Read-only databases | `readOnly: true` on an end, and nothing that writes to it is defined |
| Configuration | one object, in the project's file, no indirection |
| Secrets | the database usernames and passwords, from `.env`, and nothing else |

## Config

Everything is one `Config` object in the project's `Envoy.blade.php`, above the
`@import` line. The environments, and — on each remote — what mirrors with it.

```php
$envoy = new Config(
    remote: new Environment(
        ssh: 'exampleuser@example.pef.czu.cz',   // or the bare host, if ~/.ssh/config knows the user
        port: null,                              // a number only to override ~/.ssh/config's port
        path: '~/code/stage1',                   // project root on the server
        branch: 'main',                          // the branch the server runs
        dumps: '~/code/temp',                    // where dumps are written there; only MySQL dumps
        php: 'php',                              // absolute paths for hosts that lack them on PATH,
        composer: 'composer',                    //   e.g. '/opt/alt/php83/usr/bin/php'
        npm: 'npm',                              //   or  'php ~/code/bin/composer'
        build: true,                             // build assets there on deploy / code-push
        storagePull: [new SyncDir('storage/app')],   // comes down from this remote
        storagePush: [],                             // goes up to it
        db: new Database(
            database: 'example_db',
            host: '127.0.0.1',
            port: 3306,
            username: Env::get('PROD_DB_USERNAME'),  // .env
            password: Env::get('PROD_DB_PASSWORD'),  // .env
        ),
    ),
    local: Environment::local(
        db: new Database(
            database: 'example_db',
            username: Env::get('DB_USERNAME'),
            password: Env::get('DB_PASSWORD'),
        ),
    ),
);
```

`Environment::local()` works `path`, `dumps` and `branch` out from where the
project sits and which branch you are on, so the local end is just its
database. Pass any of them explicitly to override. It takes no mirror lists:
they say what moves between a remote and here, so they live on the remote — see
[Which way does the data go?](#which-way-does-the-data-go).

`build` belongs to the end that does the building, so it sits on the remote
next to the `npm` that runs it. It is **off by default** — a project with a
front-end build says `build: true` once, in the file, and `deploy` and
`code-push` run `npm-build` from then on. There is no flag that turns it on or
off for a single run; `envoy run npm-build` is there for the one-off.

`database` says which kind of project this is, so there is no separate switch
for it. A plain name is MySQL. A path ending in `.sqlite` is SQLite — `db-pull`
and `db-push` transfer that file instead of dumping, and `host`, `port`,
`username` and `password` go unused:

```php
db: new Database('~/code/stage1/database/database.sqlite'),
```

Every end has to be the same kind — all names, or all `.sqlite` paths. A
mismatch is a typo, not a transfer, and the file refuses to run.

### More than one remote

Usually there is one server and nothing to choose. Sometimes there are two — a
`prod` and a `dev` — and then `remote:` is a list keyed by the name each
answers to:

```php
remote: [
    'prod' => new Environment(
        ssh: 'me@example.com',
        path: '~/code/prod',
        branch: 'main',
        dumps: '~/code/temp',
        build: true,
        storagePull: [new SyncDir('storage/app')],
        db: new Database(
            database: 'example_prod',
            username: Env::get('PROD_DB_USERNAME'),
            password: Env::get('PROD_DB_PASSWORD'),
            readOnly: true,
        ),
    ),
    'dev' => new Environment(
        ssh: 'me@dev.example.com',
        path: '~/code/dev',
        branch: 'develop',
        dumps: '~/code/temp',
        storagePush: [new SyncDir('storage/app')],
        db: new Database(
            database: 'example_dev',
            username: Env::get('DEV_DB_USERNAME'),
            password: Env::get('DEV_DB_PASSWORD'),
        ),
    ),
],
```

**The name is the flag that picks it**, so every command then says which remote
it means:

```sh
envoy run deploy --prod
envoy run db-pull --dev
envoy run storage-sync --prod
```

There is no default and no last-used, and a command without a flag does not
run — it says which flags there are and stops:

```
Envoy: this project has more than one remote, so every command has to say
which one it means: --prod, --dev. For example, envoy run deploy --prod.
```

A default is how a deploy meant for `dev` arrives on `prod`, so there isn't
one. Two flags at once is refused for the same reason. Everything that writes
asks first, and with several remotes the question names the one it is about —
*Migrate the database on prod?* — rather than only the task.

Names are lowercase, and become `--flags`, so they cannot be one Envoy already
uses (`--pretend`, `--continue`, `--force`, `--dry`, …). Everything else is
**per remote**: its own `branch`, `port`, `build:`, `db:` and `readOnly:`, its
own `storagePull:` and `storagePush:`, its own credentials in `.env`. A
read-only `prod` beside a writable `dev` is two entries and nothing more —
`db-push --prod` refuses, `db-push --dev` runs, and the example above sends
`storage-sync --prod` down and `storage-sync --dev` up.

One remote is not a choice, so it takes no flag and nothing above applies. A
single *named* remote (`remote: ['staging' => ...]`) is still one remote and
still takes none; the flags start being required the day a second one is added.

`envoy tasks` only lists what there is and runs nothing, so it needs no flag
either.

### Read-only database

Some databases are never yours to overwrite — a production one you only ever
pull from, a local one seeded by something other than a dump. `readOnly: true`
says so on the end that has it:

```php
db: new Database(
    database: 'example_db',
    username: Env::get('PROD_DB_USERNAME'),
    password: Env::get('PROD_DB_PASSWORD'),
    readOnly: true,
),
```

Then nothing that imports, drops or rebuilds that database is **defined** —
not `db-import-remote`, not `db-push-sqlite` — rather than defined and refused
at the last moment, and the story pointed that way stops with a message instead
of running. On a read-only remote that is `db-push`; on a read-only local it is
`db-pull`. Reads are untouched: `db-dump` and `db-download` still work, since
neither writes anything.

The one thing that still writes to it is `deploy`'s `migrate` — a read-only
database is one no dump may be poured into, not one the schema stops moving
forward on. Each end is marked on its own, and marking both is fine: then the
migration is all that ever touches either.

### No database

Plenty of projects have none — a static site, a front end, anything that only
ever ships code. Leave `db:` off **every** environment and there is nothing
else to say:

```php
$envoy = new Config(
    remote: new Environment(
        ssh: 'me@example.com',
        path: '~/code/stage1',
        branch: 'main',
        storagePull: [new SyncDir('storage/app')],
    ),
    local: Environment::local(),
);
```

Then not one database task is defined — no `db-dump`, no `db-import`, not even
`migrate` — rather than each of them failing when run. `deploy` is `code-push`
plus `composer install`, with the migration left out, and `db-pull` / `db-push`
say the project has no database and stop. Everything else is unchanged.

`dumps:` exists for MySQL dumps alone, so a project without a database — or a
SQLite one — leaves it out too. A MySQL project that forgets it is refused
before anything runs, the same as a missing username. Leaving `db:` on one end
only is refused as well: there is no transfer between a database and no
database. With several remotes every one of them is checked, whichever the
flag would have picked.

| Setting | |
|---|---|
| `storagePull:` `storagePush:` | on each remote: which directories move between it and here, and which way — see [below](#which-way-does-the-data-go) |
| `ignoreTables:` | tables whose data is never carried between environments. Defaults to `Config::IGNORE_TABLES` — migrations, cache, sessions, queues, telescope, pulse. Extend it rather than replacing it: `[...Config::IGNORE_TABLES, 'audits']` |

`branch` matters more than it looks: `db-import` checks that branch out locally
to build the right schema before importing, then puts you back.

## Commands

### Code

| Command | Does |
|---|---|
| `code-push` (`push`) | **Fast deploy.** down → `git push` → remote checks out *your* branch and pulls → build, if the remote's `build:` says so → `optimize` → up. No composer install, no migrations — that is the point of it. Your local branch never moves; the remote is moved to match it. |
| `code-push --force` | Same, but `git add -A` + `commit --amend` + `push --force-with-lease`, and the remote hard-resets to origin. The iterate-on-a-server-only-bug loop. Confirms before the reset. |
| `deploy` | **Full deploy.** Everything `code-push` does, plus `composer install --no-dev --optimize-autoloader` and `migrate` between the pull and the build. The migration confirms, and is skipped altogether on a project with no database. |

`push` is an alias — every flag passes straight through, so
`envoy run push --force --prod` is `code-push --force --prod`.

### Database

| Command | Does |
|---|---|
| `db-dump` | Dumps the remote database into its dump dir — `dump--latest.sql` plus a timestamped copy. Downloads nothing, writes nothing. |
| `db-import` | Imports your local `storage/envoy/dump--latest.sql` into your **local** database: checkout the remote's branch → `migrate:fresh` → import → back to your branch → `migrate`. |
| `db-import-remote` | Drops and rebuilds the remote database, then imports the `dump--latest.sql` already sitting there — the one the last `db-push` uploaded. Uploads nothing; errors if the remote has no dump. |
| `db-pull` | remote → local, end to end: `db-dump` → download → `db-import`. |
| `db-push` | local → remote, end to end: dump local → upload → import on the remote. |

Everything that changes the remote confirms first — `db-import-remote`,
`db-push-sqlite`, `storage-push`, `migrate`, `git-reset`. `db-import-remote` runs
`migrate:fresh` against the remote database, so it is the one to read twice
before answering. `db-upload` only drops a file in the dump dir, so it doesn't ask.

On a project with no database none of these tasks exists, and `db-pull` /
`db-push` say so — see [No database](#no-database). An end marked
`readOnly: true` loses the tasks that write to it the same way — see
[Read-only database](#read-only-database). With several remotes each of these
runs against the one the flag named, and against no other: `db-pull --dev`
dumps `dev` and imports it here, and `prod` is not touched.

SQLite projects transfer the file itself. Point both `database` fields at the
`.sqlite` paths and `db-pull` / `db-push` rsync the file instead of dumping,
keeping a `.bak` at the destination. Nothing else changes.

### Storage

| Command | Does |
|---|---|
| `storage-pull` | rsyncs every directory in that remote's `storagePull:`, remote → local. |
| `storage-push` | rsyncs every directory in that remote's `storagePush:`, local → remote. Always confirms. |
| `storage-sync` | Whichever of the two that remote declares, in one run. |

There is no ad-hoc path flag. What moves is whatever the chosen remote's two
lists name, so a one-off transfer is an edit to the list, not a flag at the
keyboard. Which remote is the one thing the keyboard decides — and since the
lists belong to it, that settles the direction too:

```sh
envoy run storage-pull
envoy run storage-push
envoy run storage-sync --dry
envoy run storage-pull --prod        # when there is more than one remote
```

A remote that declares neither list moves nothing, and says so rather than
failing.

### Building blocks

Each is runnable on its own: `git-push` `git-repush` `git-pull` `git-reset`
`down` `up` `clear` `optimize` `migrate` `composer-install` `npm-build`
`status`, plus `db-dump-local` `db-download` `db-upload` `db-import-remote`.
The `db-` ones and `migrate` are defined only when the project has a database,
and the ones that write to a `readOnly:` end are not defined at all.

## Which way does the data go?

Different per remote, and permanently so — on some, users edit the server and
you mirror it down; on others you author locally and publish upward; on plenty
it's a mix. That belongs in the file, not in your head, and it belongs on the
**remote it is about**, because the same project answers differently for `prod`
and for `dev`. Write it down once, per remote:

```php
'prod' => new Environment(
    // ...
    storagePull: [
        new SyncDir('storage/media'),
    ],
),

'dev' => new Environment(
    // ...
    storagePush: [
        new SyncDir('storage/app', delete: true),
    ],
),
```

Every storage command reads the two lists on the remote it was aimed at:
`storage-pull` pulls what `storagePull:` names, `storage-push` pushes what
`storagePush:` names, and `storage-sync` does whichever of the two that remote
declares. So `storage-sync --prod` above brings `storage/media` down and sends
nothing up, and `storage-sync --dev` sends `storage/app` up and brings nothing
down — each remote can only be asked for the direction it declares. Paths are
project-relative, one directory per entry. **Anything not listed is never
touched in either direction.**

Nothing is assumed anywhere: there is no built-in directory behind any of it,
and no flag adds one, so a remote with both lists empty moves nothing at all.
Files move where you said so and nowhere else.

`Environment::local()` takes neither list — they describe what moves *between*
a remote and here, so putting them on the local end would name directories
nothing ever reads, and the file refuses to run instead.

The database has no such list — `db-pull` and `db-push` say the direction in
their own name, so run the one you mean.

### `delete:`

`delete: true` makes rsync mirror deletions at the destination, and applies to
that entry alone. It is **off by default in both directions** — it deletes
files at the far end that were never here, which is rarely what you meant. Put
it on the one entry that needs it, as above, where `storage/app` is authored
locally and `dev` should mirror it exactly. There is no flag that turns
mirroring on for a run: a command that deletes files at the far end is one you
decide once, in the file, where the next person can read it.

## Flags

| Flag | Effect |
|---|---|
| `--prod` `--dev` … | which remote the command is for. Named after the remotes in the file, and required on every command as soon as there is more than one |
| `--force` | on `code-push` / `deploy`: amend + force-push, hard-reset the remote |
| `--dry` | rsync dry run with `--itemize-changes` |

Envoy stops parsing its own options at the first one it doesn't know, so put
`--pretend`, `--continue` and `--path` *before* any of the above:
`envoy run deploy --pretend --prod`, not the other way round.

## Project-specific tasks

They go below the `@import`, in the project's own file, where an update cannot
reach them. `$envoy` is the only name in scope there — the shorthands the
shared tasks use (`$remote`, `$local`) are local to the imported file:

```blade
@import('vitnasinec/laravel-envoy-config')

@task('dev-seed', ['on' => 'local'])
    cd {{ $envoy->local->path }}
    {{ $envoy->local->php }} artisan db:seed --class=DevSeeder
@endtask

@story('refresh')
    db-pull
    dev-seed
@endstory
```

## Layout

| | |
|---|---|
| `stubs/Envoy.blade.php` | what you copy into a project: the config block, and the import |
| `Envoy.blade.php` | the tasks and stories, imported from `vendor` and never edited |
| `src/Config.php` | the whole configuration, plus everything derived from it |
| `src/Environment.php` | one end of the map — its paths, its build, what mirrors with it, and the ssh / scp / rsync spellings of its port |
| `src/CommandLine.php` | the arguments Envoy was called with, read for which remote a command means |
| `src/Database.php` | one database, whether it is a MySQL schema or a SQLite file, and whether anything may be written into it — `null` on the environments of a project that has none |
| `src/SyncDir.php` | one directory that mirrors |
| `src/Env.php` | the two credential pairs, out of the project's `.env` |

## Notes

- `code-push` moves the *remote* onto your local branch. Your working copy is
  never checked out from under you — the one exception is `db-import`, which
  borrows the remote's branch to build the right schema and puts you back
  afterwards.
- Dumps are **data only**, always. `db-import` runs `migrate:fresh` on the
  remote's branch first, imports, then switches back to your branch and applies
  newer migrations — so the schema comes from the migrations, never from a dump.
- Passwords go through `MYSQL_PWD`, not `--password=…`, so they don't show up
  in `ps` on a shared host. They are the only thing left in `.env`; if the
  usernames are missing on a MySQL project the file refuses to run, rather than
  handing `mysql` an empty `--user=`.
- `set -e` in every task, so a failed `git pull` cannot leave `migrate` and
  `artisan up` running behind it.
- The config is typed objects, not nested arrays — `$envoy->remote->db->password`,
  not `$remote['db']['password']`. Every one of those values is spliced into a
  shell command, and a mistyped array key would have arrived there as an empty
  string; a mistyped property is a fatal error before anything runs.
- Those objects have methods now, which is the point of them being real files.
  Envoy's compiler regex-scans a `.blade.php` for `$name` and prepends
  `$name = isset($name) ? $name : null;` for each one it finds, so a class
  written *inside* the template could not use `$this` — it compiled into
  `$this = …` and died on `Cannot re-assign $this`. In `src/` that limit is
  gone: `isSqlite()`, `ignoreFlags()`, `sshFlag()` and the rest sit on the
  objects they belong to instead of being closures below the config block.
- The remote is chosen from the arguments Envoy was called with, in
  `src/CommandLine.php`, rather than from the `$prod` variable Envoy hands the
  template. It has to be: the `Config` object is built at the top of the
  project's file and every task below it — the project's own included — is
  rendered against the remote it settled on, which is well before a task body
  could read a variable. Same argument, one step earlier.
- `envoy tasks` renders the tasks without running any, so it does not ask which
  remote it is for. Nothing else skips the flag.
- Requires PHP 8.1 for `readonly`.
