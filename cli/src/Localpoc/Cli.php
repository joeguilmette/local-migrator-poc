<?php
declare(strict_types=1);

namespace Localpoc;

use InvalidArgumentException;
use RuntimeException;

/**
 * Main CLI entry point
 */
class Cli
{
    private const VERSION = '0.0.15';

    private const DEFAULT_OUTPUT = './local-backup';
    private const DEFAULT_CONCURRENCY = 4;

    private const EXIT_SUCCESS = 0;
    private const EXIT_USAGE = 2;
    private const EXIT_HTTP = 3;
    private const EXIT_ERROR = 4;

    /**
     * Runs the CLI application
     *
     * @param array $argv Command line arguments
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        array_shift($argv); // drop script name

        if (empty($argv)) {
            $this->printUsage();
            return self::EXIT_USAGE;
        }

        $command = array_shift($argv);

        if ($command === 'download') {
            try {
                $options = $this->parseOptions($argv);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());
                $this->printUsage();
                return self::EXIT_USAGE;
            }

            try {
                return $this->handleDownload($options);
            } catch (HttpException $e) {
                $this->error($e->getMessage());
                return self::EXIT_HTTP;
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());
                $this->printUsage();
                return self::EXIT_USAGE;
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                return self::EXIT_ERROR;
            } catch (\Throwable $e) {
                $this->error('Unexpected error: ' . $e->getMessage());
                return self::EXIT_ERROR;
            }
        }

        if (in_array($command, ['help', '--help', '-h'], true)) {
            $this->printUsage();
            return self::EXIT_SUCCESS;
        }

        $this->error("Unknown subcommand: {$command}");
        $this->printUsage();
        return self::EXIT_USAGE;
    }

    /**
     * Parses command line options
     *
     * @param array $args Command arguments
     * @return array Parsed options
     * @throws InvalidArgumentException If required options missing
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'url'         => '',
            'key'         => '',
            'output'      => self::DEFAULT_OUTPUT,
            'concurrency' => self::DEFAULT_CONCURRENCY,
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--url=')) {
                $options['url'] = substr($arg, 6);
            } elseif (str_starts_with($arg, '--key=')) {
                $options['key'] = substr($arg, 6);
            } elseif (str_starts_with($arg, '--output=')) {
                $options['output'] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--concurrency=')) {
                $options['concurrency'] = (int) substr($arg, 14);
            }
        }

        if ($options['url'] === '') {
            throw new InvalidArgumentException('--url is required.');
        }

        if ($options['key'] === '') {
            throw new InvalidArgumentException('--key is required.');
        }

        if ($options['concurrency'] < 1) {
            $options['concurrency'] = 1;
        }

        return $options;
    }

    /**
     * Handles the download command
     *
     * @param array $options Download options
     * @return int Exit code
     */
    private function handleDownload(array $options): int
    {
        // Initialize components
        $progressTracker = new ProgressTracker();
        $batchExtractor = new BatchZipExtractor($progressTracker);
        $downloader = new ConcurrentDownloader($progressTracker, $batchExtractor);
        $orchestrator = new DownloadOrchestrator($progressTracker, $downloader);

        // Execute download workflow
        return $orchestrator->handleDownload($options);
    }

    /**
     * Prints usage information
     */
    private function printUsage(): void
    {
        $usage = "Usage: localpoc download --url=<URL> --key=<KEY> [--output=<DIR>] [--concurrency=<N>]";
        fwrite(STDOUT, $usage . "\n");
        fwrite(STDOUT, "Version: " . self::VERSION . "\n");
    }

    /**
     * Outputs error message
     *
     * @param string $message Error message
     */
    private function error(string $message): void
    {
        fwrite(STDERR, '[localpoc] ERROR: ' . $message . "\n");
    }
}
