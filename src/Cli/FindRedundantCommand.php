<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Cli;

use RedundantRequireOnce\Core\Analyzer;
use RedundantRequireOnce\Core\DependencyGraph;
use RedundantRequireOnce\Core\OutputFormatter as RedundantOutputFormatter;
use RedundantRequireOnce\Exception\AnalyzerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FindRedundantCommand extends Command
{
    public function __construct(private ?string $repoRoot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('find-redundant-require-once')
            ->setDescription('Detect redundant require_once statements for files already covered by Composer autoload.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Show reverse caller traces for the given file path (repo relative) — who requires this file?')
            ->addOption('deps', null, InputOption::VALUE_REQUIRED, 'Show forward dependency traces for the given file path (repo relative) — what files does this file require?')
            ->addOption('include-non-autoload', null, InputOption::VALUE_NONE, 'Include require_once targets not registered in Composer autoload')
            ->addOption('define', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Define a global constant used in require_once expressions (NAME=VALUE).\nCan be specified multiple times.\nExample: --define BASE_DIR=/var/www/htdocs/", [])
            ->addOption('max-paths', null, InputOption::VALUE_REQUIRED, 'Maximum number of trace paths (0 = unlimited)', 20)
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Maximum trace depth (0 = unlimited)', 25);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $consts = [];
        foreach ($input->getOption('define') as $define) {
            $eqPos = strpos((string) $define, '=');
            if ($eqPos === false || $eqPos === 0) {
                $errOutput->writeln("--define requires NAME=VALUE format, got '{$define}'");
                return Command::FAILURE;
            }
            $consts[substr((string) $define, 0, $eqPos)] = substr((string) $define, $eqPos + 1);
        }

        $repoRoot = $this->repoRoot ?? getcwd();
        if ($repoRoot === false) {
            $errOutput->writeln('failed to resolve current working directory');
            return Command::FAILURE;
        }

        /** @var string|null $trace */
        $trace = $input->getOption('trace');
        /** @var string|null $deps */
        $deps = $input->getOption('deps');
        $maxPaths = (int) $input->getOption('max-paths');
        $maxDepth = (int) $input->getOption('max-depth');

        try {
            $analyzer = new Analyzer($repoRoot);
            if ($consts !== []) {
                $analyzer->withGlobalConsts($consts);
            }
            if ($trace !== null || $deps !== null) {
                $analyzer->enableAutoloadEdges();
            }
            $result = $analyzer->run();
            if (!$input->getOption('include-non-autoload')) {
                unset($result['nonAutoloadRequireOnce']);
            }
        } catch (AnalyzerException $e) {
            $errOutput->writeln($e->getMessage());
            return Command::FAILURE;
        }

        $graph = new DependencyGraph($result['edges'], $repoRoot);

        if ($trace !== null) {
            $result['trace'] = $graph->buildReverseTrace($trace, $maxPaths, $maxDepth);
        }
        if ($deps !== null) {
            $result['deps'] = $graph->buildForwardTrace($deps, $maxPaths, $maxDepth);
        }

        $formatter = new RedundantOutputFormatter();

        if ($input->getOption('json')) {
            if ($trace !== null || $deps !== null) {
                $jsonData = [];
                if ($trace !== null) {
                    $jsonData['trace'] = $result['trace'];
                }
                if ($deps !== null) {
                    $jsonData['deps'] = $result['deps'];
                }
                $output->write($formatter->outputJson($jsonData), false, OutputInterface::OUTPUT_RAW);
            } else {
                $output->write($formatter->outputJson($result), false, OutputInterface::OUTPUT_RAW);
            }
            return Command::SUCCESS;
        }

        if ($trace !== null || $deps !== null) {
            if ($trace !== null) {
                $output->write($formatter->formatReverseTrace($result['trace']), false, OutputInterface::OUTPUT_RAW);
            }
            if ($deps !== null) {
                if ($trace !== null) {
                    $output->writeln('', OutputInterface::OUTPUT_RAW);
                }
                $output->write($formatter->formatForwardTrace($result['deps']), false, OutputInterface::OUTPUT_RAW);
            }
            return Command::SUCCESS;
        }

        $output->write($formatter->formatSummary($result), false, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
