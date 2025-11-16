<?php
declare(strict_types=1);

namespace Localpoc;

use InvalidArgumentException;
use RuntimeException;

class Cli
{
    private const DEFAULT_OUTPUT = './local-backup';
    private const DEFAULT_CONCURRENCY = 4;

    private const EXIT_SUCCESS = 0;
    private const EXIT_USAGE = 2;
    private const EXIT_HTTP = 3;
    private const EXIT_ERROR = 4;

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

    private function parseOptions(array $args): array
    {
        $options = [
            'url'         => null,
            'key'         => null,
            'output'      => self::DEFAULT_OUTPUT,
            'concurrency' => self::DEFAULT_CONCURRENCY,
        ];

        while (!empty($args)) {
            $arg = array_shift($args);
            if (str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                if ($eq !== false) {
                    $name = substr($arg, 2, $eq - 2);
                    $value = substr($arg, $eq + 1);
                } else {
                    $name = substr($arg, 2);
                    if (empty($args)) {
                        throw new InvalidArgumentException("Option --{$name} requires a value.");
                    }
                    $value = array_shift($args);
                }

                $name = strtolower($name);
                switch ($name) {
                    case 'url':
                    case 'key':
                    case 'output':
                        $options[$name] = trim($value);
                        break;
                    case 'concurrency':
                        if (!ctype_digit((string) $value) || (int) $value < 1) {
                            throw new InvalidArgumentException('Concurrency must be a positive integer.');
                        }
                        $options['concurrency'] = (int) $value;
                        break;
                    default:
                        throw new InvalidArgumentException("Unknown option --{$name}.");
                }
            } else {
                throw new InvalidArgumentException("Unexpected argument: {$arg}");
            }
        }

        if (empty($options['url']) || empty($options['key'])) {
            throw new InvalidArgumentException('Both --url and --key are required.');
        }

        if (empty($options['output'])) {
            $options['output'] = self::DEFAULT_OUTPUT;
        }

        return $options;
    }

    private function handleDownload(array $options): int
    {
        $url = $options['url'];
        $key = $options['key'];
        $outputDir = $options['output'];
        $concurrency = (int) $options['concurrency'];

        $this->info('Starting download command');
        $this->info('Output directory: ' . $outputDir);

        $this->ensureOutputDir($outputDir);

        $apiBase = Http::buildApiBaseUrl($url);
        $this->info('Using API base: ' . $apiBase);
        $this->info('Fetching manifest...');

        $manifest = Http::getJson($apiBase, '/files-manifest', $key);
        if (!isset($manifest['files']) || !is_array($manifest['files'])) {
            throw new RuntimeException('Manifest response missing files array.');
        }

        $files = $manifest['files'];
        $totalSize = 0;
        foreach ($files as $index => $file) {
            if (!is_array($file) || !array_key_exists('path', $file) || !array_key_exists('size', $file)) {
                throw new RuntimeException('Manifest entry #' . $index . ' is missing path or size.');
            }
            if (!is_numeric($file['size'])) {
                throw new RuntimeException('Manifest entry has non-numeric size for path ' . $file['path']);
            }
            $totalSize += (int) $file['size'];
        }

        $fileCount = count($files);
        $this->info(sprintf('Manifest retrieved: %d files, %d bytes total', $fileCount, $totalSize));

        $dbResult = $this->downloadDatabase($apiBase, $key, $outputDir);
        if ($dbResult['success']) {
            $this->info('Database export saved to ' . $dbResult['path']);
        } else {
            $this->error('Database download failed: ' . $dbResult['error']);
        }

        $this->info(sprintf('Downloading files (%d files, %d bytes) with concurrency %d...', $fileCount, $totalSize, $concurrency));
        $fileResults = $this->downloadFilesParallel($apiBase, $key, $files, $outputDir, $concurrency);
        $this->info('Completed file downloads.');

        $dbSummary = $dbResult['success'] ? $dbResult['path'] : 'FAILED';
        $this->info(sprintf('Done. DB: %s, Files: %d/%d, Failures: %d', $dbSummary, $fileResults['succeeded'], $fileResults['total'], $fileResults['failed']));

        if (!$dbResult['success'] || $fileResults['failed'] > 0) {
            return self::EXIT_HTTP;
        }

        return self::EXIT_SUCCESS;
    }

    private function ensureOutputDir(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Output directory cannot be empty.');
        }

