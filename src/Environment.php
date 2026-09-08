<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

use RuntimeException;

/**
 * One end of the map — a remote, or here.
 *
 * Every value on it is spliced into a shell command, or decides whether one
 * runs at all, so it is a typed property rather than an array key: a mistyped
 * key arrives at rsync as an empty source, a mistyped property is a fatal
 * error before anything runs.
 *
 * Which directories mirror lives here too, on the remote they mirror with,
 * because the answer is per remote: the same project can take prod's uploads
 * down and publish its fixtures up to dev.
 */
final class Environment
{
    /**
     * @param  string  $dumps  where dumps are written at this end — needed only
     *                         by a MySQL project, so it defaults to nowhere
     * @param  Database|null  $db  null when the project has no database at all,
     *                             and then no database task is defined
     * @param  int|null  $port  null defers to ~/.ssh/config, which is not the
     *                          same as forcing 22 — set it only to override
     * @param  bool  $build  whether deploy and code-push build assets on this end
     *                       — off unless the project says otherwise
     * @param  list<SyncDir>  $storagePull  directories that move this remote -> local
     * @param  list<SyncDir>  $storagePush  directories that move local -> this remote
     */
    public function __construct(
        public readonly string $path,
        public readonly string $branch,
        public readonly string $dumps = '',
        public readonly ?Database $db = null,
        public readonly string $ssh = '',
        public readonly ?int $port = null,
        public readonly string $php = 'php',
        public readonly string $composer = 'composer',
        public readonly string $npm = 'npm',
        public readonly bool $build = false,
        public readonly array $storagePull = [],
        public readonly array $storagePush = [],
    ) {
    }

    /**
     * Here. Nothing is ever ssh'd to this end, so the ssh fields keep their
     * defaults, the paths come from where the project sits, and the branch is
     * whichever one you are on right now. There are no mirror lists either:
     * they say what moves between a remote and here, so they belong on the
     * remote.
     */
    public static function local(
        ?Database $db = null,
        ?string $path = null,
        ?string $dumps = null,
        ?string $branch = null,
        string $php = 'php',
        string $composer = 'composer',
        string $npm = 'npm',
    ): self {
        $path ??= (string) getcwd();

        return new self(
            path: $path,
            branch: $branch ?? self::currentBranch($path),
            dumps: $dumps ?? $path.'/storage/envoy',
            db: $db,
            php: $php,
            composer: $composer,
            npm: $npm,
        );
    }

    /** `-p 2222`, for ssh. Empty when the port is ssh's own business. */
    public function sshFlag(): string
    {
        return $this->port === null ? '' : "-p {$this->port}";
    }

    /** `-P 2222`, for scp — the same thing spelled differently. */
    public function scpFlag(): string
    {
        return $this->port === null ? '' : "-P {$this->port}";
    }

    /** `-e 'ssh -p 2222'`, for rsync — the same thing spelled differently again. */
    public function rsyncShell(): string
    {
        return $this->port === null ? '' : "-e 'ssh -p {$this->port}'";
    }

    private static function currentBranch(string $path): string
    {
        $branch = trim((string) shell_exec(
            'git -C '.escapeshellarg($path).' branch --show-current 2>/dev/null'
        ));

        if ($branch === '') {
            throw new RuntimeException(
                'Envoy: cannot tell which branch is checked out in '.$path.'. Pass '
                .'branch: to Environment::local() if this is not a git working copy, '
                .'or check out a branch if HEAD is detached.'
            );
        }

        return $branch;
    }
}
