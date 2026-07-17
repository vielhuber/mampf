<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\Database;
use Mampf\HelloFreshScraper;
use Mampf\HttpClient;
use PHPUnit\Framework\TestCase;

final class HelloFreshScraperTest extends TestCase
{
    public function testIngredientsForThreePeopleAreImported(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $scraper = new HelloFreshScraper(database: new Database(path: $path), httpClient: new HttpClient());
        $method = new \ReflectionClass(objectOrClass: $scraper)->getMethod(name: 'ingredientDefinitions');
        $ingredients = $method->invoke($scraper, [
            'ingredients' => [['id' => 'potato', 'name' => 'Kartoffeln', 'shipped' => true]],
            'yields' => [
                ['yields' => 2, 'ingredients' => [['id' => 'potato', 'amount' => 400, 'unit' => 'g']]],
                ['yields' => 3, 'ingredients' => [['id' => 'potato', 'amount' => 600, 'unit' => 'g']]],
                ['yields' => 4, 'ingredients' => [['id' => 'potato', 'amount' => 800, 'unit' => 'g']]]
            ]
        ]);

        $this->assertSame(600, $ingredients[0]['amount']);
        $this->assertSame('g', $ingredients[0]['unit']);
        unlink(filename: $path);
    }

    public function testHelloFreshPdfUrlIsImported(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $scraper = new HelloFreshScraper(database: new Database(path: $path), httpClient: new HttpClient());
        $method = new \ReflectionClass(objectOrClass: $scraper)->getMethod(name: 'pdfUrl');
        $pdfUrl = 'https://www.hellofresh.de/recipecards/card/example.pdf';

        $this->assertSame($pdfUrl, $method->invoke($scraper, ['cardLink' => $pdfUrl]));
        $this->assertNull($method->invoke($scraper, ['cardLink' => 'https://example.org/example.pdf']));
        $this->assertNull($method->invoke($scraper, ['cardLink' => null]));
        unlink(filename: $path);
    }
}
