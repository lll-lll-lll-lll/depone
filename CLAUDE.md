# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`depone` is a PHP CLI static-analysis tool that classifies every `require_once` in a Composer project by its relationship to Composer autoload: **redundant** (target already autoloaded; deleting is provably safe), **conflicting** (target shadows a copy the autoloader would load from a different file), or unresolved/unreported. It is report-only and never rewrites code.

## Commands

```sh
composer install       # setup
composer check         # everything CI runs locally: cs + analyse + test
composer test          # PHPUnit
composer analyse       # PHPStan (level max, covers src/ and bin/)
composer cs            # PHP-CS-Fixer dry-run (PSR-12)
composer cs-fix        # apply style fixes
```

Run a single test file or test:

```sh
vendor/bin/phpunit tests/AnalyzerTest.php
vendor/bin/phpunit --filter testMethodName
```

Run the tool itself: `php bin/depone` (options: `--trace <repo-relative-path>`, `--format json`).

Supported PHP range is 8.1–8.5; CI runs `composer check` on 8.4/8.5 and the test suite on 8.1–8.3 plus a `--prefer-lowest` job, so avoid syntax/APIs newer than 8.1 and don't rely on a dependency behavior newer than the lowest allowed constraint.

`example/` is a separate Composer project (its own `composer.json`/lockfile, `composer install` there) demonstrating how findings are meant to be acted on: `depone --format json` piped into a custom Rector rule that deletes only the `redundant` entries. It exists because depone is deliberately report-only — proposals to add an autofix mode belong there, not in `src/`.

## Architecture

The pipeline, from `bin/depone`:

1. **`Cli\CliApplication`** wires Symfony Console around the single **`Cli\FindRedundantCommand`** (command name `depone`). Exit codes are the public contract: `0` clean, `1` findings, `2` analysis could not run. They gate on `Analyzer::ACTIONABLE_CATEGORIES` (`redundant`, `conflicting`); `unresolved` and `edges` are informational only.
2. **`Core\Analyzer`** walks every PHP file with Symfony Finder (excluding `vendor/`, `.git/`) and locates include/require statements on a raw `PhpToken::tokenize()` stream — tokens, not a parse, because legacy codebases contain files a strict parser would reject. It produces the `AnalysisResult` array shape (see its phpstan types) and memoizes classifications per target file, since legacy code requires the same file from many places.
3. **`Tokenizer\IncludeExprParser`** evaluates each include's path expression using php-parser's `ConstExprEvaluator`, extended with context (`__DIR__`/`__FILE__`, `define()`'d constants, `dirname()`). Unevaluable expressions become `unresolved` entries with a reason (`variable`, `method_call`, `static_access`, `complex`) — never silently dropped. The reason is derived from the AST when the snippet parses, and only falls back to raw-token scanning in **`Tokenizer\TokenHelper`** (which also owns the reason constants and the token-cursor helpers) when it does not.
4. **`Tokenizer\DeclaredClassExtractor`** parses each resolved target with php-parser to find the class-like types it declares and whether it declares *only* types (no functions/constants/top-level code).
5. **`Resolver\AutoloadResolver`** resolves class names to files with Composer's own `ClassLoader`. It prefers the dumped `vendor/composer/autoload_*.php` maps; without them it falls back to reading the root `composer.json` (psr-4/psr-0/classmap/files + dev), using `composer/class-map-generator` for classmap scanning.
6. Classification: redundant only when provably safe (eager `autoload.files` entry, or target declares nothing but types and every declared class autoloads back to that same file); a class resolving to a *different* file makes it conflicting; anything else is unreported (the require is load-bearing).
7. **`Core\OutputFormatter`** renders text/JSON; **`Core\DependencyGraph`** builds reverse caller traces for `--trace` from the collected edges. **`Cli\DualOutput`** keeps the report on stdout and diagnostics on stderr, so `depone --format json > report.json` stays valid JSON even when something goes wrong.

A key design rule (documented in the README and past PRs): **delegate to upstream machinery instead of reimplementing it** — class-to-file resolution goes through Composer's `ClassLoader`, expression evaluation through php-parser's `ConstExprEvaluator`, classmap scanning through `composer/class-map-generator`, file discovery through Symfony Finder, and every path operation through Symfony `Filesystem\Path` (wrapped thinly in `Tokenizer\PathHelper` — no hand-rolled string munging of paths).

## Public interface and conventions

- The supported public interface is the CLI only: command name, options, exit codes, and output. Everything under `src/` is `final`, marked `@internal`, and carries no BC guarantee.
- Any change to the user-visible CLI (options, exit codes, output) needs a one-line entry under **Unreleased** in `CHANGELOG.md`.
- Findings exit `1` and errors exit `2` deliberately, so CI consumers can distinguish "findings" from "analysis failed" — don't collapse them.

## Tests

Tests live flat in `tests/`; `tests/Fixture/` holds self-contained mini Composer projects (each with its own `composer.json`) that tests run the analyzer against, and is excluded from the PHPUnit test suite. To cover a new classification scenario, add or extend a fixture project rather than mocking the filesystem.

Fixtures are checked in as source only — no `composer install` is ever run in them, so they exercise `AutoloadResolver`'s composer.json fallback. `VendorAutoloadProject` is the exception: it ships hand-written `vendor/composer/autoload_*.php` maps to exercise the dumped-autoloader branch. `tests/bootstrap.php` autoloads `src/` itself, so a new class under `src/` needs no `composer dump-autoload`.
