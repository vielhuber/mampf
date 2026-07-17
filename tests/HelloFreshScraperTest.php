<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\Database;
use Mampf\HelloFreshScraper;
use Mampf\HttpClient;
use Mampf\ReweClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testVisibleTagsAndCuisinesAreImportedAsCategories(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $scraper = new HelloFreshScraper(database: new Database(path: $path), httpClient: new HttpClient());
        $method = new \ReflectionClass(objectOrClass: $scraper)->getMethod(name: 'categories');
        $categories = $method->invoke($scraper, [
            'cuisines' => [['name' => 'American'], ['name' => 'Amerikanisch']],
            'tags' => [
                [
                    'name' => 'Vegetarisch',
                    'displayLabel' => true,
                    'preferences' => [
                        'Fleisch & Gemüse',
                        'Veggie & Fisch',
                        'Internationale Küche',
                        'Vegan'
                    ]
                ],
                ['name' => 'Veggie', 'displayLabel' => true, 'preferences' => []],
                ['name' => 'Family', 'displayLabel' => true, 'preferences' => ['Familienfreundlich']],
                ['name' => 'nutri-score-c', 'displayLabel' => false, 'preferences' => []]
            ]
        ]);

        $this->assertSame(['Amerikanisch', 'Familienfreundlich', 'Vegetarisch'], $categories);
        unlink(filename: $path);
    }

    public function testIngredientMappingStopsAtFirstError(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('a', 'Alpha', 'image', 'https://example.org/a', null);
        $database->upsertRecipe('b', 'Beta', 'image', 'https://example.org/b', null);
        foreach ($database->recipes('', 1, 10, 2026, 29, sort: 'name_asc') as $recipe) {
            $database->updateIngredients(
                recipeId: (int) $recipe['id'],
                ingredients: [['name' => 'Kartoffeln']]
            );
        }
        $scraper = new HelloFreshScraper(database: $database, httpClient: new HttpClient());
        $reweClient = new ReweClient(database: $database, httpClient: new HttpClient(), cookieFile: '/does/not/exist');

        $this->expectException(exception: RuntimeException::class);
        $this->expectExceptionMessage(message: 'Alpha: Die REWE-Cookie-Datei wurde nicht gefunden');
        try {
            $scraper->scrapeIngredients(reweClient: $reweClient);
        } finally {
            unlink(filename: $path);
        }
    }
}
