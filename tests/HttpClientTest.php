<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\HttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpClientTest extends TestCase
{
    private string $directory;
    private string $endpoint;
    private mixed $server;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mampf-http-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->endpoint = 'http://' . $address;
        file_put_contents($this->directory . '/ready.txt', 'ready');
        file_put_contents($this->directory . '/proxies.txt', "proxy.example:8080:fixture-user:fixture-password\n");
        file_put_contents(
            $this->directory . '/curl',
            '#!' .
                PHP_BINARY .
                "\n" .
                <<<'PHP'
                <?php
                $output = $argv[array_search('-o', $argv, true) + 1];
                $position = array_search('--config', $argv, true);
                $file = $position === false ? null : $argv[$position + 1];
                $proxy = $file === null ? '' : file_get_contents($file);
                $attempts = (int) (file_exists(__DIR__ . '/attempts') ? file_get_contents(__DIR__ . '/attempts') : 0) + 1;
                file_put_contents(__DIR__ . '/attempts', (string) $attempts);
                if (file_exists(__DIR__ . '/transient') && $attempts <= (int) file_get_contents(__DIR__ . '/transient')) {
                    file_put_contents(__DIR__ . '/used-proxy', $file);
                    file_put_contents($output, 'partial response');
                    echo '000|https://failed.example/';
                    fwrite(STDERR, 'fixture-password');
                    exit(56);
                }
                if (file_exists(__DIR__ . '/fail')) {
                    file_put_contents(__DIR__ . '/used-proxy', $file);
                    fwrite(STDERR, 'fixture-password');
                    exit(7);
                }
                file_put_contents($output, json_encode(['config' => $proxy, 'file' => $file, 'mode' => $file === null ? null : fileperms($file) & 0777]));
                echo '200|' . end($argv);
                PHP
        );
        chmod($this->directory . '/curl', 0700);
        $this->server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $this->directory],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->directory . '/server.log', 'a'],
                2 => ['file', $this->directory . '/server.log', 'a']
            ],
            $pipes
        );
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $handle = curl_init($this->endpoint . '/ready.txt');
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 100]);
            if (curl_exec($handle) === 'ready') {
                return;
            }
            usleep(50000);
        }
        $this->fail('Local proxy-list server did not start.');
    }

    protected function tearDown(): void
    {
        proc_terminate($this->server);
        proc_close($this->server);
        foreach (glob($this->directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testProxyListIsLoadedOnceAndUsesPrivateCurlConfiguration(): void
    {
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyListUrl: $this->endpoint . '/proxies.txt'
        );
        $first = $client->requestImpersonated('https://www.rewe.de/shop/');
        $this->assertSame(200, $first->status);
        $firstData = json_decode($first->body, true);
        $config = $firstData['config'];
        $this->assertSame(0600, $firstData['mode']);
        $this->assertFileDoesNotExist($firstData['file']);
        $this->assertSame("proxy = \"http://fixture-user:fixture-password@proxy.example:8080\"\n", $config);
        file_put_contents($this->directory . '/proxies.txt', '');
        $second = $client->requestImpersonated('https://www.rewe.de/shop/basket');
        $this->assertSame($config, json_decode($second->body, true)['config']);
    }

    public function testProxyUrlsAreAccepted(): void
    {
        file_put_contents(
            $this->directory . '/proxies.txt',
            "http://fixture-user:fixture-password@proxy.example:8080\n"
        );
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyListUrl: $this->endpoint . '/proxies.txt'
        );
        $response = $client->requestImpersonated('https://www.rewe.de/shop/');
        $this->assertStringContainsString(
            'http://fixture-user:fixture-password@proxy.example:8080',
            json_decode($response->body, true)['config']
        );
    }

    public function testEmptyProxyListFailsInsteadOfConnectingDirectly(): void
    {
        file_put_contents($this->directory . '/proxies.txt', "\n\n");
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyListUrl: $this->endpoint . '/proxies.txt'
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Die Proxy-Liste ist leer.');
        $client->requestImpersonated('https://www.rewe.de/shop/');
    }

    public function testInvalidProxyEntryDoesNotExposeCredentials(): void
    {
        file_put_contents($this->directory . '/proxies.txt', 'http://fixture-user:fixture-password@');
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyListUrl: $this->endpoint . '/proxies.txt'
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Die Proxy-Liste enthält einen ungültigen Eintrag.');
        $client->requestImpersonated('https://www.rewe.de/shop/');
    }

    public function testProxyFailureDoesNotExposeCredentialsAndRemovesConfiguration(): void
    {
        file_put_contents($this->directory . '/fail', '1');
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyListUrl: $this->endpoint . '/proxies.txt'
        );
        try {
            $client->requestImpersonated('https://www.rewe.de/shop/');
            $this->fail('Proxy failure was not reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HTTP-Anfrage über Proxy fehlgeschlagen (cURL exit 7).', $exception->getMessage());
        }
        $this->assertFileDoesNotExist(file_get_contents($this->directory . '/used-proxy'));
    }

    public function testDirectProxyGatewayDoesNotFetchAList(): void
    {
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyListUrl: $this->endpoint . '/missing.txt',
            proxyUrl: 'http://fixture-user:fixture-password@proxy.example:8080'
        );
        $response = $client->requestImpersonated('https://www.rewe.de/shop/');
        $this->assertSame(200, $response->status);
        $this->assertSame(
            "proxy = \"http://fixture-user:fixture-password@proxy.example:8080\"\n",
            json_decode($response->body, true)['config']
        );
    }

    public function testInterruptedGetIsRetriedWithoutKeepingPartialResponse(): void
    {
        file_put_contents($this->directory . '/transient', '2');
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyUrl: 'http://fixture-user:fixture-password@proxy.example:8080'
        );
        $response = $client->requestImpersonated('https://www.rewe.de/shop/');
        $this->assertSame(200, $response->status);
        $this->assertSame('https://www.rewe.de/shop/', $response->finalUrl);
        $this->assertSame('3', file_get_contents($this->directory . '/attempts'));
        $this->assertIsArray(json_decode($response->body, true));
        $this->assertFileDoesNotExist(file_get_contents($this->directory . '/used-proxy'));
    }

    public function testInterruptedGetStopsAfterThreeAttemptsWithoutExposingCredentials(): void
    {
        file_put_contents($this->directory . '/transient', '9');
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyUrl: 'http://fixture-user:fixture-password@proxy.example:8080'
        );
        try {
            $client->requestImpersonated('https://www.rewe.de/shop/');
            $this->fail('Persistent receive error was not reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HTTP-Anfrage über Proxy fehlgeschlagen (cURL exit 56).', $exception->getMessage());
        }
        $this->assertSame('3', file_get_contents($this->directory . '/attempts'));
        $this->assertFileDoesNotExist(file_get_contents($this->directory . '/used-proxy'));
    }

    public function testInterruptedRequestsWithPotentialSideEffectsAreNotRetried(): void
    {
        file_put_contents($this->directory . '/transient', '9');
        $client = new HttpClient(
            impersonateBinary: $this->directory . '/curl',
            proxyUrl: 'http://fixture-user:fixture-password@proxy.example:8080'
        );
        foreach (['POST', 'GET'] as $method) {
            file_put_contents($this->directory . '/attempts', '0');
            try {
                $client->requestImpersonated('https://www.rewe.de/shop/', method: $method, body: '{}');
                $this->fail('Receive error was not reported.');
            } catch (RuntimeException $exception) {
                $this->assertSame('HTTP-Anfrage über Proxy fehlgeschlagen (cURL exit 56).', $exception->getMessage());
            }
            $this->assertSame('1', file_get_contents($this->directory . '/attempts'));
        }
    }

    public function testMissingProxyConfigurationKeepsDirectRequests(): void
    {
        $client = new HttpClient(impersonateBinary: $this->directory . '/curl');
        $response = $client->requestImpersonated('https://www.rewe.de/shop/');
        $this->assertSame('', json_decode($response->body, true)['config']);
    }
}
