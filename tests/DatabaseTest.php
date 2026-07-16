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

        $this->assertTrue($database->upsertRecipe('abc', 'First', 'image', 'https://example.org/abc', null, 2, 1, 4));
        $this->assertFalse(
            $database->upsertRecipe('abc', 'Updated', 'image-2', 'https://example.org/abc', null, 7, 3, 4.5)
        );
        $this->assertSame(1, $database->recipeCount(search: '', year: 2026, week: 29));
        $this->assertSame('Updated', $database->recipes('', 1, 10, 2026, 29)[0]['name']);
        $this->assertSame(7, $database->recipes('', 1, 10, 2026, 29)[0]['favorites_count']);

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

        $this->assertCount(1, $database->recipesForWeek(2026, 29));
        $this->assertSame(1, $database->weekRecipeCount(2026, 29));
        unlink(filename: $path);
    }

    public function testRecipesCanBeFilteredAndSorted(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->upsertRecipe('a', 'Alpha', 'image', 'https://example.org/a', '2026-01-01', 1, 1, 3);
        $database->upsertRecipe('b', 'Beta', 'image', 'https://example.org/b', '2026-02-01', 8, 4, 4.5);
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
        $mappingRecipes = $database->recipesForIngredientMapping();

        $this->assertSame('Beta', $mapped[0]['name']);
        $this->assertSame('Alpha', $unmapped[0]['name']);
        $this->assertSame('Alpha', $selected[0]['name']);
        $this->assertSame('Alpha', $sorted[0]['name']);
        $this->assertSame('Beta', $popular[0]['name']);
        $this->assertSame('Alpha', $mappingRecipes[0]['name']);
        $this->assertCount(2, $mappingRecipes);
        $this->assertSame(3, $database->mappedIngredientCount('', 2026, 29));
        $this->assertSame(1, $database->mappedIngredientCount('Alpha', 2026, 29));
        $this->assertSame(2, $database->mappedIngredientCount('', 2026, 29, ingredientFilter: 'mapped'));
        $this->assertSame(1, $database->mappedIngredientCount('', 2026, 29, weekFilter: 'selected'));
        $this->assertSame(2, $database->recipeCount('', 2026, 29));
        $this->assertSame(1, $database->recipeCount('', 2026, 29, ingredientFilter: 'mapped'));
        $this->assertSame(1, $database->recipeCount('', 2026, 29, weekFilter: 'selected'));
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

    public function testRatingsAndNotesAreUniquePerUser(): void
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
        $recipe = $database->recipes('', 1, 10, 2026, 29, userId: '1')[0];
        $this->assertSame(2, $summary['count']);
        $this->assertSame(4.5, $summary['average']);
        $this->assertSame(5, (int) $recipe['personal_rating']);
        $this->assertSame('Eigene Notiz', $recipe['personal_note']);
        $this->assertSame(2, (int) $recipe['community_ratings_count']);

        $database->saveNote($recipeId, '1', 'first@example.org', '');
        $recipe = $database->recipes('', 1, 10, 2026, 29, userId: '1')[0];
        $this->assertSame('', $recipe['personal_note']);
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

        $this->assertSame(1, $database->resetRecipes());
        $this->assertSame(0, $database->recipeCount('', 2026, 29));
        $this->assertSame(0, $database->weekRecipeCount(2026, 29));
        $this->assertSame(0, $database->ratingSummary($recipeId)['count']);
        $this->assertNull($database->ingredientMapping('potato'));
        unlink(filename: $path);
    }
}
