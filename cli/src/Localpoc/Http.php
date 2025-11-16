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

    public static function buildAdminAjaxUrl(string $siteUrl): string
    {
        $normalized = trim($siteUrl);
        if ($normalized === '') {
            throw new RuntimeException('Site URL cannot be empty.');
        }

        $normalized = rtrim($normalized, "/");
        if ($normalized === '') {
            throw new RuntimeException('Site URL resolved to an empty string.');
        }

        return $normalized . '/wp-admin/admin-ajax.php';
    }

    public static function postJson(string $url, array $params, string $key, $multiHandle = null): array
    {
        self::ensureCurlAvailable();
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to initialize HTTP request.');
        }

        self::applyCommonCurlOptions($handle);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'X-Localpoc-Key: ' . $key,
            ],
            CURLOPT_TIMEOUT        => 60,
        ]);

        [$body, $status] = self::executeCurl($handle, $multiHandle);

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

    public static function streamToFile(string $url, array $params, string $key, string $destPath, int $timeout = 600, $multiHandle = null, ?callable $progressCallback = null, bool $jsonBody = false): void
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

        self::applyCommonCurlOptions($handle);
        $headers = [
            'Accept: application/octet-stream',
            'X-Localpoc-Key: ' . $key,
        ];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $writeCallback = static function ($ch, $data) use ($fp, $progressCallback) {
            $len = fwrite($fp, $data);
            if ($len === false) {
                return 0;
            }

            if ($progressCallback) {
                $progressCallback($len);
            }

            return $len;
        };

        curl_setopt($handle, CURLOPT_WRITEFUNCTION, $writeCallback);

        if ($jsonBody) {
            $payload = json_encode($params);
            if ($payload === false) {
                fclose($fp);
                curl_close($handle);
                throw new RuntimeException('Failed to encode JSON payload.');
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        [, $status] = self::executeCurl($handle, $multiHandle);
        fclose($fp);

        if ($status < 200 || $status >= 300) {
            @unlink($destPath);
            throw new HttpException("Server returned HTTP {$status} for {$url}");
        }
    }

    /**
     * @param resource $fp
     */
    public static function createFileCurlHandle(string $url, array $params, string $key, $fp, int $timeout = 300, int $connectTimeout = 20): \CurlHandle
    {
        self::ensureCurlAvailable();

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to prepare file download request.');
        }

        self::applyCommonCurlOptions($handle);
        $headers = [
            'Accept: application/octet-stream',
            'X-Localpoc-Key: ' . $key,
        ];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FILE           => $fp,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        ]);

        return $handle;
    }

    /**
     * Creates a curl handle for streaming response with progress callback
     *
     * @param resource $fp
     * @param callable|null $progressCallback
     */
    public static function createStreamHandle(string $url, array $params, string $key, $fp, ?callable $progressCallback = null, bool $jsonBody = false, int $timeout = 600, int $connectTimeout = 20): \CurlHandle
    {
        self::ensureCurlAvailable();

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to prepare stream request.');
        }

        self::applyCommonCurlOptions($handle);
        $headers = [
            'Accept: application/octet-stream',
            'X-Localpoc-Key: ' . $key,
        ];

        $writeCallback = static function ($ch, $data) use ($fp, $progressCallback) {
            $len = fwrite($fp, $data);
            if ($len === false) {
                return 0;
            }

            if ($progressCallback) {
                $progressCallback($len);
            }

            return $len;
        };

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_WRITEFUNCTION  => $writeCallback,
        ]);

        if ($jsonBody) {
            $payload = json_encode($params);
            if ($payload === false) {
                curl_close($handle);
                throw new RuntimeException('Failed to encode JSON payload.');
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        return $handle;
    }

    private static function applyCommonCurlOptions($handle): void
    {
        $options = [
            CURLOPT_USERAGENT    => self::USER_AGENT,
            CURLOPT_ENCODING     => '',
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_FORBID_REUSE  => false,
        ];

        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }

        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            $options[CURLOPT_TCP_KEEPALIVE] = 1;
            if (defined('CURLOPT_TCP_KEEPIDLE')) {
                $options[CURLOPT_TCP_KEEPIDLE] = 30;
            }
            if (defined('CURLOPT_TCP_KEEPINTVL')) {
                $options[CURLOPT_TCP_KEEPINTVL] = 15;
            }
        }

        curl_setopt_array($handle, $options);
    }

    private static function executeCurl($handle, $multiHandle = null): array
    {
        if ($multiHandle === null) {
            $response = curl_exec($handle);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($errno !== 0) {
                throw new HttpException("HTTP request failed ({$errno}): {$error}");
            }

            return [$response, $status];
        }

        if (curl_multi_add_handle($multiHandle, $handle) !== CURLM_OK) {
            curl_close($handle);
            throw new HttpException('Failed to add cURL handle to multi handle.');
        }

        do {
            $mrc = curl_multi_exec($multiHandle, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($running && $mrc === CURLM_OK) {
            if (curl_multi_select($multiHandle, 1.0) === -1) {
                usleep(100000);
            }

            do {
                $mrc = curl_multi_exec($multiHandle, $running);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        }

        curl_multi_remove_handle($multiHandle, $handle);
        $response = curl_multi_getcontent($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($mrc !== CURLM_OK) {
            throw new HttpException('HTTP multi request failed: ' . $mrc);
        }

        if ($errno !== 0) {
            throw new HttpException("HTTP request failed ({$errno}): {$error}");
        }

        return [$response, $status];
    }

    private static function ensureCurlAvailable(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
    }
}
