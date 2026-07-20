<?php
declare(strict_types=1);

namespace Mampf;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class HelloFreshScraper
{
    private const RECIPES_URL = 'https://www.hellofresh.de/recipes';
    private const SITEMAP_URL = 'https://www.hellofresh.de/sitemap_recipe_pages.xml';
    private const API_URL = 'https://www.hellofresh.de/gw/recipes/recipes';
    private const BATCH_SIZE = 250;
    private const API_REQUEST_ATTEMPTS = 3;
    private const IMAGE_URL = 'https://media.hellofresh.com/c_fit,f_auto,fl_lossy,h_400,q_80,w_800/hellofresh_s3/image/';
    private const CATEGORY_ALIASES = [
        'american' => 'Amerikanisch',
        'argentinsk' => 'Argentinisch',
        'austrian' => 'Österreichisch',
        'belgisk' => 'Belgisch',
        'cajunsk' => 'Cajun',
        'calorie smart' => 'Unter 650 Kalorien',
        'cambodian' => 'Kambodschanisch',
        'dansk' => 'Dänisch',
        'family' => 'Familienfreundlich',
        'fettarm' => 'Fettarm',
        'ghanaian' => 'Ghanaisch',
        'high protein' => 'Proteinreich',
        'hawaiian' => 'Hawaiianisch',
        'israelisk' => 'Israelisch',
        'ivorian' => 'Ivorisch',
        'jamaicansk' => 'Jamaikanisch',
        'kalorien im blick' => 'Unter 650 Kalorien',
        'kanadensisk' => 'Kanadisch',
        'latin american' => 'Lateinamerikanisch',
        'malaysisk' => 'Malaysisch',
        'middle eastern' => 'Nahöstlich',
        'moroccan' => 'Marokkanisch',
        'nedeländsk' => 'Niederländisch',
        'nepalese' => 'Nepalesisch',
        'north american' => 'Nordamerikanisch',
        'palestinian' => 'Palästinensisch',
        'peruansk' => 'Peruanisch',
        'quick cook' => 'Zeit sparen',
        'scharf' => 'Scharf',
        'skandinavisk' => 'Skandinavisch',
        'sri lankesisk' => 'Sri-lankisch',
        'svensk' => 'Schwedisch',
        'syrian' => 'Syrisch',
        'ungersk' => 'Ungarisch',
        'uzbek' => 'Usbekisch',
        'vegetarian' => 'Vegetarisch',
        'veggie' => 'Vegetarisch',
        'western european' => 'Westeuropäisch'
    ];

    private ?string $accessToken = null;

    public function __construct(private readonly Database $database, private readonly HttpClient $httpClient) {}

