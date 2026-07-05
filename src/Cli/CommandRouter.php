<?php

declare(strict_types=1);

namespace Depone\Internal\Cli;

/**
 * Decides whether depone's default command name should be prepended to argv.
 *
 * With subcommands registered, `Application::setDefaultCommand(..., false)` is
 * required so they are reachable, but it means Symfony can no longer tell an
 * option's value (e.g. `--trace src/Bar.php`) apart from a command name when
 * the default command is invoked implicitly. Routing is made unambiguous by
 * looking at the first non-option token: when it is a known command name, argv
 * is left untouched so the Application dispatches that command with any
 * preceding global options (`-q doctor`, `--help doctor`) still applied;
 * otherwise the default command name is prepended.
 *
 * @internal
 */
final class CommandRouter
{
    /**
     * @param list<string> $argv             Raw CLI arguments, argv[0] being the binary name
     * @param list<string> $knownCommandNames Command names (and aliases) the application recognizes
     * @return list<string> The possibly-rewritten argv
     */
    public static function route(array $argv, array $knownCommandNames, string $defaultCommandName): array
    {
        foreach (array_slice($argv, 1) as $token) {
            if ($token === '--') {
                break;
            }
            if ($token !== '' && $token[0] !== '-') {
                if (in_array($token, $knownCommandNames, true)) {
                    return $argv;
                }
                break;
            }
        }

        return [$argv[0], $defaultCommandName, ...array_slice($argv, 1)];
    }
}
