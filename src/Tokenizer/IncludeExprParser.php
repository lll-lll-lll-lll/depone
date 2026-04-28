<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tokenizer;

/**
 * Parser for include/require statements and define() calls.
 */
final class IncludeExprParser
{
    /**
     * Parses a define() call and extracts the constant name and value.
     *
     * @param list<Token> $tokens Token list
     * @param int $index Position of the `define` T_STRING token
     * @param array $consts Map of known constants
     * @param string $file Path of the file currently being analyzed
     * @return array{0: string, 1: string}|null [constant name, constant value], or null on parse failure
     */
    public function parseDefine(array $tokens, int $index, array $consts, string $file): ?array
    {
        $count = count($tokens);
        $cursor = $index + 1;
        TokenHelper::skipTrivia($tokens, $cursor);
        $openParen = $tokens[$cursor] ?? null;
        if ($openParen === null || $openParen->text !== '(') {
            return null;
        }

        $cursor++;
        $argsTokens = [];
        list($exprTokens, $cursor) = $this->extracted($cursor, $count, $tokens[$cursor], $argsTokens);

        $args = TokenHelper::splitArgs($argsTokens);
        if (count($args) < 2) {
            return null;
        }

        $constName = $this->evalStaticExpr($args[0], $consts, $file);
        if ($constName === null || $constName === '') {
            return null;
        }
        $constValue = $this->evalStaticExpr($args[1], $consts, $file);
        if ($constValue === null) {
            return null;
        }

        return [$constName, $constValue];
    }

    /**
     * Reads the argument tokens of a require/include statement.
     *
     * Example: require_once LIB_DIR . '/foo.php';  -> [LIB_DIR, '.', '/foo.php']
     * Example: require_once(LIB_DIR . '/foo.php'); -> tokens inside the parentheses
     *
     * @param list<Token> $tokens Token list
     * @param int $index Position of the require/include token
     * @return list<Token> Argument token list
     */
    public function readIncludeExprTokens(array $tokens, int $index): array
    {
        $count = count($tokens);
        $cursor = $index + 1;
        TokenHelper::skipTrivia($tokens, $cursor);

        $exprTokens = [];
        $firstToken = $tokens[$cursor] ?? null;
        if ($firstToken !== null && $firstToken->text === '(') {
            $cursor++;
            list($exprTokens, $cursor) = $this->extracted($cursor, $count, $tokens[$cursor], $exprTokens);

            return $exprTokens;
        }

        while ($cursor < $count && $tokens[$cursor]->text !== ';') {
            $exprTokens[] = $tokens[$cursor];
            $cursor++;
        }

        return $exprTokens;
    }

    /**
     * Evaluates a statically resolvable expression and returns its path string.
     *
     * @param list<Token> $tokens Expression token list
     * @param array $consts Map of known constants
     * @param string $file Path of the file being analyzed, used for __DIR__ and __FILE__
     * @return string|null Evaluated path string, or null when it cannot be resolved
     */
    public function evalStaticExpr(array $tokens, array $consts, string $file): ?string
    {
        $parser = new StaticExprParser($tokens, $consts, $file);

        return $parser->parse();
    }

    /**
     * @param int $cursor
     * @param int $count
     * @param Token $tokens Current token
     * @param array $exprTokens Collected expression tokens
     * @return array{0: array, 1: int}
     */
    public function extracted(int $cursor, int $count, $tokens, array $exprTokens): array
    {
        $depth = 1;
        while ($cursor < $count) {
            $token = $tokens;
            if ($token->text === '(') {
                $depth++;
            } elseif ($token->text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $exprTokens[] = $token;
            $cursor++;
        }
        return array($exprTokens, $cursor);
    }
}