    /**
     * Import every public recipe found in the official sitemap.
     *
     * @return array{created: int, updated: int, public: int, scanned: int, unresolved: int, filtered: int}
     */
    public function scrapeRecipes(?callable $progress = null, ?callable $checkpoint = null): array
    {
        $sitemapResponse = $this->httpClient->request(url: self::SITEMAP_URL);
        if ($sitemapResponse->status !== 200) {
            throw new RuntimeException(
                message: 'Die HelloFresh-Sitemap antwortete mit HTTP ' . $sitemapResponse->status . '.'
            );
        }
        $sitemap = new DOMDocument();
        if (!$sitemap->loadXML(source: $sitemapResponse->body)) {
            throw new RuntimeException(message: 'Die HelloFresh-Sitemap konnte nicht gelesen werden.');
        }
        $publicRecipes = [];
        foreach ($sitemap->getElementsByTagName(qualifiedName: 'loc') as $location) {
            $url = trim(string: $location->textContent);
            if (str_contains(haystack: $url, needle: '/recipes/market/')) {
                continue;
            }
            if (preg_match(pattern: '~([a-f0-9]{24})(?:[/?#]|$)~i', subject: $url, matches: $matches) !== 1) {
                continue;
            }
            $publicRecipes[strtolower(string: $matches[1])] = $url;
        }
        if ($publicRecipes === []) {
            throw new RuntimeException(message: 'Die HelloFresh-Sitemap enthält keine Rezept-IDs.');
        }

        $created = 0;
        $updated = 0;
        $filtered = 0;
        $scanned = 0;
        $skip = 0;
        $total = 1;
        while ($skip < $total && $skip < 10000) {
            $checkpoint?->__invoke();
            $url =
                self::API_URL .
                '/search?' .
                http_build_query(
                    data: [
                        'country' => 'DE',
                        'locale' => 'de-DE',
                        'take' => self::BATCH_SIZE,
                        'skip' => $skip
                    ]
                );
            $response = $this->httpClient->request(url: $url, headers: $this->apiHeaders());
            $requestAttempts = 1;
            for (
                $attempt = 2;
                $attempt <= self::API_REQUEST_ATTEMPTS && ($response->status === 429 || $response->status >= 500);
                $attempt++
            ) {
                sleep(seconds: $attempt - 1);
                $checkpoint?->__invoke();
                $response = $this->httpClient->request(url: $url, headers: $this->apiHeaders());
                $requestAttempts = $attempt;
            }
            if ($response->status !== 200) {
                throw new RuntimeException(
                    message: 'Die HelloFresh-Rezept-API antwortete' .
                        ($requestAttempts > 1 ? ' nach ' . $requestAttempts . ' Versuchen' : '') .
                        ' mit HTTP ' .
                        $response->status .
                        '.'
                );
            }
            $data = $response->json();
            $items = is_array(value: $data['items'] ?? null) ? $data['items'] : [];
            $total = max(0, (int) ($data['total'] ?? 0));
            $imageResponses = $this->imageResponses(items: $items, publicRecipes: $publicRecipes);
            foreach ($items as $item) {
                if (!is_array(value: $item)) {
                    continue;
                }
                $sourceId = strtolower(string: (string) ($item['id'] ?? ''));
                if (!isset($publicRecipes[$sourceId])) {
                    continue;
                }
                $name = trim(string: (string) ($item['name'] ?? ''));
                $imageUrl = $this->imageUrl(recipe: $item);
                $ingredients = $this->ingredientDefinitions(recipe: $item);
                if (
                    ($item['isAddon'] ?? false) === true ||
                    $name === '' ||
                    $imageUrl === null ||
                    $ingredients === [] ||
                    $this->isTestRecipe(name: $name) ||
                    $this->imageIsMissing(imageUrl: $imageUrl, responses: $imageResponses)
                ) {
                    $this->database->deleteRecipe(sourceId: $sourceId);
                    unset($publicRecipes[$sourceId]);
                    $filtered++;
                    continue;
                }
                $isNew = $this->database->upsertRecipe(
                    sourceId: $sourceId,
                    name: $name,
                    imageUrl: $imageUrl,
                    sourceUrl: $publicRecipes[$sourceId],
                    sourceUpdatedAt: isset($item['updatedAt']) ? (string) $item['updatedAt'] : null,
                    favoritesCount: (int) ($item['favoritesCount'] ?? 0),
                    ratingsCount: (int) ($item['ratingsCount'] ?? 0),
                    averageRating: (float) ($item['averageRating'] ?? 0),
                    pdfUrl: $this->pdfUrl(recipe: $item),
                    categories: $this->categories(recipe: $item)
                );
                $this->database->updateIngredientDefinitions(sourceId: $sourceId, ingredients: $ingredients);
                $isNew ? $created++ : $updated++;
                unset($publicRecipes[$sourceId]);
            }
            $scanned += count(value: $items);
            $skip += self::BATCH_SIZE;
            $progress?->__invoke($scanned, $total, $created, $updated);
            if ($items === []) {
                break;
            }
        }
        $remainingTotal = count(value: $publicRecipes);
        for ($attempt = 1; $attempt <= 3 && $publicRecipes !== []; $attempt++) {
            foreach (array_chunk(array: array_keys(array: $publicRecipes), length: 120) as $chunk) {
                $checkpoint?->__invoke();
                $urls = array_map(
                    callback: fn(string $sourceId): string => self::API_URL .
                        '/' .
                        rawurlencode(string: $sourceId) .
                        '?country=DE&locale=de-DE',
                    array: $chunk
                );
                $responses = $this->httpClient->requestMany(
                    urls: $urls,
                    headers: $this->apiHeaders(),
                    concurrency: 32,
                    connectTimeout: 5,
                    timeout: 15
                );
                $detailItems = [];
                foreach ($chunk as $sourceId) {
                    $url = self::API_URL . '/' . rawurlencode(string: $sourceId) . '?country=DE&locale=de-DE';
                    $response = $responses[$url] ?? null;
                    if ($attempt === 1) {
                        $scanned++;
                    }
                    if ($response instanceof HttpResponse && in_array($response->status, [404, 410], true)) {
                        $this->database->deleteRecipe(sourceId: $sourceId);
                        unset($publicRecipes[$sourceId]);
                        $filtered++;
                        continue;
                    }
                    if (!($response instanceof HttpResponse) || $response->status !== 200) {
                        continue;
                    }
                    $detailItems[$sourceId] = $response->json();
                }
                $imageResponses = $this->imageResponses(
                    items: array_values(array: $detailItems),
                    publicRecipes: $publicRecipes
                );
                foreach ($detailItems as $sourceId => $item) {
                    $name = trim(string: (string) ($item['name'] ?? ''));
                    $imageUrl = $this->imageUrl(recipe: $item);
                    $ingredients = $this->ingredientDefinitions(recipe: $item);
                    if (
                        ($item['isAddon'] ?? false) === true ||
                        $name === '' ||
                        $imageUrl === null ||
                        $ingredients === [] ||
                        $this->isTestRecipe(name: $name) ||
                        $this->imageIsMissing(imageUrl: $imageUrl, responses: $imageResponses)
                    ) {
                        $this->database->deleteRecipe(sourceId: $sourceId);
                        unset($publicRecipes[$sourceId]);
                        $filtered++;
                        continue;
                    }
                    $isNew = $this->database->upsertRecipe(
                        sourceId: $sourceId,
                        name: $name,
                        imageUrl: $imageUrl,
                        sourceUrl: $publicRecipes[$sourceId],
                        sourceUpdatedAt: isset($item['updatedAt']) ? (string) $item['updatedAt'] : null,
                        favoritesCount: (int) ($item['favoritesCount'] ?? 0),
                        ratingsCount: (int) ($item['ratingsCount'] ?? 0),
                        averageRating: (float) ($item['averageRating'] ?? 0),
                        pdfUrl: $this->pdfUrl(recipe: $item),
                        categories: $this->categories(recipe: $item)
                    );
                    $this->database->updateIngredientDefinitions(sourceId: $sourceId, ingredients: $ingredients);
                    $isNew ? $created++ : $updated++;
                    unset($publicRecipes[$sourceId]);
                }
                $progress?->__invoke($scanned, 10000 + $remainingTotal, $created, $updated);
            }
            if ($publicRecipes !== []) {
                $this->accessToken = null;
                sleep(seconds: $attempt);
            }
        }
        return [
            'created' => $created,
            'updated' => $updated,
            'public' => $created + $updated,
            'scanned' => $scanned,
            'unresolved' => count(value: $publicRecipes),
            'filtered' => $filtered
        ];
    }

