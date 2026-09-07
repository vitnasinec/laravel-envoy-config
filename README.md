# Shared Laravel Envoy template

One `Envoy.blade.php` for every project. Copy it in, edit the **Config block**
at the top — the remote, the local database, which directories mirror — and
that is the setup. The only thing it reads from `.env` is the database
usernames and passwords, because this file is committed and those are not.

## Install

```sh
composer require --dev laravel/envoy
curl -o Envoy.blade.php https://raw.githubusercontent.com/vitnasinec/laravel-envoy-config/main/Envoy.blade.php
```

Then edit the Config block, append the two keys from `.env.example` to the
project's `.env`, and add the dump directory to `.gitignore`:

```
/storage/envoy
```

## Conventions

| | |
|---|---|
| Environments | `local` and one remote — that is the whole map |
| Command names | `<subject>-<verb>`: `code-push`, `db-pull`, `storage-sync` |
| Direction | **pull = remote → local**, **push = local → remote**, same as git |
| Target | there is only one remote, so no command takes a target flag |
| Writes to the remote | always confirm first; `--noconfirm` answers in advance |
| Configuration | hard-coded in the Config block, one place, no indirection |
| Secrets | the database usernames and passwords, from `.env`, and nothing else |

## Config

Everything is in the one block at the top of `Envoy.blade.php`, between the
`Config` banner and `End of config`. Two typed objects, two lists, one flag:

```php
$remote = new EnvoyEnvironment(
    ssh: 'exampleuser@example.pef.czu.cz',   // or the bare host, if ~/.ssh/config knows the user
    port: 22,
    path: '~/code/stage1',                   // project root on the server
    dumps: '~/code/temp',                    // where dumps are written there
    branch: 'main',                          // the branch the server runs
    php: 'php',                              // absolute paths for hosts that lack them on PATH,
    composer: 'composer',                    //   e.g. '/opt/alt/php83/usr/bin/php'
    npm: 'npm',                              //   or  'php ~/code/bin/composer'
    db: new EnvoyDatabase(
        host: '127.0.0.1',
        port: 3306,
        database: 'example_db',
        username: $env('PROD_DB_USERNAME'),  // .env
        password: $env('PROD_DB_PASSWORD'),  // .env
    ),
);
```

`$local` is the same object with `path`, `dumps` and `branch` worked out from
where the file sits and which branch you are on; edit its database, and leave
the rest alone.

`database` says which kind of project this is, so there is no separate switch
for it. A plain name is MySQL. A path ending in `.sqlite` is SQLite — `db-pull`
and `db-push` transfer that file instead of dumping, and `host`, `port`,
`username` and `password` go unused:

```php
db: new EnvoyDatabase(
    host: null,
    port: null,
    database: '~/code/stage1/database/database.sqlite',
    username: null,
    password: null,
),
```

Both ends have to be the same kind — two names, or two `.sqlite` paths. A
mismatch is a typo, not a transfer, and the file refuses to run.

