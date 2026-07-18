<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testRecipeImportIsIncremental(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);

        $this->assertTrue(
            $database->upsertRecipe(
                'abc',
                'First',
                'image',
                'https://example.org/abc',
                null,
                2,
                1,
                4,
                'https://www.hellofresh.de/recipecards/card/first.pdf'
            )
        );
        $this->assertFalse(
            $database->upsertRecipe(
                'abc',
                'Updated',
                'image-2',
                'https://example.org/abc',
                null,
                7,
                3,
                4.5,
                'https://www.hellofresh.de/recipecards/card/updated.pdf',
                ['Italienisch', 'Vegetarisch']
            )
        );
        $this->assertSame(1, $database->recipeCount(search: '', year: 2026, week: 29));
        $this->assertSame('Updated', $database->recipes('', 1, 10, 2026, 29)[0]['name']);
        $this->assertSame(7, $database->recipes('', 1, 10, 2026, 29)[0]['favorites_count']);
        $this->assertSame(
            'https://www.hellofresh.de/recipecards/card/updated.pdf',
            $database->recipes('', 1, 10, 2026, 29)[0]['pdf_url']
        );
        $this->assertSame(['Italienisch', 'Vegetarisch'], $database->categories());

        unlink(filename: $path);
    }

    public function testWeekAssignmentsAreUnique(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);
        $recipeId = (int) $database->recipes('', 1, 10, 2026, 29)[0]['id'];
        $database->updateIngredients(
            recipeId: $recipeId,
            ingredients: [['name' => 'Kartoffeln', 'selected' => ['listing_id' => 'product-1']]]
        );

        $database->assignRecipe($recipeId, 2026, 29);
        $database->assignRecipe($recipeId, 2026, 29);
        $database->assignRecipe($recipeId, 2026, 30);

        $this->assertCount(1, $database->recipesForWeek(2026, 29));
        $this->assertSame(1, $database->weekRecipeCount(2026, 29));
        $this->assertSame(['2026-W29' => 1, '2026-W30' => 1], $database->weekRecipeCounts());
        unlink(filename: $path);
    }

    public function testRecipesWithoutIngredientsAreExcludedFromMapping(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);

        $this->assertSame([], $database->recipesForIngredientMapping());

        $recipeId = (int) $database->recipes('', 1, 10, 2026, 29)[0]['id'];
        $database->updateIngredients(recipeId: $recipeId, ingredients: [['name' => 'Kartoffeln']]);
        $this->assertCount(1, $database->recipesForIngredientMapping());
        unlink(filename: $path);
    }

    public function testRecipesCanBeFilteredAndSorted(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe(
            'a',
            'Alpha',
            'image',
            'https://example.org/a',
            '2026-01-01',
            1,
            1,
            3,
            categories: ['Italienisch']
        );
        $database->upsertRecipe(
            'b',
            'Beta',
            'image',
            'https://example.org/b',
            '2026-02-01',
            8,
            4,
            4.5,
            categories: ['Vegetarisch']
        );
        $recipes = $database->recipes('', 1, 10, 2026, 29, sort: 'name_asc');
        $database->updateIngredients(
            recipeId: (int) $recipes[1]['id'],
            ingredients: [
                ['name' => 'Kartoffeln', 'selected' => ['listing_id' => 'product-1']],
                ['name' => 'Salz', 'selected' => ['listing_id' => 'product-2']]
            ]
        );
        $database->updateIngredients(
            recipeId: (int) $recipes[0]['id'],
            ingredients: [
                ['name' => 'Zwiebel', 'selected' => ['listing_id' => 'product-3']],
                ['name' => 'Pfeffer', 'selected' => null]
            ]
        );
        $database->updateIngredients(
            recipeId: (int) $recipes[0]['id'],
            ingredients: [['name' => 'Zwiebel', 'selected' => ['listing_id' => 'product-3']]]
        );
        $database->assignRecipe((int) $recipes[0]['id'], 2026, 29);
        $database->updateIngredients(
            recipeId: (int) $recipes[0]['id'],
            ingredients: [
                ['name' => 'Zwiebel', 'selected' => ['listing_id' => 'product-3']],
                ['name' => 'Pfeffer', 'selected' => null]
            ]
        );

        $mapped = $database->recipes('', 1, 10, 2026, 29, ingredientFilter: 'mapped');
        $unmapped = $database->recipes('', 1, 10, 2026, 29, ingredientFilter: 'unmapped');
        $selected = $database->recipes('', 1, 10, 2026, 29, weekFilter: 'selected');
        $sorted = $database->recipes('', 1, 10, 2026, 29, sort: 'ingredients_desc');
        $popular = $database->recipes('', 1, 10, 2026, 30);
        $italian = $database->recipes('', 1, 10, 2026, 29, category: 'Italienisch');
        $mappingRecipes = $database->recipesForIngredientMapping();

        $this->assertSame('Beta', $mapped[0]['name']);
        $this->assertSame('Alpha', $unmapped[0]['name']);
        $this->assertSame('Alpha', $selected[0]['name']);
        $this->assertSame('Alpha', $sorted[0]['name']);
        $this->assertSame('Beta', $popular[0]['name']);
        $this->assertSame('Alpha', $italian[0]['name']);
        $this->assertSame('Alpha', $mappingRecipes[0]['name']);
        $this->assertCount(2, $mappingRecipes);
        $this->assertSame(3, $database->mappedIngredientCount('', 2026, 29));
        $this->assertSame(1, $database->mappedIngredientCount('Alpha', 2026, 29));
        $this->assertSame(2, $database->mappedIngredientCount('', 2026, 29, ingredientFilter: 'mapped'));
        $this->assertSame(1, $database->mappedIngredientCount('', 2026, 29, weekFilter: 'selected'));
        $this->assertSame(2, $database->recipeCount('', 2026, 29));
        $this->assertSame(1, $database->recipeCount('', 2026, 29, ingredientFilter: 'mapped'));
        $this->assertSame(1, $database->recipeCount('', 2026, 29, weekFilter: 'selected'));
        $this->assertSame(1, $database->recipeCount('', 2026, 29, category: 'Vegetarisch'));
        $this->assertSame(['Italienisch', 'Vegetarisch'], $database->categories());
        unlink(filename: $path);
    }

    public function testIncompleteRecipeCannotBeAssigned(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);
        $recipeId = (int) $database->recipes('', 1, 10, 2026, 29)[0]['id'];
        $database->updateIngredients(recipeId: $recipeId, ingredients: [['name' => 'Kartoffeln', 'selected' => null]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Das Rezept muss zuerst vollständig zugeordnet werden.');
        $database->assignRecipe($recipeId, 2026, 29);
    }

    public function testRatingsRemainPerUserAndNotesAreShared(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);
        $recipeId = (int) $database->recipes('', 1, 10, 2026, 29)[0]['id'];

        $database->saveRating($recipeId, '1', 'first@example.org', 2);
        $database->saveRating($recipeId, '1', 'first@example.org', 5);
        $database->saveRating($recipeId, '2', 'second@example.org', 4);
        $database->saveNote($recipeId, '1', 'first@example.org', 'Eigene Notiz');

        $summary = $database->ratingSummary($recipeId);
        $firstUserRecipe = $database->recipes('', 1, 10, 2026, 29, userId: '1')[0];
        $secondUserRecipe = $database->recipes('', 1, 10, 2026, 29, userId: '2')[0];
        $this->assertSame(2, $summary['count']);
        $this->assertSame(4.5, $summary['average']);
        $this->assertSame(5, (int) $firstUserRecipe['personal_rating']);
        $this->assertSame(4, (int) $secondUserRecipe['personal_rating']);
        $this->assertSame('Eigene Notiz', $firstUserRecipe['global_note']);
        $this->assertSame('Eigene Notiz', $secondUserRecipe['global_note']);
        $this->assertSame(2, (int) $firstUserRecipe['community_ratings_count']);

        $database->saveNote($recipeId, '2', 'second@example.org', 'Gemeinsame Notiz');
        $firstUserRecipe = $database->recipes('', 1, 10, 2026, 29, userId: '1')[0];
        $this->assertSame('Gemeinsame Notiz', $firstUserRecipe['global_note']);

        $database->saveNote($recipeId, '1', 'first@example.org', '');
        $secondUserRecipe = $database->recipes('', 1, 10, 2026, 29, userId: '2')[0];
        $this->assertSame('', $secondUserRecipe['global_note']);
        unlink(filename: $path);
    }

    public function testLatestExistingUserNoteIsMigratedToSharedNote(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);
        unset($database);

        $connection = new \PDO(dsn: 'sqlite:' . $path);
        $connection->exec('DROP TABLE recipe_notes');
        $connection->exec(
            <<<'SQL'
                CREATE TABLE recipe_notes (
                    recipe_id INTEGER NOT NULL,
                    user_id TEXT NOT NULL,
                    user_email TEXT NOT NULL,
                    note TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    PRIMARY KEY (recipe_id, user_id)
                )
            SQL
        );
        $connection->exec(
            <<<'SQL'
                INSERT INTO recipe_notes VALUES
                    (1, '1', 'first@example.org', 'Ältere Notiz', '2026-01-01 10:00:00', '2026-01-01 10:00:00'),
                    (1, '2', 'second@example.org', 'Neuere Notiz', '2026-01-02 10:00:00', '2026-01-02 10:00:00')
            SQL
        );
        unset($connection);

        $database = new Database(path: $path);
        $firstUserRecipe = $database->recipes('', 1, 10, 2026, 29, userId: '1')[0];
        $secondUserRecipe = $database->recipes('', 1, 10, 2026, 29, userId: '2')[0];
        $this->assertSame('Neuere Notiz', $firstUserRecipe['global_note']);
        $this->assertSame('Neuere Notiz', $secondUserRecipe['global_note']);
        unlink(filename: $path);
    }

    public function testIngredientDefinitionsKeepExistingProductMappings(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);
        $recipeId = (int) $database->recipes('', 1, 10, 2026, 29)[0]['id'];
        $database->updateIngredients(
            recipeId: $recipeId,
            ingredients: [
                [
                    'source_id' => 'potato',
                    'name' => 'Kartoffeln',
                    'amount' => 400,
                    'unit' => 'g',
                    'selected' => ['listing_id' => 'product-1']
                ]
            ]
        );

        $database->updateIngredientDefinitions(
            sourceId: 'abc',
            ingredients: [
                ['source_id' => 'potato', 'name' => 'Kartoffeln', 'amount' => 500, 'unit' => 'g'],
                ['source_id' => 'onion', 'name' => 'Zwiebel', 'amount' => 1, 'unit' => 'Stück']
            ]
        );

        $recipe = $database->recipes('', 1, 10, 2026, 29)[0];
        $ingredients = json_decode((string) $recipe['ingredients_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(500, $ingredients[0]['amount']);
        $this->assertSame('product-1', $ingredients[0]['selected']['listing_id']);
        $this->assertCount(2, $ingredients);
        unlink(filename: $path);
    }

    public function testRecipeResetRemovesAllConnectedData(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null);
        $recipeId = (int) $database->recipes('', 1, 10, 2026, 29)[0]['id'];
        $database->updateIngredients(
            recipeId: $recipeId,
            ingredients: [['name' => 'Kartoffeln', 'selected' => ['listing_id' => 'product-1']]]
        );
        $database->assignRecipe($recipeId, 2026, 29);
        $database->saveRating($recipeId, '1', 'first@example.org', 5);
        $database->saveNote($recipeId, '1', 'first@example.org', 'Notiz');
        $database->saveIngredientMapping('potato', 'Kartoffeln', [['listing_id' => 'product-1']]);
        $database->saveOrder(2026, 29, 'completed', ['added' => ['product-1']]);
        $database->recordSyncRun(Database::SYNC_RECIPES);
        $database->recordSyncRun(Database::SYNC_INGREDIENTS);

        $syncRunTimes = $database->syncRunTimes();
        $this->assertNotNull($syncRunTimes[Database::SYNC_RECIPES]);
        $this->assertNotNull($syncRunTimes[Database::SYNC_INGREDIENTS]);

        $this->assertSame(1, $database->resetRecipes());
        $this->assertSame(0, $database->recipeCount('', 2026, 29));
        $this->assertSame(0, $database->weekRecipeCount(2026, 29));
        $this->assertSame(0, $database->ratingSummary($recipeId)['count']);
        $this->assertNull($database->ingredientMapping('potato'));
        $this->assertSame(
            [Database::SYNC_RECIPES => null, Database::SYNC_INGREDIENTS => null],
            $database->syncRunTimes()
        );
        unlink(filename: $path);
    }
}
