<?php

declare(strict_types=1);

namespace Vitnasinec\EnvoyConfig;

/**
 * The arguments Envoy was called with — read for which remote a command is
 * aimed at, and for nothing else.
 *
 * Envoy hands every `--flag` to the template as a variable, but that is too
 * late: the Config object is built at the top of the project's file, and every
 * task below it is rendered against the remote it settled on. So the flag is
 * read here, off the same arguments, one step earlier.
 */
final class CommandLine
{
    /**
     * The short forms of the two remote names in use, so `-p` is `--prod` and
     * `-d` is `--dev`. Only these: a letter is short for one name, and every
     * other name is spelled out.
     */
    public const SHORT = ['p' => 'prod', 'd' => 'dev'];

    /**
     * Every `--flag` typed, in the order it was typed, with `-p` and `-d` read
     * as the long names they are short for. `--flag=value` counts as the flag
     * alone — none of the ones read here carries a value.
     *
     * @return list<string>
     */
    public static function flags(): array
    {
        $flags = [];

        foreach (self::arguments() as $argument) {
            if (str_starts_with($argument, '--')) {
                $flags[] = explode('=', substr($argument, 2), 2)[0];
            } elseif (isset(self::SHORT[substr($argument, 1)]) && $argument[0] === '-') {
                $flags[] = self::SHORT[substr($argument, 1)];
            }
        }

        return $flags;
    }

    /**
     * Whether a task is about to run, rather than `envoy tasks` merely listing
     * what there is. Listing runs nothing against nothing, so it does not have
     * to choose a remote, and demanding a flag where there is no risk would
     * only make the list unreadable.
     */
    public static function runsATask(): bool
    {
        foreach (self::arguments() as $argument) {
            if (! str_starts_with($argument, '-')) {
                return $argument === 'run';
            }
        }

        return false;
    }

    /**
     * The task or story the command line named, or null when it named none —
     * `envoy tasks` is reading the list, and `envoy run` on its own is about to
     * be told it has to say what to run.
     */
    public static function task(): ?string
    {
        $words = array_values(array_filter(
            self::arguments(),
            static fn (string $argument): bool => ! str_starts_with($argument, '-'),
        ));

        return ($words[0] ?? null) === 'run' ? ($words[1] ?? null) : null;
    }

    /**
     * The arguments, without the name Envoy was called by.
     *
     * @return list<string>
     */
    private static function arguments(): array
    {
        return array_values(array_filter(
            array_slice((array) ($_SERVER['argv'] ?? []), 1),
            'is_string',
        ));
    }
}
