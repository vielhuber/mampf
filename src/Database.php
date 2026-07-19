<?php
declare(strict_types=1);

namespace Mampf;

use PDOException;
use RuntimeException;
use vielhuber\dbhelper\dbhelper;

final class Database
{
    public const SYNC_RECIPES = 'recipes';
    public const SYNC_INGREDIENTS = 'ingredients';
    public const SYNC_STATUS_SUCCESS = 'success';
    public const SYNC_STATUS_ERROR = 'error';
    public const SYNC_STATUS_CANCELLED = 'cancelled';

    private dbhelper $connection;

    public function __construct(string $path)
    {
        $directory = dirname(path: $path);
        if (!is_dir(filename: $directory)) {
            mkdir(directory: $directory, permissions: 0770, recursive: true);
        }
        $this->connection = new dbhelper();
        $this->connection->connect_with_create('pdo', 'sqlite', $path);
        $this->connection->query('PRAGMA foreign_keys = ON');
        $this->connection->query('PRAGMA journal_mode = WAL');
        $this->migrate();
    }

    public function migrate(): void
    {
        $this->connection->query(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS recipes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source_id TEXT NOT NULL UNIQUE,
                    name TEXT NOT NULL,
                    image_url TEXT NOT NULL,
                    source_url TEXT NOT NULL UNIQUE,
                    pdf_url TEXT,
                    categories_json TEXT NOT NULL DEFAULT '[]',
                    ingredients_json TEXT,
                    ingredient_count INTEGER NOT NULL DEFAULT 0,
                    mapped_ingredient_count INTEGER NOT NULL DEFAULT 0,
                    source_updated_at TEXT,
                    ingredients_scraped_at TEXT,
                    favorites_count INTEGER NOT NULL DEFAULT 0,
                    ratings_count INTEGER NOT NULL DEFAULT 0,
                    average_rating REAL NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS ingredient_mappings (
                    ingredient_key TEXT PRIMARY KEY,
                    query TEXT NOT NULL,
                    products_json TEXT NOT NULL,
                    scraped_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS week_recipes (
                    year INTEGER NOT NULL,
                    week INTEGER NOT NULL,
                    recipe_id INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (year, week, recipe_id),
                    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    year INTEGER NOT NULL,
                    week INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    result_json TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS sync_runs (
                    type TEXT PRIMARY KEY,
                    completed_at TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'success',
                    message TEXT NOT NULL DEFAULT ''
                );

                CREATE TABLE IF NOT EXISTS recipe_ratings (
                    recipe_id INTEGER NOT NULL,
                    user_id TEXT NOT NULL,
                    user_email TEXT NOT NULL,
                    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (recipe_id, user_id),
                    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS recipe_notes (
                    recipe_id INTEGER PRIMARY KEY,
                    user_id TEXT NOT NULL,
                    user_email TEXT NOT NULL,
                    note TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
                );

                CREATE INDEX IF NOT EXISTS recipes_name ON recipes(name);
                CREATE INDEX IF NOT EXISTS week_recipes_week ON week_recipes(year, week);
                CREATE INDEX IF NOT EXISTS recipe_ratings_recipe ON recipe_ratings(recipe_id);
            SQL
        );
        $recipeColumns = array_column(
            array: $this->connection->fetch_all('PRAGMA table_info(recipes)'),
            column_key: 'name'
        );
        $syncRunColumns = array_column(
            array: $this->connection->fetch_all('PRAGMA table_info(sync_runs)'),
            column_key: 'name'
        );
        if (!in_array(needle: 'status', haystack: $syncRunColumns, strict: true)) {
            $this->connection->query("ALTER TABLE sync_runs ADD COLUMN status TEXT NOT NULL DEFAULT 'success'");
        }
        if (!in_array(needle: 'message', haystack: $syncRunColumns, strict: true)) {
            $this->connection->query("ALTER TABLE sync_runs ADD COLUMN message TEXT NOT NULL DEFAULT ''");
        }
        $noteColumns = $this->connection->fetch_all('PRAGMA table_info(recipe_notes)');
        $noteUserIdIsPrimary = false;
        foreach ($noteColumns as $noteColumn) {
            if ((string) $noteColumn['name'] === 'user_id' && (int) $noteColumn['pk'] > 0) {
                $noteUserIdIsPrimary = true;
                break;
            }
        }
        if ($noteUserIdIsPrimary) {
            $this->connection->query('BEGIN IMMEDIATE');
            try {
                $this->connection->query('DROP TABLE IF EXISTS recipe_notes_global');
                $this->connection->query(
                    <<<'SQL'
                        CREATE TABLE recipe_notes_global (
                            recipe_id INTEGER PRIMARY KEY,
                            user_id TEXT NOT NULL,
                            user_email TEXT NOT NULL,
                            note TEXT NOT NULL,
                            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
                        )
                    SQL
                );
                $this->connection->query(
                    <<<'SQL'
                        INSERT OR REPLACE INTO recipe_notes_global (
                            recipe_id, user_id, user_email, note, created_at, updated_at
                        )
                        SELECT recipe_id, user_id, user_email, note, created_at, updated_at
                        FROM recipe_notes
                        ORDER BY updated_at ASC, rowid ASC
                    SQL
                );
                $this->connection->query('DROP TABLE recipe_notes');
                $this->connection->query('ALTER TABLE recipe_notes_global RENAME TO recipe_notes');
                $this->connection->query('COMMIT');
            } catch (PDOException $exception) {
                $this->connection->query('ROLLBACK');
                throw new RuntimeException(message: 'Die Notizen konnten nicht migriert werden.', previous: $exception);
            }
        }
        $requiresIngredientCountBackfill =
            !in_array(needle: 'ingredient_count', haystack: $recipeColumns, strict: true) ||
            !in_array(needle: 'mapped_ingredient_count', haystack: $recipeColumns, strict: true);
        foreach (
            [
                'pdf_url' => 'TEXT',
                'categories_json' => "TEXT NOT NULL DEFAULT '[]'",
                'ingredient_count' => 'INTEGER NOT NULL DEFAULT 0',
                'mapped_ingredient_count' => 'INTEGER NOT NULL DEFAULT 0',
                'favorites_count' => 'INTEGER NOT NULL DEFAULT 0',
                'ratings_count' => 'INTEGER NOT NULL DEFAULT 0',
                'average_rating' => 'REAL NOT NULL DEFAULT 0'
            ]
            as $column => $definition
        ) {
            if (in_array(needle: $column, haystack: $recipeColumns, strict: true)) {
                continue;
            }
            $this->connection->query('ALTER TABLE recipes ADD COLUMN ' . $column . ' ' . $definition);
        }
        if ($requiresIngredientCountBackfill) {
            $this->connection->query(
                <<<'SQL'
                    UPDATE recipes
                    SET ingredient_count = json_array_length(COALESCE(ingredients_json, '[]')),
                        mapped_ingredient_count = (
                            SELECT COUNT(*)
                            FROM json_each(recipes.ingredients_json) AS ingredient
                            WHERE COALESCE(json_extract(ingredient.value, '$.selected.listing_id'), '') <> ''
                        )
                SQL
            );
        }
        $this->connection->query(
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS recipes_ingredient_counts
                    ON recipes(ingredient_count, mapped_ingredient_count);
                CREATE INDEX IF NOT EXISTS recipes_favorites
                    ON recipes(favorites_count DESC, ratings_count DESC, name COLLATE NOCASE);
            SQL
        );
    }

    /** @param list<string> $categories */
    public function upsertRecipe(
        string $sourceId,
        string $name,
        string $imageUrl,
        string $sourceUrl,
        ?string $sourceUpdatedAt,
        int $favoritesCount = 0,
        int $ratingsCount = 0,
        float $averageRating = 0,
        ?string $pdfUrl = null,
        array $categories = []
    ): bool {
        $isNew = $this->connection->fetch_var('SELECT 1 FROM recipes WHERE source_id = ?', $sourceId) === null;
        $this->connection->query(
            <<<'SQL'
                INSERT INTO recipes (
                    source_id,
                    name,
                    image_url,
                    source_url,
                    pdf_url,
                    categories_json,
                    source_updated_at,
                    favorites_count,
                    ratings_count,
                    average_rating
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(source_id) DO UPDATE SET
                    name = excluded.name,
                    image_url = excluded.image_url,
                    source_url = excluded.source_url,
                    pdf_url = excluded.pdf_url,
                    categories_json = excluded.categories_json,
                    source_updated_at = excluded.source_updated_at,
                    favorites_count = excluded.favorites_count,
                    ratings_count = excluded.ratings_count,
                    average_rating = excluded.average_rating,
                    updated_at = CURRENT_TIMESTAMP
            SQL
            ,
            $sourceId,
            $name,
            $imageUrl,
            $sourceUrl,
            $pdfUrl,
            json_encode(value: $categories, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $sourceUpdatedAt,
            max(0, $favoritesCount),
            max(0, $ratingsCount),
            max(0, $averageRating)
        );
        return $isNew;
    }

    /** @return list<array<string, mixed>> */
    public function recipes(
        string $search,
        int $page,
        int $perPage,
        int $year,
        int $week,
        string $ingredientFilter = 'all',
        string $weekFilter = 'all',
        string $category = '',
        string $sort = 'favorites_desc',
        string $userId = ''
    ): array {
        $offset = max(0, $page - 1) * $perPage;
        $ingredientCondition = match ($ingredientFilter) {
            'mapped' => '(recipes.ingredient_count > 0
                AND recipes.mapped_ingredient_count = recipes.ingredient_count)',
            'unmapped' => '(recipes.ingredient_count = 0
                OR recipes.mapped_ingredient_count < recipes.ingredient_count)',
            default => '1 = 1'
        };
        $weekCondition = match ($weekFilter) {
            'selected' => 'week_recipes.recipe_id IS NOT NULL',
            'unselected' => 'week_recipes.recipe_id IS NULL',
            default => '1 = 1'
        };
        $secondaryOrder = match ($sort) {
            'favorites_asc' => 'recipes.favorites_count ASC, recipes.name COLLATE NOCASE ASC',
            'ratings_desc'
                => 'recipes.ratings_count DESC, recipes.average_rating DESC, recipes.name COLLATE NOCASE ASC',
            'ratings_asc' => 'recipes.ratings_count ASC, recipes.average_rating ASC, recipes.name COLLATE NOCASE ASC',
            'rating_desc' => 'recipes.average_rating DESC, recipes.ratings_count DESC, recipes.name COLLATE NOCASE ASC',
            'rating_asc' => 'recipes.average_rating ASC, recipes.ratings_count ASC, recipes.name COLLATE NOCASE ASC',
            'name_asc' => 'recipes.name COLLATE NOCASE ASC',
            'name_desc' => 'recipes.name COLLATE NOCASE DESC',
            'ingredients_desc' => 'ingredient_count DESC, recipes.name COLLATE NOCASE ASC',
            'ingredients_asc' => 'ingredient_count ASC, recipes.name COLLATE NOCASE ASC',
            'source_updated_desc' => "COALESCE(recipes.source_updated_at, '') DESC, recipes.name COLLATE NOCASE ASC",
            'source_updated_asc' => "COALESCE(recipes.source_updated_at, '') ASC, recipes.name COLLATE NOCASE ASC",
            'created_desc' => 'recipes.created_at DESC, recipes.id DESC',
            'created_asc' => 'recipes.created_at ASC, recipes.id ASC',
            'updated_desc' => 'recipes.updated_at DESC, recipes.id DESC',
            'updated_asc' => 'recipes.updated_at ASC, recipes.id ASC',
            'ingredients_updated_desc'
                => "COALESCE(recipes.ingredients_scraped_at, '') DESC, recipes.name COLLATE NOCASE ASC",
            'ingredients_updated_asc'
                => "COALESCE(recipes.ingredients_scraped_at, '') ASC, recipes.name COLLATE NOCASE ASC",
            default => 'recipes.favorites_count DESC, recipes.ratings_count DESC, recipes.name COLLATE NOCASE ASC'
        };
        $searchValue = '%' . $search . '%';
        return $this->connection->fetch_all(
            <<<SQL
                SELECT recipes.*,
                       CASE WHEN week_recipes.recipe_id IS NULL THEN 0 ELSE 1 END AS selected,
                       COALESCE((
                           SELECT rating FROM recipe_ratings
                           WHERE recipe_id = recipes.id AND user_id = ?
                       ), 0) AS personal_rating,
                       COALESCE((
                           SELECT note FROM recipe_notes
                           WHERE recipe_id = recipes.id
                       ), '') AS global_note,
                       COALESCE((
                           SELECT AVG(rating) FROM recipe_ratings WHERE recipe_id = recipes.id
                       ), 0) AS community_average_rating,
                       (SELECT COUNT(*) FROM recipe_ratings WHERE recipe_id = recipes.id) AS community_ratings_count,
                       COALESCE((
                           SELECT json_group_array(json_object('email', user_email, 'rating', rating))
                           FROM recipe_ratings WHERE recipe_id = recipes.id
                       ), '[]') AS community_ratings_json
                FROM recipes
                LEFT JOIN week_recipes
                  ON week_recipes.recipe_id = recipes.id
                 AND week_recipes.year = ?
                 AND week_recipes.week = ?
                WHERE (recipes.name LIKE ? OR recipes.source_id LIKE ? OR recipes.source_url LIKE ?)
                  AND {$ingredientCondition}
                  AND {$weekCondition}
                  AND (? = '' OR EXISTS (
                      SELECT 1 FROM json_each(recipes.categories_json) AS recipe_category
                      WHERE recipe_category.value = ? COLLATE NOCASE
                  ))
                ORDER BY selected DESC, {$secondaryOrder}
                LIMIT ? OFFSET ?
            SQL
            ,
            $userId,
            $year,
            $week,
            $searchValue,
            $searchValue,
            $searchValue,
            $category,
            $category,
            $perPage,
            $offset
        );
    }

    public function recipeCount(
        string $search,
        int $year,
        int $week,
        string $ingredientFilter = 'all',
        string $weekFilter = 'all',
        string $category = ''
    ): int {
        $ingredientCondition = match ($ingredientFilter) {
            'mapped' => '(recipes.ingredient_count > 0
                AND recipes.mapped_ingredient_count = recipes.ingredient_count)',
            'unmapped' => '(recipes.ingredient_count = 0
                OR recipes.mapped_ingredient_count < recipes.ingredient_count)',
            default => '1 = 1'
        };
        $weekCondition = match ($weekFilter) {
            'selected' => 'week_recipes.recipe_id IS NOT NULL',
            'unselected' => 'week_recipes.recipe_id IS NULL',
            default => '1 = 1'
        };
        $searchValue = '%' . $search . '%';
        return (int) $this->connection->fetch_var(
            <<<SQL
                SELECT COUNT(*)
                FROM recipes
                LEFT JOIN week_recipes
                  ON week_recipes.recipe_id = recipes.id
                 AND week_recipes.year = ?
                 AND week_recipes.week = ?
                WHERE (recipes.name LIKE ? OR recipes.source_id LIKE ? OR recipes.source_url LIKE ?)
                  AND {$ingredientCondition}
                  AND {$weekCondition}
                  AND (? = '' OR EXISTS (
                      SELECT 1 FROM json_each(recipes.categories_json) AS recipe_category
                      WHERE recipe_category.value = ? COLLATE NOCASE
                  ))
            SQL
            ,
            $year,
            $week,
            $searchValue,
            $searchValue,
            $searchValue,
            $category,
            $category
        );
    }

    public function mappedIngredientCount(
        string $search,
        int $year,
        int $week,
        string $ingredientFilter = 'all',
        string $weekFilter = 'all',
        string $category = ''
    ): int {
        $ingredientCondition = match ($ingredientFilter) {
            'mapped' => '(recipes.ingredient_count > 0
                AND recipes.mapped_ingredient_count = recipes.ingredient_count)',
            'unmapped' => '(recipes.ingredient_count = 0
                OR recipes.mapped_ingredient_count < recipes.ingredient_count)',
            default => '1 = 1'
        };
        $weekCondition = match ($weekFilter) {
            'selected' => 'week_recipes.recipe_id IS NOT NULL',
            'unselected' => 'week_recipes.recipe_id IS NULL',
            default => '1 = 1'
        };
        $searchValue = '%' . $search . '%';
        return (int) $this->connection->fetch_var(
            <<<SQL
                SELECT COALESCE(SUM(recipes.mapped_ingredient_count), 0)
                FROM recipes
                LEFT JOIN week_recipes
                  ON week_recipes.recipe_id = recipes.id
                 AND week_recipes.year = ?
                 AND week_recipes.week = ?
                WHERE (recipes.name LIKE ? OR recipes.source_id LIKE ? OR recipes.source_url LIKE ?)
                  AND {$ingredientCondition}
                  AND {$weekCondition}
                  AND (? = '' OR EXISTS (
                      SELECT 1 FROM json_each(recipes.categories_json) AS recipe_category
                      WHERE recipe_category.value = ? COLLATE NOCASE
                  ))
            SQL
            ,
            $year,
            $week,
            $searchValue,
            $searchValue,
            $searchValue,
            $category,
            $category
        );
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_map(
            callback: fn(array $category): string => (string) $category['name'],
            array: $this->connection->fetch_all(
                <<<'SQL'
                    SELECT DISTINCT recipe_category.value AS name
                    FROM recipes, json_each(recipes.categories_json) AS recipe_category
                    WHERE recipe_category.type = 'text' AND TRIM(recipe_category.value) <> ''
                    ORDER BY recipe_category.value COLLATE NOCASE
                SQL
            )
        );
    }

    public function weekRecipeCount(int $year, int $week): int
    {
        return (int) $this->connection->fetch_var(
            'SELECT COUNT(*) FROM week_recipes WHERE year = ? AND week = ?',
            $year,
            $week
        );
    }

    /** @return array<string, int> */
    public function weekRecipeCounts(): array
    {
        $rows = $this->connection->fetch_all(
            'SELECT year, week, COUNT(*) AS recipe_count FROM week_recipes GROUP BY year, week'
        );
        $counts = [];
        foreach ($rows as $row) {
            $key = sprintf('%04d-W%02d', (int) $row['year'], (int) $row['week']);
            $counts[$key] = (int) $row['recipe_count'];
        }
        return $counts;
    }

    public function resetRecipes(): int
    {
        $recipeCount = (int) $this->connection->fetch_var('SELECT COUNT(*) FROM recipes');
        $this->connection->query('BEGIN IMMEDIATE');
        try {
            $this->connection->query('DELETE FROM ingredient_mappings');
            $this->connection->query('DELETE FROM orders');
            $this->connection->query('DELETE FROM sync_runs');
            $this->connection->query('DELETE FROM recipes');
            $this->connection->query("DELETE FROM sqlite_sequence WHERE name IN ('recipes', 'orders')");
            $this->connection->query('COMMIT');
        } catch (PDOException $exception) {
            $this->connection->query('ROLLBACK');
            throw new RuntimeException(
                message: 'Die Rezeptdaten konnten nicht zurückgesetzt werden.',
                previous: $exception
            );
        }
        return $recipeCount;
    }

    public function recordSyncRun(string $type, string $status = self::SYNC_STATUS_SUCCESS, string $message = ''): void
    {
        if (!in_array(needle: $type, haystack: [self::SYNC_RECIPES, self::SYNC_INGREDIENTS], strict: true)) {
            throw new RuntimeException(message: 'Ungültiger Synchronisierungstyp.');
        }
        if (
            !in_array(
                needle: $status,
                haystack: [self::SYNC_STATUS_SUCCESS, self::SYNC_STATUS_ERROR, self::SYNC_STATUS_CANCELLED],
                strict: true
            )
        ) {
            throw new RuntimeException(message: 'Ungültiger Synchronisierungsstatus.');
        }
        $this->connection->query(
            <<<'SQL'
                INSERT INTO sync_runs (type, completed_at, status, message)
                VALUES (?, CURRENT_TIMESTAMP, ?, ?)
                ON CONFLICT(type) DO UPDATE SET
                    completed_at = CURRENT_TIMESTAMP,
                    status = excluded.status,
                    message = excluded.message
            SQL
            ,
            $type,
            $status,
            $message
        );
    }

    /** @return array{recipes: array{completed_at: ?string, status: string, message: string}, ingredients: array{completed_at: ?string, status: string, message: string}} */
    public function syncRuns(): array
    {
        $runs = [
            self::SYNC_RECIPES => ['completed_at' => null, 'status' => '', 'message' => ''],
            self::SYNC_INGREDIENTS => ['completed_at' => null, 'status' => '', 'message' => '']
        ];
        foreach ($this->connection->fetch_all('SELECT type, completed_at, status, message FROM sync_runs') as $run) {
            $type = (string) $run['type'];
            if (!array_key_exists(key: $type, array: $runs)) {
                continue;
            }
            $runs[$type] = [
                'completed_at' => (string) $run['completed_at'],
                'status' => (string) $run['status'],
                'message' => (string) $run['message']
            ];
        }
        return $runs;
    }

    /** @return list<array<string, mixed>> */
    public function recipesForIngredientMapping(?int $limit = null): array
    {
        $sql = <<<'SQL'
            SELECT * FROM recipes
            WHERE ingredient_count > 0
              AND mapped_ingredient_count < ingredient_count
            ORDER BY
                COALESCE(ingredients_scraped_at, '') ASC,
                created_at ASC,
                id ASC
        SQL;
        if ($limit === null) {
            return $this->connection->fetch_all($sql);
        }
        return $this->connection->fetch_all($sql . ' LIMIT ?', $limit);
    }

    /** @param list<array<string, mixed>> $ingredients */
    public function updateIngredients(int $recipeId, array $ingredients): void
    {
        $mappedIngredientCount = $this->countMappedIngredients(ingredients: $ingredients);
        $this->connection->query(
            <<<'SQL'
                UPDATE recipes
                SET ingredients_json = ?,
                    ingredient_count = ?,
                    mapped_ingredient_count = ?,
                    ingredients_scraped_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            SQL
            ,
            json_encode(
                value: $ingredients,
                flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            count(value: $ingredients),
            $mappedIngredientCount,
            $recipeId
        );
    }

    /** @param list<array<string, mixed>> $ingredients */
    public function updateIngredientDefinitions(string $sourceId, array $ingredients): void
    {
        $existingJson = $this->connection->fetch_var(
            'SELECT ingredients_json FROM recipes WHERE source_id = ?',
            $sourceId
        );
        $existingIngredients = is_string(value: $existingJson)
            ? json_decode(json: $existingJson, associative: true, flags: JSON_THROW_ON_ERROR)
            : [];
        $existingByKey = [];
        foreach (is_array(value: $existingIngredients) ? $existingIngredients : [] as $ingredient) {
            if (!is_array(value: $ingredient)) {
                continue;
            }
            $key = (string) ($ingredient['source_id'] ?? mb_strtolower(string: (string) ($ingredient['name'] ?? '')));
            $existingByKey[$key] = $ingredient;
        }
        foreach ($ingredients as &$ingredient) {
            $key = (string) ($ingredient['source_id'] ?? mb_strtolower(string: (string) ($ingredient['name'] ?? '')));
            $existing = $existingByKey[$key] ?? null;
            if (!is_array(value: $existing)) {
                continue;
            }
            foreach (['search_url', 'products', 'selected'] as $field) {
                if (array_key_exists(key: $field, array: $existing)) {
                    $ingredient[$field] = $existing[$field];
                }
            }
        }
        unset($ingredient);
        $mappedIngredientCount = $this->countMappedIngredients(ingredients: $ingredients);
        $this->connection->query(
            <<<'SQL'
                UPDATE recipes
                SET ingredients_json = ?,
                    ingredient_count = ?,
                    mapped_ingredient_count = ?,
                    ingredients_scraped_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE source_id = ?
            SQL
            ,
            json_encode(
                value: $ingredients,
                flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            count(value: $ingredients),
            $mappedIngredientCount,
            $sourceId
        );
    }

    public function saveRating(int $recipeId, string $userId, string $userEmail, int $rating): void
    {
        $this->connection->query(
            <<<'SQL'
                INSERT INTO recipe_ratings (recipe_id, user_id, user_email, rating)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(recipe_id, user_id) DO UPDATE SET
                    user_email = excluded.user_email,
                    rating = excluded.rating,
                    updated_at = CURRENT_TIMESTAMP
            SQL
            ,
            $recipeId,
            $userId,
            $userEmail,
            $rating
        );
    }

    public function saveNote(int $recipeId, string $userId, string $userEmail, string $note): void
    {
        if ($note === '') {
            $this->connection->query('DELETE FROM recipe_notes WHERE recipe_id = ?', $recipeId);
            return;
        }
        $this->connection->query(
            <<<'SQL'
                INSERT INTO recipe_notes (recipe_id, user_id, user_email, note)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(recipe_id) DO UPDATE SET
                    user_id = excluded.user_id,
                    user_email = excluded.user_email,
                    note = excluded.note,
                    updated_at = CURRENT_TIMESTAMP
            SQL
            ,
            $recipeId,
            $userId,
            $userEmail,
            $note
        );
    }

    /** @return array{average: float, count: int, ratings: list<array{email: string, rating: int}>} */
    public function ratingSummary(int $recipeId): array
    {
        $ratings = $this->connection->fetch_all(
            'SELECT user_email AS email, rating FROM recipe_ratings WHERE recipe_id = ? ORDER BY updated_at DESC',
            $recipeId
        );
        return [
            'average' =>
                $ratings === []
                    ? 0.0
                    : array_sum(array: array_column(array: $ratings, column_key: 'rating')) / count(value: $ratings),
            'count' => count(value: $ratings),
            'ratings' => array_map(
                callback: fn(array $rating): array => [
                    'email' => (string) $rating['email'],
                    'rating' => (int) $rating['rating']
                ],
                array: $ratings
            )
        ];
    }

    /** @return list<array<string, mixed>>|null */
    public function ingredientMapping(string $key, ?int $maxAgeSeconds = null): ?array
    {
        $json =
            $maxAgeSeconds === null
                ? $this->connection->fetch_var(
                    'SELECT products_json FROM ingredient_mappings WHERE ingredient_key = ?',
                    $key
                )
                : $this->connection->fetch_var(
                    "SELECT products_json FROM ingredient_mappings
                 WHERE ingredient_key = ? AND scraped_at >= datetime('now', ?)",
                    $key,
                    '-' . max(0, $maxAgeSeconds) . ' seconds'
                );
        if (!is_string(value: $json)) {
            return null;
        }
        $products = json_decode(json: $json, associative: true, flags: JSON_THROW_ON_ERROR);
        return is_array(value: $products) ? $products : null;
    }

    /** @param list<array<string, mixed>> $products */
    public function saveIngredientMapping(string $key, string $query, array $products): void
    {
        $this->connection->query(
            <<<'SQL'
                INSERT INTO ingredient_mappings (ingredient_key, query, products_json, scraped_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(ingredient_key) DO UPDATE SET
                    query = excluded.query,
                    products_json = excluded.products_json,
                    scraped_at = CURRENT_TIMESTAMP
            SQL
            ,
            $key,
            $query,
            json_encode(value: $products, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function assignRecipe(int $recipeId, int $year, int $week): void
    {
        $isComplete = (int) $this->connection->fetch_var(
            <<<'SQL'
                SELECT COUNT(*)
                FROM recipes
                WHERE id = ?
                  AND ingredient_count > 0
                  AND mapped_ingredient_count = ingredient_count
            SQL
            ,
            $recipeId
        );
        if ($isComplete !== 1) {
            throw new RuntimeException(message: 'Das Rezept muss zuerst vollständig zugeordnet werden.');
        }
        $this->connection->query(
            <<<'SQL'
                INSERT OR IGNORE INTO week_recipes (year, week, recipe_id)
                VALUES (?, ?, ?)
            SQL
            ,
            $year,
            $week,
            $recipeId
        );
    }

    public function removeRecipe(int $recipeId, int $year, int $week): void
    {
        $this->connection->query(
            'DELETE FROM week_recipes WHERE year = ? AND week = ? AND recipe_id = ?',
            $year,
            $week,
            $recipeId
        );
    }

    public function deleteRecipe(string $sourceId): void
    {
        $this->connection->query('DELETE FROM recipes WHERE source_id = ?', $sourceId);
    }

    /** @return list<array<string, mixed>> */
    public function recipesForWeek(int $year, int $week): array
    {
        return $this->connection->fetch_all(
            <<<'SQL'
                SELECT recipes.* FROM recipes
                INNER JOIN week_recipes ON week_recipes.recipe_id = recipes.id
                WHERE week_recipes.year = ? AND week_recipes.week = ?
                ORDER BY recipes.name ASC
            SQL
            ,
            $year,
            $week
        );
    }

    /** @param array<string, mixed> $result */
    public function saveOrder(int $year, int $week, string $status, array $result): int
    {
        $this->connection->query(
            <<<'SQL'
                INSERT INTO orders (year, week, status, result_json)
                VALUES (?, ?, ?, ?)
            SQL
            ,
            $year,
            $week,
            $status,
            json_encode(value: $result, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        return (int) $this->connection->last_insert_id();
    }

    /** @param list<array<string, mixed>> $ingredients */
    private function countMappedIngredients(array $ingredients): int
    {
        return count(
            value: array_filter(
                array: $ingredients,
                callback: fn(mixed $ingredient): bool => is_array(value: $ingredient) &&
                    trim(string: (string) ($ingredient['selected']['listing_id'] ?? '')) !== ''
            )
        );
    }
}