    /**
     * Enrich recipes with shipped ingredients and matching REWE products.
     *
     * @return array{processed: int, failed: int, errors: list<string>}
     */
    public function scrapeIngredients(
        ReweClient $reweClient,
        ?int $limit = null,
        ?callable $progress = null,
        ?callable $checkpoint = null
    ): array {
        $processed = 0;
        $recipes = $this->database->recipesForIngredientMapping(limit: $limit);
        foreach ($recipes as $recipe) {
            try {
                $checkpoint?->__invoke();
                $ingredients = json_decode(json: (string) ($recipe['ingredients_json'] ?? ''), associative: true);
                if (!is_array(value: $ingredients) || $ingredients === []) {
                    throw new RuntimeException(
                        message: 'Keine HelloFresh-Zutaten vorhanden. Bitte zuerst die Rezepte aktualisieren.'
                    );
                }
                $ingredientsChanged = false;
                foreach ($ingredients as &$ingredient) {
                    $checkpoint?->__invoke();
                    if (!is_array(value: $ingredient)) {
                        continue;
                    }
                    $name = trim(string: (string) ($ingredient['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $previousIngredient = $ingredient;
                    $previousListingId = trim(string: (string) ($ingredient['selected']['listing_id'] ?? ''));
                    $products = $reweClient->productsForIngredient(name: $name);
                    $ingredient['search_url'] = $reweClient->searchUrl(query: $name);
                    $ingredient['products'] = $products;
                    $ingredient['selected'] = $products[0] ?? null;
                    foreach ($products as $product) {
                        if ((string) ($product['listing_id'] ?? '') === $previousListingId) {
                            $ingredient['selected'] = $product;
                            break;
                        }
                    }
                    if ($ingredient === $previousIngredient) {
                        continue;
                    }
                    $ingredientsChanged = true;
                }
                unset($ingredient);
                if ($ingredientsChanged) {
                    $this->database->updateIngredients(recipeId: (int) $recipe['id'], ingredients: $ingredients);
                }
                $processed++;
                if ($processed % 10 === 0 || $processed === count(value: $recipes)) {
                    $progress?->__invoke((string) $recipe['name'], true, $processed, count(value: $recipes));
                }
            } catch (TaskCancelledException $exception) {
                throw $exception;
            } catch (RuntimeException $exception) {
                $progress?->__invoke(
                    (string) $recipe['name'],
                    false,
                    $processed + 1,
                    count(value: $recipes),
                    $exception->getMessage()
                );
                throw new RuntimeException(
                    message: (string) $recipe['name'] . ': ' . $exception->getMessage(),
                    previous: $exception
                );
            }
        }
        return ['processed' => $processed, 'failed' => 0, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $recipe
     * @return list<array<string, mixed>>
     */
    private function ingredientDefinitions(array $recipe): array
    {
        $amounts = [];
        foreach ($recipe['yields'] ?? [] as $yield) {
            if (!is_array(value: $yield) || (int) ($yield['yields'] ?? 0) !== 3) {
                continue;
            }
            foreach ($yield['ingredients'] ?? [] as $amount) {
                if (is_array(value: $amount) && isset($amount['id'])) {
                    $amounts[(string) $amount['id']] = $amount;
                }
            }
        }
        $ingredients = [];
        foreach ($recipe['ingredients'] ?? [] as $ingredient) {
            if (!is_array(value: $ingredient) || ($ingredient['shipped'] ?? false) !== true) {
                continue;
            }
            $sourceId = (string) ($ingredient['id'] ?? '');
            $name = trim(string: (string) ($ingredient['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $amount = $amounts[$sourceId] ?? [];
            $ingredients[] = [
                'source_id' => $sourceId,
                'name' => $name,
                'amount' => $amount['amount'] ?? null,
                'unit' => $amount['unit'] ?? null
            ];
        }
        return $ingredients;
    }

    /**
     * @param array<string, mixed> $recipe
     * @return list<string>
     */
    private function categories(array $recipe): array
    {
        $categories = [];
        foreach ($recipe['cuisines'] ?? [] as $cuisine) {
            if (!is_array(value: $cuisine)) {
                continue;
            }
            $name = $this->categoryName(name: (string) ($cuisine['name'] ?? ''));
            if ($name !== '') {
                $categories[$name] = true;
            }
        }
        foreach ($recipe['tags'] ?? [] as $tag) {
            if (!is_array(value: $tag) || ($tag['displayLabel'] ?? false) !== true) {
                continue;
            }
            $name = $this->categoryName(name: (string) ($tag['name'] ?? ''));
            if ($name !== '') {
                $categories[$name] = true;
            }
        }
        $names = array_keys(array: $categories);
        natcasesort(array: $names);
        return array_values(array: $names);
    }

    private function categoryName(string $name): string
    {
        $name = trim(string: $name);
        return self::CATEGORY_ALIASES[mb_strtolower(string: $name)] ?? $name;
    }

    /** @return list<string> */
    private function apiHeaders(): array
    {
        if ($this->accessToken !== null) {
            return ['Accept: application/json', 'Authorization: Bearer ' . $this->accessToken];
        }
        $response = $this->httpClient->request(url: self::RECIPES_URL);
        if ($response->status !== 200) {
            throw new RuntimeException(
                message: 'Die HelloFresh-Rezeptseite antwortete mit HTTP ' . $response->status . '.'
            );
        }
        $document = new DOMDocument();
        libxml_use_internal_errors(use_errors: true);
        $document->loadHTML(source: $response->body);
        libxml_clear_errors();
        $node = new DOMXPath(document: $document)->query(expression: '//script[@id="__NEXT_DATA__"]')?->item(index: 0);
        $data = json_decode(json: $node?->textContent ?? '', associative: true);
        $token = $data['props']['pageProps']['ssrPayload']['serverAuth']['access_token'] ?? null;
        if (!is_string(value: $token) || $token === '') {
            throw new RuntimeException(message: 'Das HelloFresh-Zugriffstoken konnte nicht gefunden werden.');
        }
        $this->accessToken = $token;
        return ['Accept: application/json', 'Authorization: Bearer ' . $token];
    }

    /** @param array<string, mixed> $recipe */
    private function imageUrl(array $recipe): ?string
    {
        $imageUrl = trim(string: (string) ($recipe['imageLink'] ?? ''));
        if ($imageUrl === '') {
            return null;
        }
        if (parse_url(url: $imageUrl, component: PHP_URL_HOST) === 'd3hvwccx09j84u.cloudfront.net') {
            return self::IMAGE_URL . basename(path: (string) parse_url(url: $imageUrl, component: PHP_URL_PATH));
        }
        return $imageUrl;
    }

    /** @param array<string, mixed> $recipe */
    private function pdfUrl(array $recipe): ?string
    {
        $pdfUrl = trim(string: (string) ($recipe['cardLink'] ?? ''));
        if ($pdfUrl === '') {
            return null;
        }
        $host = strtolower(string: (string) parse_url(url: $pdfUrl, component: PHP_URL_HOST));
        $path = strtolower(string: (string) parse_url(url: $pdfUrl, component: PHP_URL_PATH));
        if ($host !== 'www.hellofresh.de' || !str_ends_with(haystack: $path, needle: '.pdf')) {
            return null;
        }
        return $pdfUrl;
    }

    private function isTestRecipe(string $name): bool
    {
        $normalizedName = mb_strtolower(string: $name);
        return str_contains(haystack: $normalizedName, needle: 'test') ||
            str_contains(haystack: $normalizedName, needle: 'backup') ||
            str_contains(haystack: $normalizedName, needle: 'dummy');
    }

    /**
     * @param list<mixed> $items
     * @param array<string, string> $publicRecipes
     * @return array<string, HttpResponse>
     */
    private function imageResponses(array $items, array $publicRecipes): array
    {
        $imageUrls = [];
        foreach ($items as $item) {
            if (!is_array(value: $item)) {
                continue;
            }
            $sourceId = strtolower(string: (string) ($item['id'] ?? ''));
            $imageUrl = $this->imageUrl(recipe: $item);
            if (!isset($publicRecipes[$sourceId]) || $imageUrl === null) {
                continue;
            }
            $imageUrls[$imageUrl] = $imageUrl;
        }
        return $this->httpClient->requestMany(urls: array_values(array: $imageUrls), concurrency: 32, head: true);
    }

    /** @param array<string, HttpResponse> $responses */
    private function imageIsMissing(string $imageUrl, array $responses): bool
    {
        $response = $responses[$imageUrl] ?? null;
        return $response instanceof HttpResponse && $response->status >= 400 && $response->status < 500;
    }
}