| Setting | |
|---|---|
| `$sync_pull_dirs` `$sync_push_dirs` | which directories move, and which way — see [below](#which-way-does-the-data-go) |
| `$build_assets` | whether `deploy` and `code-push` build on the server; `false` for projects with no front-end build, or that commit built assets. `--build` / `--nobuild` override it for one run |
| `$ignore_tables` | tables whose data is never carried between environments — migrations, cache, sessions, queues, telescope, pulse |

`branch` matters more than it looks: `db-import` checks that branch out locally
to build the right schema before importing, then puts you back.

## Commands

### Code

| Command | Does |
|---|---|
| `code-push` (`push`) | **Fast deploy.** down → `git push` → remote checks out *your* branch and pulls → build → `optimize` → up. No composer install, no migrations — that is the point of it. Your local branch never moves; the remote is moved to match it. |
| `code-push --force` | Same, but `git add -A` + `commit --amend` + `push --force-with-lease`, and the remote hard-resets to origin. The iterate-on-a-server-only-bug loop. Confirms before the reset. |
| `deploy` | **Full deploy.** Everything `code-push` does, plus `composer install --no-dev --optimize-autoloader` and `migrate` between the pull and the build. The migration confirms. |

`push` is an alias — every flag passes straight through, so
`envoy run push --force` is `code-push --force`.

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

SQLite projects transfer the file itself. Point both `database` fields at the
`.sqlite` paths and `db-pull` / `db-push` rsync the file instead of dumping,
keeping a `.bak` at the destination. Nothing else changes.

### Storage

| Command | Does |
|---|---|
| `storage-pull` | rsyncs every directory in `$sync_pull_dirs`, remote → local. |
| `storage-push` | rsyncs every directory in `$sync_push_dirs`, local → remote. Always confirms. |
| `storage-sync` | Both declared directions in one run. |

There is no ad-hoc path flag. What moves is whatever the two lists in the file
name, so a one-off transfer is an edit to the list, not a flag at the keyboard:

```sh
envoy run storage-pull
envoy run storage-push
envoy run storage-sync --dry
```

### Building blocks

Each is runnable on its own: `git-push` `git-repush` `git-pull` `git-reset`
`down` `up` `clear` `optimize` `migrate` `composer-install` `npm-build`
`status`, plus `db-dump-local` `db-download` `db-upload` `db-import-remote`.

## Which way does the data go?

Different per project, and permanently so — on some, users edit the server and
you mirror it down; on others you author locally and publish upward; on plenty
it's a mix. That belongs in the file, not in your head. Write it down once:

```php
$sync_pull_dirs = [
    new EnvoySyncDir('storage/media'),
];

$sync_push_dirs = [
    new EnvoySyncDir('storage/app', delete: true),
];
```

Every storage command reads those two lists: `storage-pull` pulls what the
first names, `storage-push` pushes what the second names, and `storage-sync`
does both in one run. Paths are project-relative, one directory per entry.
**Anything not listed is never touched in either direction.**

Nothing is assumed anywhere: there is no built-in directory behind any of it,
and no flag adds one, so with both lists empty `storage-pull` / `storage-push`
move nothing at all. Files move where you said so and nowhere else.

The database has no such list — `db-pull` and `db-push` say the direction in
their own name, so run the one you mean.

### `delete:`

`delete: true` makes rsync mirror deletions at the destination, and applies to
that entry alone. It is **off by default in both directions** — it deletes
files at the far end that were never here, which is rarely what you meant. Put
it on the one entry that needs it, as above, where `storage/app` is authored
locally and the server should mirror it exactly. There is no flag that turns
mirroring on for a run: a command that deletes files at the far end is one you
decide once, in the file, where the next person can read it.

## Flags

| Flag | Effect |
|---|---|
| `--force` | on `code-push` / `deploy`: amend + force-push, hard-reset the remote |
| `--dry` | rsync dry run with `--itemize-changes` |
| `--build` / `--nobuild` | force or skip `npm-build` in `code-push` / `deploy` |
| `--noconfirm` | answer every confirmation in advance, for unattended runs |

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
- The two environments are typed objects, not nested arrays — `$remote->db->password`,
  not `$remote['db']['password']`. Every one of those values is spliced into a
  shell command, and a mistyped array key would have arrived there as an empty
  string; a mistyped property is a fatal error before anything runs. They are
  plain data holders with **no methods**: Envoy's compiler regex-scans the whole
  file for `$name` and prepends `$name = isset($name) ? $name : null;` for each
  one it finds, so a method body mentioning `$this` compiles into `$this = …`
  and dies on `Cannot re-assign $this`. Anything derived — whether a database is
  SQLite, which `--ignore-table` flags a dump needs — is a closure taking the
  object, below the config block. Needs PHP 8.1 for `readonly`.
- Per-project quirks (a `permission_name` virtual-column dance, a `DevSeeder`,
  extra ignore tables) stay in that project's file as an extra task appended
  below the template — the shared part stays shared.
