<?php

declare(strict_types=1);

namespace Depone\Internal\Tokenizer;

/**
 * Helper utilities for working with PHP tokens.
 *
 * @internal
 */
final class TokenHelper
{
    /** Unresolvable because the require_once expression contains a variable. */
    public const REASON_VARIABLE      = 'variable';
    /** Unresolvable because the expression contains an object method call. */
    public const REASON_METHOD_CALL   = 'method_call';
    /** Unresolvable because the expression contains static access (`::`). */
    public const REASON_STATIC_ACCESS = 'static_access';
    /** Unresolvable because the expression could not be classified into any of the above categories. */
    public const REASON_COMPLEX       = 'complex';

    /**
     * Returns whether the token can be part of a class name.
     * Includes T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, and T_NAME_RELATIVE on PHP 8.0+.
     *
     * @param int|null $id Token ID
     */
    public static function isNameToken(?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        return in_array($id, [
            T_STRING,
            T_NS_SEPARATOR,
            T_NAME_QUALIFIED,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true);
    }

    /**
     * Skips trivia such as whitespace and comments.
     *
     * @param list<Token> $tokens Token list
     * @param int $cursor Current position, updated by reference
     */
    public static function skipTrivia(array $tokens, int &$cursor): void
    {
        $count = count($tokens);
        while ($cursor < $count) {
            $id = $tokens[$cursor]->id;
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            $cursor++;
        }
    }

    /**
     * Splits comma-separated argument tokens.
     * Nested parentheses are respected, so only top-level commas split arguments.
     *
     * @param list<Token> $tokens Token list
     * @return array<list<Token>> Token lists for each argument
     */
    public static function splitArgs(array $tokens): array
    {
        $args = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token->text === '(') {
                $depth++;
            } elseif ($token->text === ')') {
                $depth--;
            } elseif ($token->text === ',' && $depth === 0) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        if ($current !== []) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * Removes surrounding quotes from a string literal and unescapes it.
     *
     * @param string $literal Quoted string literal such as "'foo'" or '"bar"'
     * @return string|null Unquoted string, or null when the literal is malformed
     */
    public static function stripQuotes(string $literal): ?string
    {
        // A binary string prefix (b'...' / B"...") is just the same string.
        if (
            strlen($literal) >= 3
            && ($literal[0] === 'b' || $literal[0] === 'B')
            && ($literal[1] === "'" || $literal[1] === '"')
        ) {
            $literal = substr($literal, 1);
        }

        $length = strlen($literal);
        if ($length < 2) {
            return null;
        }
        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[$length - 1] !== $quote) {
            return null;
        }

        $inner = substr($literal, 1, -1);

        if ($quote === '"') {
            // strtr replaces matched keys and leaves everything else untouched,
            // so recognized escapes decode while an unknown one (\d, \a, \b)
            // keeps its backslash — matching PHP, unlike stripcslashes() which
            // drops it and would collapse "src\Models\User.php". Octal, hex, and
            // \u{...} escapes do not occur in include paths and stay verbatim.
            return strtr($inner, [
                '\\\\' => '\\', '\\"' => '"', '\\$' => '$',
                '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
                '\\v' => "\v", '\\f' => "\f", '\\e' => "\x1b",
            ]);
        }

        // Single-quoted strings only unescape \' and \\.
        return strtr($inner, [
            "\\'" => "'",
            '\\\\' => '\\',
        ]);
    }

    /**
     * Reconstructs the original source code string from a token list.
     *
     * @param list<Token> $tokens Token list
     * @return string Reconstructed source code
     */
    public static function tokensToString(array $tokens): string
    {
        $result = '';
        foreach ($tokens as $token) {
            $result .= $token->text;
        }

        return trim($result);
    }

    /**
     * Classifies why a token list could not be resolved.
     *
     * @param list<Token> $tokens Token list
     * @return string Reason (variable, method_call, static_access, complex)
     */
    public static function classifyUnresolvableReason(array $tokens): string
    {
        foreach ($tokens as $token) {
            $id = $token->id;

            if ($id === T_VARIABLE) {
                return self::REASON_VARIABLE;
            }

            // Expressions containing $obj->method() are not statically resolvable.
            if ($id === T_OBJECT_OPERATOR) {
                return self::REASON_METHOD_CALL;
            }

            // Static access like Class::method() or Class::CONST is unresolved here.
            if ($id === T_DOUBLE_COLON) {
                return self::REASON_STATIC_ACCESS;
            }
        }

        return self::REASON_COMPLEX;
    }
}
