<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

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
     * @param  Database|null  $db  null when the project has no database at all,
     *                             and then no database task is defined
     * @param  int|null  $port  null defers to ~/.ssh/config, which is not the
     *                          same as forcing 22 — set it only to override
     * @param  bool  $build  whether deploy and code-push build assets on this end
     *                       — off unless the project says otherwise
     * @param  list<Storage>  $storagePull  directories that move this remote -> local
     * @param  list<Storage>  $storagePush  directories that move local -> this remote
     * @param  string|null  $deployFrom  the one branch this remote may be moved onto —
     *                                   null, and it takes whichever you are on
     */
    public function __construct(
        public readonly string $path,
        public readonly ?Database $db = null,
        public readonly string $ssh = '',
        public readonly ?int $port = null,
        public readonly string $php = 'php',
        public readonly string $composer = 'composer',
        public readonly string $npm = 'npm',
        public readonly bool $build = false,
        public readonly array $storagePull = [],
        public readonly array $storagePush = [],
        public readonly ?string $deployFrom = null,
    ) {
    }

    /**
     * Where dumps are written at this end, as one path everything else can use.
     *
     * The directory is configured on the Database, since only a database ever
     * writes one, but it is read here: the default is relative — the same
     * directory under every project root — and this end is the only thing that
     * knows which root that is. A path starting with / or ~ was written to say
     * exactly where, and is left as it is. No database means no dumps, and
     * nothing that would ask is defined.
     */
    public function dumps(): string
    {
        $dumps = $this->db?->dumps ?? '';

        if ($dumps === '' || str_starts_with($dumps, '/') || str_starts_with($dumps, '~')) {
            return $dumps;
        }

        return rtrim($this->path, '/').'/'
            .(str_starts_with($dumps, './') ? substr($dumps, 2) : $dumps);
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
}
