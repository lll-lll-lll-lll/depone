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
