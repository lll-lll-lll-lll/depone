<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Core\OutputFormatter;

final class OutputFormatterTest extends TestCase
{
    public function testFormatSummaryOutputIsByteExact(): void
    {
        // The text output is part of the public CLI contract: redundant rows
        // print unindented without a detail, fixable/conflicting rows print
        // indented with one, and every section appears even when empty.
        // `needed` is deliberately non-empty here and must not change a single
        // byte of the output: it is invisible in the default text format.
        $result = [
            'redundant' => [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Bar.php',
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Bar', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Bar.php'],
                        ],
                    ],
                ],
            ],
            'fixable' => [
                [
                    'file' => 'public/a.php',
                    'line' => 7,
                    'target' => 'src/WrongPath.php',
                    'class' => 'App\Missing',
                    'expected_path' => 'src/Missing.php',
                    'detail' => 'App\Missing would load from src/Missing.php — fix autoload, then remove this require',
                ],
            ],
            'conflicting' => [
                [
                    'file' => 'public/b.php',
                    'line' => 9,
                    'target' => 'src/Dup.php',
                    'class' => 'App\Dup',
                    'loaded_from' => 'classmap/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy',
                ],
            ],
            'needed' => [
                [
                    'file' => 'public/d.php',
                    'line' => 11,
                    'target' => 'src/helper.php',
                    'reason' => 'target declares no types',
                ],
            ],
            'unresolved' => [
                ['file' => 'public/c.php', 'line' => 3, 'type' => 'include', 'reason' => 'complex', 'expr' => "SITE_ROOT . '/x.php'"],
            ],
            'edges' => [],
        ];

        $expected = 'redundant_require_once=1' . PHP_EOL
            . 'public/index.php:5 => src/Bar.php' . PHP_EOL
            . PHP_EOL
            . 'fixable_require_once=1' . PHP_EOL
            . '  public/a.php:7 => src/WrongPath.php  (App\Missing would load from src/Missing.php — fix autoload, then remove this require)' . PHP_EOL
            . PHP_EOL
            . 'conflicting_require_once=1' . PHP_EOL
            . '  public/b.php:9 => src/Dup.php  (App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy)' . PHP_EOL
            . PHP_EOL
            . 'unresolved_include_require=1' . PHP_EOL
            . "  public/c.php:3 [complex] SITE_ROOT . '/x.php'" . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatSummary($result));
    }

    public function testFormatSummaryPrintsEverySectionWhenEmpty(): void
    {
        $result = [
            'redundant' => [],
            'fixable' => [],
            'conflicting' => [],
            'needed' => [],
            'unresolved' => [],
            'edges' => [],
        ];

        $expected = 'redundant_require_once=0' . PHP_EOL
            . PHP_EOL
            . 'fixable_require_once=0' . PHP_EOL
            . PHP_EOL
            . 'conflicting_require_once=0' . PHP_EOL
            . PHP_EOL
            . 'unresolved_include_require=0' . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatSummary($result));
    }

    public function testFormatSummaryWithEvidenceOutputIsByteExact(): void
    {
        // --explain output: a coverage header, then the same section lines
        // formatSummary() prints, plus evidence lines under each redundant
        // row (one variant per proof shape: eager and pure-declaration).
        $result = [
            'redundant' => [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Bar.php',
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Bar', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Bar.php'],
                        ],
                    ],
                ],
                [
                    'file' => 'public/index.php',
                    'line' => 8,
                    'target' => 'src/eager.php',
                    'proof' => ['eager' => true, 'pure_declaration' => null, 'classes' => []],
                ],
            ],
            'fixable' => [
                [
                    'file' => 'public/a.php',
                    'line' => 7,
                    'target' => 'src/WrongPath.php',
                    'class' => 'App\Missing',
                    'expected_path' => 'src/Missing.php',
                    'detail' => 'App\Missing would load from src/Missing.php — fix autoload, then remove this require',
                ],
            ],
            'conflicting' => [
                [
                    'file' => 'public/b.php',
                    'line' => 9,
                    'target' => 'src/Dup.php',
                    'class' => 'App\Dup',
                    'loaded_from' => 'classmap/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy',
                ],
            ],
            'needed' => [
                [
                    'file' => 'public/d.php',
                    'line' => 11,
                    'target' => 'src/helper.php',
                    'reason' => 'target declares no types',
                ],
            ],
            'unresolved' => [
                ['file' => 'public/c.php', 'line' => 3, 'type' => 'include', 'reason' => 'complex', 'expr' => "SITE_ROOT . '/x.php'"],
            ],
            'edges' => [
                ['from' => 'public/index.php', 'line' => 5, 'type' => 'require_once', 'to' => 'src/Bar.php'],
                ['from' => 'public/index.php', 'line' => 8, 'type' => 'require_once', 'to' => 'src/eager.php'],
                ['from' => 'public/a.php', 'line' => 7, 'type' => 'require_once', 'to' => 'src/WrongPath.php'],
                ['from' => 'public/b.php', 'line' => 9, 'type' => 'require_once', 'to' => 'src/Dup.php'],
                ['from' => 'public/d.php', 'line' => 11, 'type' => 'require_once', 'to' => 'src/helper.php'],
            ],
        ];

        $expected = 'includes_total=6' . PHP_EOL
            . 'resolved=5' . PHP_EOL
            . 'unresolved=1' . PHP_EOL
            . 'needed_require_once=1' . PHP_EOL
            . PHP_EOL
            . 'redundant_require_once=2' . PHP_EOL
            . 'public/index.php:5 => src/Bar.php' . PHP_EOL
            . '    App\Bar => autoloaded via psr-4 from src/Bar.php' . PHP_EOL
            . '    pure declaration file: autoload reproduces everything this file provides' . PHP_EOL
            . 'public/index.php:8 => src/eager.php' . PHP_EOL
            . '    autoload.files entry — loaded eagerly on Composer init, so this require is a no-op' . PHP_EOL
            . PHP_EOL
            . 'fixable_require_once=1' . PHP_EOL
            . '  public/a.php:7 => src/WrongPath.php  (App\Missing would load from src/Missing.php — fix autoload, then remove this require)' . PHP_EOL
            . PHP_EOL
            . 'conflicting_require_once=1' . PHP_EOL
            . '  public/b.php:9 => src/Dup.php  (App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy)' . PHP_EOL
            . PHP_EOL
            . 'unresolved_include_require=1' . PHP_EOL
            . "  public/c.php:3 [complex] SITE_ROOT . '/x.php'" . PHP_EOL;

        self::assertSame($expected, (new OutputFormatter())->formatSummaryWithEvidence($result));
    }

    public function testFormatJsonOutputIsByteExact(): void
    {
        // The JSON document is the machine-readable contract: every section
        // is built explicitly, so this pins the exact shape rather than
        // whatever the internal result array happens to look like.
        $result = [
            'redundant' => [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Bar.php',
                    'proof' => [
                        'eager' => false,
                        'pure_declaration' => true,
                        'classes' => [
                            ['class' => 'App\Bar', 'via' => 'psr-4', 'prefix' => 'App\\', 'path' => 'src/Bar.php'],
                        ],
                    ],
                ],
            ],
            'fixable' => [
                [
                    'file' => 'public/a.php',
                    'line' => 7,
                    'target' => 'src/WrongPath.php',
                    'class' => 'App\Missing',
                    'expected_path' => 'src/Missing.php',
                    'detail' => 'App\Missing would load from src/Missing.php — fix autoload, then remove this require',
                ],
            ],
            'conflicting' => [
                [
                    'file' => 'public/b.php',
                    'line' => 9,
                    'target' => 'src/Dup.php',
                    'class' => 'App\Dup',
                    'loaded_from' => 'classmap/Dup.php',
                    'detail' => 'App\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy',
                ],
            ],
            'needed' => [
                [
                    'file' => 'public/d.php',
                    'line' => 11,
                    'target' => 'src/helper.php',
                    'reason' => 'target declares no types',
                ],
            ],
            'unresolved' => [
                ['file' => 'public/c.php', 'line' => 3, 'type' => 'include', 'reason' => 'complex', 'expr' => "SITE_ROOT . '/x.php'"],
            ],
            'edges' => [
                ['from' => 'public/index.php', 'line' => 5, 'type' => 'require_once', 'to' => 'src/Bar.php'],
                ['from' => 'public/a.php', 'line' => 7, 'type' => 'require_once', 'to' => 'src/WrongPath.php'],
                ['from' => 'public/b.php', 'line' => 9, 'type' => 'require_once', 'to' => 'src/Dup.php'],
                ['from' => 'public/d.php', 'line' => 11, 'type' => 'require_once', 'to' => 'src/helper.php'],
            ],
        ];

        $expected = <<<'JSON'
        {
            "schema_version": 1,
            "summary": {
                "includes_total": 5,
                "resolved": 4,
                "unresolved": 1,
                "require_once": {
                    "redundant": 1,
                    "fixable": 1,
                    "conflicting": 1,
                    "needed": 1
                }
            },
            "redundant": [
                {
                    "file": "public/index.php",
                    "line": 5,
                    "target": "src/Bar.php",
                    "proof": {
                        "eager": false,
                        "pure_declaration": true,
                        "classes": [
                            {
                                "class": "App\\Bar",
                                "via": "psr-4",
                                "prefix": "App\\",
                                "path": "src/Bar.php"
                            }
                        ]
                    }
                }
            ],
            "fixable": [
                {
                    "file": "public/a.php",
                    "line": 7,
                    "target": "src/WrongPath.php",
                    "class": "App\\Missing",
                    "expected_path": "src/Missing.php",
                    "detail": "App\\Missing would load from src/Missing.php — fix autoload, then remove this require"
                }
            ],
            "conflicting": [
                {
                    "file": "public/b.php",
                    "line": 9,
                    "target": "src/Dup.php",
                    "class": "App\\Dup",
                    "loaded_from": "classmap/Dup.php",
                    "detail": "App\\Dup is autoloaded from classmap/Dup.php — this require loads a shadowed copy"
                }
            ],
            "needed": [
                {
                    "file": "public/d.php",
                    "line": 11,
                    "target": "src/helper.php",
                    "reason": "target declares no types"
                }
            ],
            "unresolved": [
                {
                    "file": "public/c.php",
                    "line": 3,
                    "type": "include",
                    "reason": "complex",
                    "expr": "SITE_ROOT . '/x.php'"
                }
            ]
        }

        JSON;

        self::assertSame($expected, (new OutputFormatter())->formatJson($result));
    }

    public function testFormatJsonEmptyResultEncodesSectionsAsEmptyLists(): void
    {
        $result = [
            'redundant' => [],
            'fixable' => [],
            'conflicting' => [],
            'needed' => [],
            'unresolved' => [],
            'edges' => [],
        ];

        $expected = <<<'JSON'
        {
            "schema_version": 1,
            "summary": {
                "includes_total": 0,
                "resolved": 0,
                "unresolved": 0,
                "require_once": {
                    "redundant": 0,
                    "fixable": 0,
                    "conflicting": 0,
                    "needed": 0
                }
            },
            "redundant": [],
            "fixable": [],
            "conflicting": [],
            "needed": [],
            "unresolved": []
        }

        JSON;

        self::assertSame($expected, (new OutputFormatter())->formatJson($result));
    }
}
