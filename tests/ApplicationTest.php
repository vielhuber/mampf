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
            $method->invoke($application, 42, 'Läuft.', 'progress', '/?year=2026');
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(
            "data: {\"type\":\"progress\",\"progress\":42,\"message\":\"Läuft.\",\"return_url\":\"/?year=2026\"}\n:" .
                str_repeat(string: ' ', times: 8192) .
                "\n\n",
            $output
        );
    }
}
