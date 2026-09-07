# Shared Laravel Envoy template

One `Envoy.blade.php` for every project. Nothing project-specific lives in the
file — copy it in, add the `.env` keys from `.env.example`, done.

## Install

```sh
composer require --dev laravel/envoy
curl -o Envoy.blade.php https://raw.githubusercontent.com/vitnasinec/laravel-envoy-config/main/Envoy.blade.php
```

Then append the keys from `.env.example` to the project's `.env`, and add
the dump directory to `.gitignore`:

```
/storage/envoy
```

## Conventions

| | |
|---|---|
| Environments | always `local`, `dev`, `prod` — no `czu`, no `remote-2` |
| Command names | `<subject>-<verb>`: `code-push`, `db-pull`, `storage-sync` |
| Direction | **pull = remote → local**, **push = local → remote**, same as git |
| Target | **pushes go to dev, pulls come from prod** — the direction picks the server |
| Overriding it | `--prod`, `--dev` or `--on=prod` pins both directions for one run |
| Source of truth | the directories are declared per project in `ENVOY_STORAGE_SYNC`, never remembered |
| Env keys | `<ENV>_<THING>`, e.g. `PROD_DB_PASSWORD` (not `DB_PROD_PASSWORD`) |

Production is the source of truth and dev is the safe place to write, so the
direction of a command already implies which server it means. `storage-push`
goes to dev; `storage-pull` comes from prod; neither needs a flag to do the
safe thing. Projects with a single remote use it for both directions.

## Commands

### Code

| Command | Does |
|---|---|
| `code-push` (`push`) | **Fast deploy → dev.** down → `git push` → remote checks out *your* branch and pulls → build → `optimize` → up. No composer install, no migrations — that is the point of it. Your local branch never moves; the remote is moved to match it. |
| `code-push --force` | Same, but `git add -A` + `commit --amend` + `push --force-with-lease`, and the remote hard-resets to origin. The iterate-on-a-server-only-bug loop. |
| `code-sync` (`sync`) | **Both remotes, one run.** Confirms once, then merges `main` into `dev` and `dev` into `main`, deploys `main` to production, deploys `dev` to the dev server, and leaves you on `dev`. Both branches end up level; a merge conflict stops the run before anything is deployed. |
| `deploy` | **Full deploy.** Everything `code-push` does, plus `composer install` and `migrate` between the pull and the build. Prod gets `--no-dev --optimize-autoloader`. |

All four take `--prod` to target production, and confirm before touching it.
`push` and `sync` are aliases — every flag passes straight through, so
`envoy run push --prod --force` is `code-push --prod --force`.

### Database

| Command | Does |
|---|---|
| `db-dump` | Dumps the **production** database into prod's dump dir — `dump--latest.sql` plus a timestamped copy. Downloads nothing, writes nothing. |
| `db-import` | Imports your local `storage/envoy/dump--latest.sql` into your **local** database: checkout prod's branch → `migrate:fresh` → import → back to your branch → `migrate`. |
| `db-import --dev` | Imports the `dump--latest.sql` already on dev — the one the last `db-push` uploaded — into dev's database. Uploads nothing; errors if dev has no dump. |
| `db-pull` | prod → local, end to end: `db-dump` → download → `db-import`. |
| `db-push` | local → **dev**, end to end: dump local → upload → import on dev. |

**Imports never run on production.** `db-import --prod` and `db-push --prod`
exit non-zero. This is not a confirmation prompt you can click through — when
the target is prod the task body *is* the refusal, and no `mysql` command is
rendered into it at all.

SQLite projects transfer the file itself; `db-pull` / `db-push` detect
`DB_CONNECTION=sqlite` and rsync the `.sqlite` file instead of dumping.

### Storage

| Command | Does |
|---|---|
| `storage-pull` | rsyncs every directory declared by a `storage-pull` entry, from **prod** down to local. |
| `storage-push` | rsyncs every directory declared by a `storage-push` entry, from local up to **dev**. Always confirms. |
| `storage-push --dir=path` | Ad-hoc: transfers exactly that project-relative path, declared or not. One path per run. Works on `storage-pull` too. |
| `storage-sync` | Both declared directions in one run, both against **dev** — so like `storage-push`, it can never write to production unflagged. |

`--dir` paths are relative to the project root and are not confined to
`storage/`, so `--dir=public/uploads` works:

```sh
envoy run storage-push --dir=storage/app/public
envoy run storage-push --dir=public/uploads --delete
envoy run storage-pull --dir=storage/media --dry
```

### Building blocks

Each is runnable on its own: `git-push` `git-repush` `git-pull` `git-reset`
`down` `up` `clear` `optimize` `migrate` `composer-install` `npm-build`
`status`, plus `db-dump-local` `db-download` `db-upload` `db-import-local`
`db-import-remote`.

## Which way does the data go?

Different per project, and permanently so — on some, users edit production and
you mirror it down; on others you author locally and publish upward; on plenty
it's a mix. That belongs in config, not in your head. Declare it once:

```dotenv
ENVOY_STORAGE_SYNC="storage-push --dir=storage/app, storage-pull --dir=storage/media"
```

There is no second syntax to learn: an entry is the command you would have
typed, with the flags you would have typed. Entries are comma separated, each
one a `storage-pull` or `storage-push` naming the one directory it moves with
`--dir=`, project-relative. One directory per entry; repeat the command for a
second one. **Anything not listed is never touched in either direction.**

