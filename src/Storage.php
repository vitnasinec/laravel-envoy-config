<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

/**
 * One directory that mirrors, and whether the far end mirrors deletions too.
 */
final class Storage
{
    public function __construct(
        public readonly string $path,
        public readonly bool $delete = false,
    ) {
    }

    /**
     * `--delete` makes the destination an exact mirror, which removes files at
     * the far end that were never here. Off unless the entry asked for it.
     */
    public function rsyncFlags(): string
    {
        return $this->delete ? '--delete' : '';
    }
}
