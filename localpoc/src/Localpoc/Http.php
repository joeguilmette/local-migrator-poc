<?php
declare(strict_types=1);

namespace Localpoc;

use RuntimeException;

class HttpException extends RuntimeException
{
}

class Http
{
    private const USER_AGENT = 'localpoc-cli/0.1';

    public static function buildApiBaseUrl(string $siteUrl): string
    {
        $normalized = trim($siteUrl);
        if ($normalized === '') {
            throw new RuntimeException('Site URL cannot be empty.');
        }

        $normalized = rtrim($normalized, "/");
        if ($normalized === '') {
            throw new RuntimeException('Site URL resolved to an empty string.');
        }

        return $normalized . '/wp-json/localpoc/v1';
    }

    public static function getJson(string $baseApiUrl, string $path, string $key): array
    {
        self::ensureCurlAvailable();
        $url = rtrim($baseApiUrl, '/') . '/' . ltrim($path, '/');
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to initialize HTTP request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Localpoc-Key: ' . $key,
            ],
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);
            throw new HttpException("HTTP request failed ({$errno}): {$error}");
        }

        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            throw new HttpException("Server returned HTTP {$status} for {$url}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $preview = substr(trim((string) $body), 0, 200);
            throw new HttpException('Failed to decode JSON from ' . $url . '. Response preview: ' . $preview);
        }

        return $decoded;
    }

    public static function streamToFile(string $url, string $key, string $destPath, int $timeout = 600): void
    {
        self::ensureCurlAvailable();

        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Unable to open file for writing: ' . $destPath);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            fclose($fp);
            throw new RuntimeException('Failed to initialize HTTP request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/octet-stream',
                'X-Localpoc-Key: ' . $key,
            ],
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        if (curl_exec($handle) === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);
            fclose($fp);
            @unlink($destPath);
            throw new HttpException("HTTP download failed ({$errno}): {$error}");
        }

        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        fclose($fp);

        if ($status < 200 || $status >= 300) {
            @unlink($destPath);
            throw new HttpException("Server returned HTTP {$status} for {$url}");
        }
    }

    /**
     * @param resource $fp
     */
    public static function createFileCurlHandle(string $url, string $key, $fp, int $timeout = 300, int $connectTimeout = 20): \CurlHandle
    {
        self::ensureCurlAvailable();

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to prepare file download request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FILE           => $fp,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/octet-stream',
                'X-Localpoc-Key: ' . $key,
            ],
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        ]);

        return $handle;
    }

    private static function ensureCurlAvailable(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
    }
}
