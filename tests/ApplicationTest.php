<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\Application;
use Mampf\Runtime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ApplicationTest extends TestCase
{
    public function testAssetsContainFileVersions(): void
    {
        $runtimeClass = new ReflectionClass(objectOrClass: Runtime::class);
        $runtime = $runtimeClass->newInstanceWithoutConstructor();
        $runtimeClass->getProperty(name: 'root')->setValue($runtime, dirname(path: __DIR__));
        $application = new Application(runtime: $runtime);
        $method = new ReflectionClass(objectOrClass: $application)->getMethod(name: 'assets');

        $assets = $method->invoke($application);

        $this->assertMatchesRegularExpression('/^\/app\.css\?v=\d+$/', $assets['css']);
        $this->assertMatchesRegularExpression('/^\/app\.js\?v=\d+$/', $assets['js']);
    }

    public function testProgressIsSentAsServerSentEvent(): void
    {
        $applicationClass = new ReflectionClass(objectOrClass: Application::class);
        $application = $applicationClass->newInstanceWithoutConstructor();
        $method = $applicationClass->getMethod(name: 'sendProgress');

        ob_start();
        try {
            $method->invoke($application, 42, 'Läuft.', 'progress', '/?year=2026', 'rewe-cookie-export');
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(
            "data: {\"type\":\"progress\",\"progress\":42,\"message\":\"Läuft.\",\"return_url\":\"/?year=2026\",\"help\":\"rewe-cookie-export\"}\n:" .
                str_repeat(string: ' ', times: 8192) .
                "\n\n",
            $output
        );
    }

    public function testCronStatusUsesTheCronLock(): void
    {
        $root = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8));
        mkdir(directory: $root . '/.data', permissions: 0770, recursive: true);
        $path = $root . '/.data/cron.lock';
        $lockHandle = fopen(filename: $path, mode: 'c+');
        $this->assertNotFalse($lockHandle);
        fwrite(stream: $lockHandle, data: '2026-07-22 07:30:00');
        flock(stream: $lockHandle, operation: LOCK_EX);

        $runtimeClass = new ReflectionClass(objectOrClass: Runtime::class);
        $runtime = $runtimeClass->newInstanceWithoutConstructor();
        $runtimeClass->getProperty(name: 'root')->setValue($runtime, $root);
        $application = new Application(runtime: $runtime);
        $method = new ReflectionClass(objectOrClass: $application)->getMethod(name: 'cronStatus');

        $this->assertSame(
            [
                'running' => true,
                'started_at' => '2026-07-22 07:30:00',
                'completed_at' => null,
                'status' => 'running',
                'message' => 'Der Cronjob läuft im Hintergrund.'
            ],
            $method->invoke($application)
        );
        ftruncate(stream: $lockHandle, size: 0);
        file_put_contents(
            filename: $root . '/.data/cron.log',
            data: "[2026-07-22 07:35:00] START Aktualisierung gestartet.\n"
        );
        $this->assertSame(
            [
                'running' => true,
                'started_at' => '2026-07-22 05:35:00',
                'completed_at' => null,
                'status' => 'running',
                'message' => 'Der Cronjob läuft im Hintergrund.'
            ],
            $method->invoke($application)
        );
        flock(stream: $lockHandle, operation: LOCK_UN);
        fclose(stream: $lockHandle);
        file_put_contents(
            filename: $root . '/.data/cron.log',
            data: "[2026-07-22 07:35:00] START Aktualisierung gestartet.\n[2026-07-22 07:49:00] DONE  Laufzeit: 840 Sekunden.\n"
        );
        $this->assertSame(
            [
                'running' => false,
                'started_at' => null,
                'completed_at' => '2026-07-22 05:49:00',
                'status' => 'success',
                'message' => 'Die Cron-Aktualisierung wurde abgeschlossen.'
            ],
            $method->invoke($application)
        );

        unlink(filename: $path);
        unlink(filename: $root . '/.data/cron.log');
        rmdir(directory: $root . '/.data');
        rmdir(directory: $root);
    }
}
