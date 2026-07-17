<?php
declare(strict_types=1);

namespace Mampf;

use RuntimeException;

final class HttpClient
{
    public function __construct(
        private readonly string $impersonateBinary = '',
        private readonly string $impersonateTarget = 'chrome146'
    ) {}

    /**
     * Send an HTTP request with PHP cURL.
     *
     * @param list<string> $headers
     */
    public function request(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null
    ): HttpResponse {
        $handle = curl_init(url: $url);
        if ($handle === false) {
            throw new RuntimeException(message: 'cURL konnte nicht initialisiert werden.');
        }
        curl_setopt_array(
            handle: $handle,
            options: [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 8,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'mampf/1.0'
            ]
        );
        if ($body !== null) {
            curl_setopt(handle: $handle, option: CURLOPT_POSTFIELDS, value: $body);
        }
        $responseBody = curl_exec(handle: $handle);
        if (!is_string(value: $responseBody)) {
            $message = curl_error(handle: $handle);
            throw new RuntimeException(message: 'HTTP-Anfrage fehlgeschlagen: ' . $message);
        }
        $status = (int) curl_getinfo(handle: $handle, option: CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo(handle: $handle, option: CURLINFO_EFFECTIVE_URL);
        return new HttpResponse(status: $status, body: $responseBody, finalUrl: $finalUrl);
    }

    /**
     * Fetch multiple endpoints concurrently.
     *
     * @param list<string> $urls
     * @param list<string> $headers
     * @return array<string, HttpResponse>
     */
    public function requestMany(
        array $urls,
        array $headers = [],
        int $concurrency = 12,
        bool $head = false,
        int $connectTimeout = 20,
        int $timeout = 60
    ): array {
        $multiHandle = curl_multi_init();
        $pending = array_values(array: $urls);
        $active = [];
        $responses = [];
        do {
            while (count(value: $active) < $concurrency && $pending !== []) {
                $url = array_shift(array: $pending);
                if (!is_string(value: $url)) {
                    continue;
                }
                $handle = curl_init(url: $url);
                if ($handle === false) {
                    continue;
                }
                curl_setopt_array(
                    handle: $handle,
                    options: [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_CONNECTTIMEOUT => $head ? 3 : $connectTimeout,
                        CURLOPT_TIMEOUT => $head ? 8 : $timeout,
                        CURLOPT_NOBODY => $head,
                        CURLOPT_HTTPHEADER => $headers,
                        CURLOPT_ENCODING => '',
                        CURLOPT_USERAGENT => 'mampf/1.0'
                    ]
                );
                curl_multi_add_handle(multi_handle: $multiHandle, handle: $handle);
                $active[(int) $handle] = ['handle' => $handle, 'url' => $url];
            }
            do {
                $status = curl_multi_exec(multi_handle: $multiHandle, still_running: $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            while (($info = curl_multi_info_read(multi_handle: $multiHandle)) !== false) {
                $handle = $info['handle'];
                $entry = $active[(int) $handle] ?? null;
                if (is_array(value: $entry)) {
                    $body = curl_multi_getcontent(handle: $handle);
                    $responses[$entry['url']] = new HttpResponse(
                        status: (int) curl_getinfo(handle: $handle, option: CURLINFO_RESPONSE_CODE),
                        body: is_string(value: $body) ? $body : '',
                        finalUrl: (string) curl_getinfo(handle: $handle, option: CURLINFO_EFFECTIVE_URL)
                    );
                    unset($active[(int) $handle]);
                }
                curl_multi_remove_handle(multi_handle: $multiHandle, handle: $handle);
            }
            if ($running > 0) {
                $selected = curl_multi_select(multi_handle: $multiHandle, timeout: 1.0);
                if ($selected === -1) {
                    usleep(microseconds: 1000);
                }
            }
        } while ($pending !== [] || $active !== []);
        return $responses;
    }

    /**
     * Send a request through curl-impersonate.
     *
     * @param list<string> $headers
     */
    public function requestImpersonated(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        ?string $cookieJar = null
    ): HttpResponse {
        if ($this->impersonateBinary === '' || !is_executable(filename: $this->impersonateBinary)) {
            throw new RuntimeException(message: 'curl-impersonate ist nicht unter .bin/curl-impersonate installiert.');
        }
        $bodyFile = tempnam(directory: sys_get_temp_dir(), prefix: 'mampf-body-');
        $errorFile = tempnam(directory: sys_get_temp_dir(), prefix: 'mampf-error-');
        if ($bodyFile === false || $errorFile === false) {
            throw new RuntimeException(message: 'Temporäre HTTP-Dateien konnten nicht erstellt werden.');
        }
        $arguments = [
            $this->impersonateBinary,
            '--impersonate',
            $this->impersonateTarget,
            '-sS',
            '-L',
            '--compressed',
            '--connect-timeout',
            '20',
            '--max-time',
            '90',
            '-o',
            $bodyFile,
            '-w',
            '%{http_code}|%{url_effective}'
        ];
        if (!in_array(needle: $method, haystack: ['GET', 'POST'], strict: true)) {
            $arguments[] = '-X';
            $arguments[] = $method;
        }
        if ($cookieJar !== null) {
            $arguments[] = '--cookie';
            $arguments[] = $cookieJar;
            $arguments[] = '--cookie-jar';
            $arguments[] = $cookieJar;
        }
        foreach ($headers as $header) {
            $arguments[] = '-H';
            $arguments[] = $header;
        }
        if ($body !== null) {
            $arguments[] = '--data-raw';
            $arguments[] = $body;
        }
        $arguments[] = $url;
        $command = implode(separator: ' ', array: array_map(callback: 'escapeshellarg', array: $arguments));
        exec(command: $command . ' 2>' . escapeshellarg(arg: $errorFile), output: $output, result_code: $exitCode);
        $responseBody = (string) file_get_contents(filename: $bodyFile);
        $error = (string) file_get_contents(filename: $errorFile);
        unlink(filename: $bodyFile);
        unlink(filename: $errorFile);
        if ($exitCode !== 0) {
            throw new RuntimeException(message: 'HTTP-Anfrage fehlgeschlagen: ' . trim(string: $error));
        }
        [$status, $finalUrl] = array_pad(
            array: explode(separator: '|', string: implode(separator: "\n", array: $output), limit: 2),
            length: 2,
            value: ''
        );
        return new HttpResponse(status: (int) $status, body: $responseBody, finalUrl: $finalUrl);
    }
}