        if (is_dir($path)) {
            return;
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException("Output path {$path} exists and is not a directory.");
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create output directory: ' . $path);
        }
    }

    private function ensureParentDir(string $path): void
    {
        $dir = dirname($path);
        if ($dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            return;
        }

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private function downloadDatabase(string $apiBaseUrl, string $key, string $outputDir): array
    {
        $normalizedBase = rtrim($outputDir, '\\\/');
        $destPath = $normalizedBase === '' ? 'db.sql' : $normalizedBase . DIRECTORY_SEPARATOR . 'db.sql';
        $this->ensureParentDir($destPath);

        try {
            Http::streamToFile(rtrim($apiBaseUrl, '/') . '/db-stream', $key, $destPath);
            return [
                'success' => true,
                'path'    => $destPath,
                'error'   => null,
            ];
        } catch (HttpException $e) {
            return [
                'success' => false,
                'path'    => $destPath,
                'error'   => $e->getMessage(),
            ];
        }
    }

    private function downloadFilesParallel(string $apiBaseUrl, string $key, array $files, string $outputDir, int $maxConcurrency): array
    {
        $results = [
            'total'     => count($files),
            'succeeded' => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        if ($results['total'] === 0) {
            $this->info('Manifest contains no files; skipping file download.');
            return $results;
        }

        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('Parallel downloads require the cURL extension.');
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            throw new RuntimeException('Failed to initialize curl_multi.');
        }

        $maxConcurrency = max(1, $maxConcurrency);
        $active = [];
        $nextIndex = 0;

        try {
            while (($results['succeeded'] + $results['failed']) < $results['total'] || !empty($active)) {
                while (count($active) < $maxConcurrency && $nextIndex < $results['total']) {
                    $fileEntry = $files[$nextIndex++];
                    $transfer = $this->createFileTransfer($apiBaseUrl, $key, $fileEntry, $outputDir);
                    $handle = $transfer['handle'];
                    curl_multi_add_handle($multi, $handle);
                    $active[(int) $handle] = $transfer;
                }

                if (empty($active)) {
                    break;
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    throw new RuntimeException('cURL multi error: ' . $status);
                }

                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $id = (int) $handle;

                    if (!isset($active[$id])) {
                        curl_multi_remove_handle($multi, $handle);
                        curl_close($handle);
                        continue;
                    }

                    $transfer = $active[$id];
                    unset($active[$id]);

                    fclose($transfer['fp']);

                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $curlErrNo = curl_errno($handle);
                    $curlErrMsg = curl_error($handle);

                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);

                    if ($curlErrNo !== 0 || $info['result'] !== CURLE_OK) {
                        @unlink($transfer['dest_path']);
                        $message = 'Download failed for ' . $transfer['file']['path'] . ': ' . ($curlErrMsg ?: 'cURL error #' . $curlErrNo);
                        $results['failed']++;
                        $results['errors'][] = $message;
                        $this->error($message);
                    } elseif ($httpCode < 200 || $httpCode >= 300) {
                        @unlink($transfer['dest_path']);
                        $message = 'Server returned HTTP ' . $httpCode . ' for file ' . $transfer['file']['path'];
                        $results['failed']++;
                        $results['errors'][] = $message;
                        $this->error($message);
                    } else {
                        $results['succeeded']++;
                    }

                    $this->info(sprintf('Downloaded %d / %d files (failures: %d)', $results['succeeded'], $results['total'], $results['failed']));
                }

                if (!empty($active)) {
                    $select = curl_multi_select($multi, 1.0);
                    if ($select === -1) {
                        usleep(100000);
                    }
                }
            }
        } finally {
            $this->cleanupActiveTransfers($multi, $active);
            curl_multi_close($multi);
        }

        return $results;
    }

    private function createFileTransfer(string $apiBaseUrl, string $key, array $fileEntry, string $outputDir): array
    {
        if (!isset($fileEntry['path']) || !is_string($fileEntry['path']) || $fileEntry['path'] === '') {
            throw new RuntimeException('Manifest entry is missing the file path.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $fileEntry['path']), '/');
        $normalizedBase = rtrim($outputDir, '\\\/');
        $localPath = $normalizedBase === ''
            ? str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
            : $normalizedBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        $this->ensureParentDir($localPath);

        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Unable to open file for writing: ' . $localPath);
        }

        $url = rtrim($apiBaseUrl, '/') . '/file?path=' . rawurlencode($relativePath);
        $handle = Http::createFileCurlHandle($url, $key, $fp);

        return [
            'handle'    => $handle,
            'fp'        => $fp,
            'file'      => $fileEntry,
            'dest_path' => $localPath,
        ];
    }

    private function cleanupActiveTransfers($multiHandle, array $active): void
    {
        foreach ($active as $transfer) {
            if (isset($transfer['handle'])) {
                curl_multi_remove_handle($multiHandle, $transfer['handle']);
                curl_close($transfer['handle']);
            }

            if (isset($transfer['fp']) && is_resource($transfer['fp'])) {
                fclose($transfer['fp']);
            }

            if (!empty($transfer['dest_path'])) {
                @unlink($transfer['dest_path']);
            }
        }
    }

    private function printUsage(): void
    {
        $usage = "Usage: localpoc download --url=<URL> --key=<KEY> [--output=<DIR>] [--concurrency=<N>]";
        fwrite(STDOUT, $usage . "\n");
    }

    private function info(string $message): void
    {
        fwrite(STDOUT, '[localpoc] ' . $message . "\n");
    }

    private function error(string $message): void
    {
        fwrite(STDERR, '[localpoc] ERROR: ' . $message . "\n");
    }
}
