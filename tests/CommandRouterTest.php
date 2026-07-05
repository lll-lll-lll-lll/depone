<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Cli\CommandRouter;

/**
 * Unit tests for CommandRouter.
 *
 * Covered behavior:
 *   - a bare invocation prepends the default command
 *   - a default-command option as the first token prepends the default command
 *     (so its value is not mistaken for a command name)
 *   - a bare application-level flag (`-h`, `--version`) with no subcommand also
 *     gets the default command prepended; Symfony still honors these as global
 *     options regardless of the command name, so behavior is unaffected
 *   - a global flag *ahead of* a known subcommand (`--help doctor`, `-q doctor`)
 *     leaves argv unchanged, so that subcommand — not the default command —
 *     is dispatched
 *   - a known command name is left unchanged, with or without further options
 *   - a known built-in command (e.g. `help`) is left unchanged
 */
final class CommandRouterTest extends TestCase
{
    private const KNOWN_COMMANDS = ['depone', 'doctor', 'help', 'list', 'completion'];

    public function testBareInvocationPrependsDefaultCommand(): void
    {
        $result = CommandRouter::route(['bin'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'depone'], $result);
    }

    public function testOptionAsFirstTokenPrependsDefaultCommand(): void
    {
        // `--trace src/Bar.php` must not be mistaken for a command name.
        $result = CommandRouter::route(['bin', '--trace', 'src/Bar.php'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'depone', '--trace', 'src/Bar.php'], $result);
    }

    public function testHelpFlagWithSubcommandPassesThrough(): void
    {
        // `--help doctor` must reach the Application so it shows doctor's help,
        // instead of being prepended into the default command's help.
        $result = CommandRouter::route(['bin', '--help', 'doctor'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', '--help', 'doctor'], $result);
    }

    public function testGlobalOptionBeforeSubcommandPassesThrough(): void
    {
        // `-q doctor` must dispatch `doctor` (with `-q` applied), not be
        // hijacked into the default command by prepending it ahead of `-q`.
        $result = CommandRouter::route(['bin', '-q', 'doctor'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', '-q', 'doctor'], $result);
    }

    public function testBareHelpShortFlagPrependsDefaultCommand(): void
    {
        // No subcommand follows `-h`, so the default command is prepended.
        // Symfony still honors `-h` as a global flag regardless of the
        // command name, so this keeps displaying help as before.
        $result = CommandRouter::route(['bin', '-h'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'depone', '-h'], $result);
    }

    public function testBareVersionFlagPrependsDefaultCommand(): void
    {
        $result = CommandRouter::route(['bin', '--version'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'depone', '--version'], $result);
    }

    public function testKnownCommandIsLeftUnchanged(): void
    {
        $result = CommandRouter::route(['bin', 'doctor'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'doctor'], $result);
    }

    public function testKnownCommandWithOptionsIsLeftUnchanged(): void
    {
        $result = CommandRouter::route(['bin', 'doctor', '--min-severity=error'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'doctor', '--min-severity=error'], $result);
    }

    public function testKnownBuiltinCommandIsLeftUnchanged(): void
    {
        $result = CommandRouter::route(['bin', 'help'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'help'], $result);
    }

    public function testUnknownFirstTokenPrependsDefaultCommand(): void
    {
        $result = CommandRouter::route(['bin', 'not-a-command'], self::KNOWN_COMMANDS, 'depone');

        self::assertSame(['bin', 'depone', 'not-a-command'], $result);
    }
}