Every storage command reads the result: `storage-pull` pulls what the pull
entries name, `storage-push` pushes what the push entries name, and
`storage-sync` does both in one run.

Nothing is assumed anywhere: there is no built-in directory behind any of it,
so with nothing declared `storage-pull` / `storage-push` move nothing until you
pass `--dir`. Files move where you said so and nowhere else.

The database has no such key — `db-pull` and `db-push` say the direction in
their own name, so run the one you mean.

### Per-entry flags

`--delete` works on an entry exactly as it does on the command line, and
applies to that entry alone:

```dotenv
ENVOY_STORAGE_SYNC="storage-push --dir=storage/app --delete, storage-pull --dir=storage/media"
```

| Flag | |
|---|---|
| `--dir=<path>` | the directory the entry moves; required on `storage-*` |
| `--delete` | mirror deletions at the destination |

`--delete` is **off by default in both directions** — it deletes files at the
far end that were never here, which is rarely what you meant. Put it on the one
entry that needs it, as above, where `storage/app` is authored locally and the
server should mirror it exactly. `envoy run storage-push --delete` turns it on
for one run everywhere; nothing turns it back off, because off is where it
starts.

Bad declarations are rejected rather than silently ignored: an entry that is
not a `storage-pull` / `storage-push`, an unknown flag, a misspelling, an entry
naming no directory, and a `--dir` carrying more than one path.

## Env keys

`.env.example` is the template to append; this is what the keys mean. Every key
is `<ENV>_<THING>` with `ENV` being `PROD` or `DEV`. **Only the `PROD_` block is
required** — add `DEV_` for projects that have a second server, and the local
environment reuses Laravel's own `DB_*` keys, so there is nothing to add for it.

There is deliberately no default-target key: pushes go to dev, pulls come from
prod, and a project with only `PROD_SSH_HOST` set uses production for both.

### Per environment

| Key | |
|---|---|
| `<ENV>_SSH_HOST` `_SSH_USER` `_SSH_PORT` | how to reach the server; the `_SSH_HOST` is what registers the environment at all |
| `<ENV>_PATH` | project root on the server |
| `<ENV>_DUMP_PATH` | where dumps are written there; defaults to `<ENV>_PATH/storage/envoy` |
| `<ENV>_BRANCH` | the branch that server runs — `main` for prod, `dev` for dev |
| `<ENV>_PHP` `_COMPOSER` `_NPM` | absolute paths for hosts that don't have them on `PATH`, e.g. `PROD_PHP=/opt/alt/php83/usr/bin/php` or `PROD_COMPOSER="php ~/code/bin/composer"` |
| `<ENV>_DB_HOST` `_DB_PORT` `_DB_DATABASE` `_DB_USERNAME` `_DB_PASSWORD` | that server's database |
| `<ENV>_DB_SQLITE_PATH` | SQLite projects only, and only when the file is not at `database/database.sqlite` |

### Behaviour

| Key | |
|---|---|
| `ENVOY_DUMP_PATH` | where dumps are kept locally — `./storage/envoy`, which belongs in `.gitignore` |
| `ENVOY_BUILD_ASSETS` | whether `deploy` and `code-push` build on the server. Leave it empty to auto-detect (true when `package.json` has a `build` script); set `false` for projects with no front-end build, or that commit built assets. `--build` / `--nobuild` override it for one run. |
| `ENVOY_STORAGE_SYNC` | which directories move, and which way — see [above](#which-way-does-the-data-go) |
| `ENVOY_DB_IGNORE_TABLES` | tables whose data is never carried between environments — migrations, cache, sessions, queues, telescope, pulse |

## Flags

| Flag | Effect |
|---|---|
| `--prod` / `--dev` / `--on=prod` | pin both directions to one environment |
| `--force` | on `code-push` / `deploy` / `code-sync`: amend + force-push, hard-reset the remote |
| `--dir=path` | transfer this project-relative path, ignoring `ENVOY_STORAGE_SYNC` |
| `--delete` | force mirroring on for one run, everywhere |
| `--dry` | rsync dry run with `--itemize-changes` |
| `--build` / `--nobuild` | force or skip `npm-build` in `code-push` / `deploy` |

`--noconfirm` also exists, but it is internal: `code-sync` uses it to stop the
runs it spawns from re-asking for the confirmation you already gave.

## Notes

- `code-push` moves the *remote* onto your local branch. Your working copy is
  never checked out from under you — the one exception is `code-sync`, which
  merges and checks out both branches by definition, and `db-import`, which
  borrows prod's branch to build the right schema and puts you back afterwards.
- Dumps are **data only**, always. `db-import` runs `migrate:fresh` on prod's
  branch first, imports, then switches back to your branch and applies newer
  migrations — so the schema comes from the migrations, never from a dump.
- Passwords go through `MYSQL_PWD`, not `--password=…`, so they don't show up
  in `ps` on a shared host.
- `set -e` in every task, so a failed `git pull` cannot leave `migrate` and
  `artisan up` running behind it.
- Per-project quirks (a `permission_name` virtual-column dance, a `DevSeeder`,
  extra ignore tables) stay in that project's file as an extra task appended
  below the template — the shared part stays shared.
