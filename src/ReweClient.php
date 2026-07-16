<?php
declare(strict_types=1);

namespace Mampf;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class ReweClient
{
    private const BASE_URL = 'https://www.rewe.de';
    private const SEARCH_URL = self::BASE_URL . '/shop/productList';
    private const BASKET_URL = self::BASE_URL . '/shop/checkout/basket';
    private const CACHE_TTL_SECONDS = 60 * 60 * 6;

    /** @var array<string, list<array<string, mixed>>> */
    private array $productsByIngredient = [];

    public function __construct(
        private readonly Database $database,
        private readonly HttpClient $httpClient,
        private readonly string $cookieFile
    ) {}

    public function searchUrl(string $query): string
    {
        return self::SEARCH_URL . '?' . http_build_query(data: ['search' => $query]);
    }

    /** @return list<array<string, mixed>> */
    public function productsForIngredient(string $name, bool $refresh = false): array
    {
        $key = $this->normalize(value: $name);
        if (array_key_exists(key: $key, array: $this->productsByIngredient)) {
            return $this->productsByIngredient[$key];
        }
        if (!$refresh) {
            $cached = $this->database->ingredientMapping(key: $key, maxAgeSeconds: self::CACHE_TTL_SECONDS);
            if ($cached !== null) {
                $this->productsByIngredient[$key] = $cached;
                return $cached;
            }
        }
        usleep(microseconds: 500000);
        $response = $this->request(url: $this->searchUrl(query: $name));
        if ($response->status !== 200) {
            throw new RuntimeException(
                message: 'Die REWE-Suche antwortete mit HTTP ' . $response->status . '. Prüfe den exportierten Cookie.'
            );
        }
        $products = $this->parseProducts(html: $response->body, query: $name);
        $this->database->saveIngredientMapping(key: $key, query: $name, products: $products);
        $this->productsByIngredient[$key] = $products;
        return $products;
    }

    /**
     * Replace the current REWE basket with ingredients from one calendar week.
     *
     * @return array<string, mixed>
     */
    public function orderWeek(int $year, int $week, ?callable $progress = null): array
    {
        $recipes = $this->database->recipesForWeek(year: $year, week: $week);
        if ($recipes === []) {
            throw new RuntimeException(message: 'Dieser Woche sind keine Rezepte zugeordnet.');
        }
        $items = [];
        $missing = [];
        foreach ($recipes as $recipe) {
            $ingredients = json_decode(json: (string) ($recipe['ingredients_json'] ?? ''), associative: true);
            if (!is_array(value: $ingredients) || $ingredients === []) {
                $missing[] = (string) $recipe['name'] . ': Zutaten nicht importiert';
                continue;
            }
            foreach ($ingredients as &$ingredient) {
                if (!is_array(value: $ingredient)) {
                    continue;
                }
                $name = trim(string: (string) ($ingredient['name'] ?? ''));
                if ($name === '') {
                    $missing[] = (string) $recipe['name'] . ': unbekannte Zutat';
                    continue;
                }
                $previousListingId = trim(string: (string) ($ingredient['selected']['listing_id'] ?? ''));
                $products = $this->productsForIngredient(name: $name, refresh: true);
                $ingredient['search_url'] = $this->searchUrl(query: $name);
                $ingredient['products'] = $products;
                $ingredient['selected'] = $products[0] ?? null;
                foreach ($products as $product) {
                    if ((string) ($product['listing_id'] ?? '') === $previousListingId) {
                        $ingredient['selected'] = $product;
                        break;
                    }
                }
                $selected = is_array(value: $ingredient['selected']) ? $ingredient['selected'] : null;
                $listingId = is_array(value: $selected) ? trim(string: (string) ($selected['listing_id'] ?? '')) : '';
                if ($listingId === '') {
                    $missing[] = (string) $recipe['name'] . ': ' . $name;
                    continue;
                }
                if (!isset($items[$listingId])) {
                    $items[$listingId] = [
                        'listing_id' => $listingId,
                        'name' => (string) ($selected['name'] ?? ''),
                        'url' => (string) ($selected['url'] ?? ''),
                        'quantity' => 0
                    ];
                }
                $items[$listingId]['quantity']++;
            }
            unset($ingredient);
            $this->database->updateIngredients(recipeId: (int) $recipe['id'], ingredients: $ingredients);
        }
        if ($missing !== []) {
            throw new RuntimeException(
                message: 'Die Bestellung wurde vor dem Leeren des Warenkorbs abgebrochen. Fehlende REWE-Zuordnungen: ' .
                    implode(separator: ', ', array: $missing)
            );
        }

        $basketResponse = $this->request(url: self::BASKET_URL);
        if ($basketResponse->status !== 200) {
            throw new RuntimeException(
                message: 'Der REWE-Warenkorb antwortete mit HTTP ' . $basketResponse->status . '.'
            );
        }
        $basket = $this->parseBasket(html: $basketResponse->body);
        if (!$basket['logged_in']) {
            $this->request(url: self::BASE_URL . '/mydata/login');
            $basketResponse = $this->request(url: self::BASKET_URL);
            if ($basketResponse->status !== 200) {
                throw new RuntimeException(
                    message: 'Der REWE-Warenkorb antwortete mit HTTP ' . $basketResponse->status . '.'
                );
            }
            $basket = $this->parseBasket(html: $basketResponse->body);
        }
        if (!$basket['logged_in']) {
            throw new RuntimeException(
                message: 'Die REWE-Sitzung konnte nicht erneuert werden. Exportiere rewe-shop.json und rewe-account.json erneut.'
            );
        }
        if ($basket['listing_ids'] !== [] && $basket['id'] === '') {
            throw new RuntimeException(message: 'Der bestehende REWE-Warenkorb konnte nicht gelesen werden.');
        }
        $totalSteps = 1 + count(value: $basket['listing_ids']) + count(value: $items);
        $currentStep = 1;
        $progress?->__invoke($currentStep, $totalSteps, 'REWE-Warenkorb wurde geladen.');
        foreach ($basket['listing_ids'] as $listingId) {
            $response = $this->request(
                url: self::BASE_URL .
                    '/shop/api/baskets/' .
                    rawurlencode(string: $basket['id']) .
                    '/listings/' .
                    rawurlencode(string: $listingId) .
                    '?includeTimeslot=true',
                method: 'DELETE',
                headers: $this->basketHeaders()
            );
            if (!in_array(needle: $response->status, haystack: [200, 204], strict: true)) {
                throw new RuntimeException(
                    message: 'Das Produkt ' . $listingId . ' konnte nicht aus dem REWE-Warenkorb entfernt werden.'
                );
            }
            $currentStep++;
            $progress?->__invoke($currentStep, $totalSteps, 'Vorhandenes Produkt wurde entfernt.');
        }
        foreach ($items as $item) {
            $response = $this->request(
                url: self::BASE_URL .
                    '/shop/api/baskets/listings/' .
                    rawurlencode(string: (string) $item['listing_id']),
                method: 'POST',
                headers: $this->basketHeaders(),
                body: json_encode(
                    value: [
                        'quantity' => $item['quantity'],
                        'includeTimeslot' => false,
                        'context' => 'product-list'
                    ],
                    flags: JSON_THROW_ON_ERROR
                )
            );
            if (!in_array(needle: $response->status, haystack: [200, 201, 204], strict: true)) {
                throw new RuntimeException(
                    message: $item['name'] .
                        ' konnte nicht zum REWE-Warenkorb hinzugefügt werden (HTTP ' .
                        $response->status .
                        ').'
                );
            }
            $currentStep++;
            $progress?->__invoke($currentStep, $totalSteps, $item['name'] . ' wurde hinzugefügt.');
        }
        return [
            'basket_id' => $basket['id'],
            'removed' => count(value: $basket['listing_ids']),
            'added' => array_values(array: $items)
        ];
    }

    /** @return list<array<string, mixed>> */
    public function parseProducts(string $html, string $query): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(use_errors: true);
        $document->loadHTML(source: $html);
        libxml_clear_errors();
        $xpath = new DOMXPath(document: $document);
        $nodes = $xpath->query(expression: '//*[@id and starts-with(@id, "plr-")]');
        $products = [];
        foreach ($nodes ?: [] as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            $productId = substr(string: $node->getAttribute(qualifiedName: 'id'), offset: 4);
            $name = trim(string: (string) $xpath->evaluate(expression: 'string(.//h4[1])', contextNode: $node));
            $link = $xpath
                ->query(expression: './/a[contains(@href, "/shop/p/")][1]', contextNode: $node)
                ?->item(index: 0);
            $image = $xpath->query(expression: './/img[1]', contextNode: $node)?->item(index: 0);
            $trackingNode = $xpath
                ->query(expression: './/script[@data-tracking-type="product"][1]', contextNode: $node)
                ?->item(index: 0);
            $tracking = json_decode(
                json: html_entity_decode(string: (string) ($trackingNode?->textContent ?? '')),
                associative: true
            );
            $tracking = is_array(value: $tracking) ? $tracking : [];
            $listingId =
                (string) ($tracking['listingId'] ??
                    ($tracking['listing_id'] ??
                        ($tracking['id'] ?? $node->getAttribute(qualifiedName: 'data-listing-id'))));
            $discountValue = $tracking['discount'] ?? false;
            $discount =
                $discountValue === true ||
                (is_array(value: $discountValue) && $discountValue !== []) ||
                (is_numeric(value: $discountValue) && (float) $discountValue > 0) ||
                (is_string(value: $discountValue) &&
                    in_array(needle: strtolower(string: $discountValue), haystack: ['true', 'yes'], strict: true)) ||
                (float) ($tracking['savingsAmount'] ?? 0) > 0 ||
                (float) ($tracking['strikePrice'] ?? 0) > 0;
            if ($name === '' || $listingId === '' || !($link instanceof DOMElement)) {
                continue;
            }
            $url = $link->getAttribute(qualifiedName: 'href');
            if (str_starts_with(haystack: $url, needle: '/')) {
                $url = self::BASE_URL . $url;
            }
            $products[] = [
                'product_id' => $productId,
                'listing_id' => $listingId,
                'name' => $name,
                'url' => $url,
                'image' =>
                    $image instanceof DOMElement
                        ? (string) ($image->getAttribute(qualifiedName: 'src') ?:
                        $image->getAttribute(qualifiedName: 'data-src'))
                        : '',
                'price' => $tracking['price'] ?? null,
                'discount' => $discount,
                'score' => $this->productScore(query: $query, productName: $name, discount: $discount)
            ];
        }
        usort(array: $products, callback: fn(array $first, array $second): int => $second['score'] <=> $first['score']);
        return array_slice(array: $products, offset: 0, length: 5);
    }

    /** @return array{id: string, listing_ids: list<string>, logged_in: bool} */
    public function parseBasket(string $html): array
    {
        $basketId = '';
        foreach (
            ['~ReweBasket\.id\s*=\s*["\']([^"\']+)["\']~', '~["\']basketId["\']\s*:\s*["\']([^"\']+)["\']~']
            as $pattern
        ) {
            if (preg_match(pattern: $pattern, subject: $html, matches: $matches) === 1) {
                $basketId = $matches[1];
                break;
            }
        }
        $listingIds = [];
        if (
            preg_match(
                pattern: '~listingIdToQuantityLookup\s*=\s*(\{.*?\})\s*;~s',
                subject: $html,
                matches: $matches
            ) === 1
        ) {
            $lookup = json_decode(json: $matches[1], associative: true);
            if (is_array(value: $lookup)) {
                $listingIds = array_values(array: array_map(callback: 'strval', array: array_keys(array: $lookup)));
            }
        }
        $loggedIn = preg_match(pattern: '~(?:&quot;|")isLoggedIn(?:&quot;|")\s*:\s*true~i', subject: $html) === 1;
        return ['id' => $basketId, 'listing_ids' => $listingIds, 'logged_in' => $loggedIn];
    }

    private function productScore(string $query, string $productName, bool $discount): int
    {
        $normalizedQuery = $this->normalize(value: $query);
        $normalizedName = $this->normalize(value: $productName);
        $score = str_contains(haystack: $normalizedName, needle: $normalizedQuery) ? 100 : 0;
        foreach (array_filter(array: explode(separator: ' ', string: $normalizedQuery)) as $word) {
            if (str_contains(haystack: $normalizedName, needle: $word)) {
                $score += 20;
            }
        }
        if (str_starts_with(haystack: $normalizedName, needle: $normalizedQuery)) {
            $score += 20;
        }
        return $score + ($discount ? 5 : 0);
    }

    private function normalize(string $value): string
    {
        $ascii = iconv(
            from_encoding: 'UTF-8',
            to_encoding: 'ASCII//TRANSLIT//IGNORE',
            string: mb_strtolower(string: trim(string: $value))
        );
        return trim(
            string: preg_replace(
                pattern: '~[^a-z0-9]+~',
                replacement: ' ',
                subject: $ascii !== false ? $ascii : $value
            ) ?? ''
        );
    }

    /** @return list<string> */
    private function basketHeaders(): array
    {
        return [
            'Accept: application/vnd.com.rewe.digital.basket-v2+json',
            'Content-Type: application/json',
            'x-origin: AddToBasketV2',
            'x-application-id: rewe-basket'
        ];
    }

    private function request(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null
    ): HttpResponse {
        return $this->httpClient->requestImpersonated(
            url: $url,
            method: $method,
            headers: $headers,
            body: $body,
            cookieJar: $this->cookieJar()
        );
    }

    private function cookieJar(): string
    {
        $cookieHeader = $this->cookieHeader(targetUrl: self::BASE_URL);
        if ($cookieHeader === '') {
            throw new RuntimeException(message: 'Keine gültigen REWE-Cookies gefunden in ' . $this->cookieFile . '.');
        }
        $jarFile = dirname(path: $this->cookieFile, levels: 2) . '/rewe.json.jar';
        $exportFiles = $this->cookieExportFiles();
        $latestExportTime = max(
            array_map(callback: fn(string $file): int => (int) filemtime(filename: $file), array: $exportFiles)
        );
        if (is_file(filename: $jarFile) && (int) filemtime(filename: $jarFile) >= $latestExportTime) {
            return $jarFile;
        }
        $cookieLines = [];
        foreach ($exportFiles as $exportFile) {
            $cookies = json_decode(json: (string) file_get_contents(filename: $exportFile), associative: true);
            if (!is_array(value: $cookies)) {
                throw new RuntimeException(message: 'Die REWE-Cookie-Datei ist ungültig: ' . $exportFile . '.');
            }
            foreach ($cookies as $cookie) {
                if (!is_array(value: $cookie)) {
                    continue;
                }
                $name = (string) ($cookie['name'] ?? '');
                $value = (string) ($cookie['value'] ?? '');
                $domain = strtolower(string: (string) ($cookie['domain'] ?? ''));
                $path = (string) ($cookie['path'] ?? '/');
                if (
                    $name === '' ||
                    $domain === '' ||
                    !str_ends_with(haystack: ltrim(string: $domain, characters: '.'), needle: 'rewe.de') ||
                    preg_match(pattern: '~[\t\r\n]~', subject: $name . $value) === 1
                ) {
                    continue;
                }
                $jarDomain = (($cookie['httpOnly'] ?? false) === true ? '#HttpOnly_' : '') . $domain;
                $cookieLines[$domain . "\t" . $path . "\t" . $name] = implode(
                    separator: "\t",
                    array: [
                        $jarDomain,
                        str_starts_with(haystack: $domain, needle: '.') ? 'TRUE' : 'FALSE',
                        $path,
                        ($cookie['secure'] ?? false) === true ? 'TRUE' : 'FALSE',
                        (string) (int) ($cookie['expirationDate'] ?? ($cookie['expires'] ?? 0)),
                        $name,
                        $value
                    ]
                );
            }
        }
        $lines = array_merge(['# Netscape HTTP Cookie File'], array_values(array: $cookieLines));
        $temporaryFile = $jarFile . '.' . bin2hex(string: random_bytes(length: 8));
        file_put_contents(filename: $temporaryFile, data: implode(separator: "\n", array: $lines) . "\n");
        chmod(filename: $temporaryFile, permissions: 0600);
        rename(from: $temporaryFile, to: $jarFile);
        return $jarFile;
    }

    private function cookieHeader(string $targetUrl): string
    {
        if (!is_file(filename: $this->cookieFile)) {
            throw new RuntimeException(
                message: 'Die REWE-Cookie-Datei wurde nicht gefunden: ' . $this->cookieFile . '.'
            );
        }
        $host = strtolower(string: (string) parse_url(url: $targetUrl, component: PHP_URL_HOST));
        $path = (string) (parse_url(url: $targetUrl, component: PHP_URL_PATH) ?: '/');
        $values = [];
        foreach ($this->cookieExportFiles() as $exportFile) {
            $contents = (string) file_get_contents(filename: $exportFile);
            $cookies = json_decode(json: $contents, associative: true);
            if (!is_array(value: $cookies)) {
                throw new RuntimeException(message: 'Die REWE-Cookie-Datei ist ungültig: ' . $exportFile . '.');
            }
            foreach ($cookies as $cookie) {
                if (!is_array(value: $cookie)) {
                    continue;
                }
                $name = (string) ($cookie['name'] ?? '');
                $value = (string) ($cookie['value'] ?? '');
                $domain = ltrim(string: strtolower(string: (string) ($cookie['domain'] ?? '')), characters: '.');
                $cookiePath = (string) ($cookie['path'] ?? '/');
                $expires = (int) ($cookie['expirationDate'] ?? ($cookie['expires'] ?? 0));
                $domainMatches =
                    $domain === '' || $host === $domain || str_ends_with(haystack: $host, needle: '.' . $domain);
                if ($name === '' || preg_match(pattern: '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', subject: $name) !== 1) {
                    continue;
                }
                if (
                    !$domainMatches ||
                    !str_starts_with(haystack: $path, needle: $cookiePath) ||
                    ($expires > 0 && $expires < time())
                ) {
                    continue;
                }
                if (
                    str_contains(haystack: $value, needle: ';') ||
                    preg_match(pattern: '~[\r\n]~', subject: $value) === 1
                ) {
                    continue;
                }
                $values[$name] = $value;
            }
        }
        $headerValues = [];
        foreach ($values as $name => $value) {
            $headerValues[] = $name . '=' . $value;
        }
        return implode(separator: '; ', array: $headerValues);
    }

    /** @return list<string> */
    private function cookieExportFiles(): array
    {
        $files = [$this->cookieFile];
        $accountFile = dirname(path: $this->cookieFile) . '/rewe-account.json';
        if ($accountFile !== $this->cookieFile && is_file(filename: $accountFile)) {
            $files[] = $accountFile;
        }
        return $files;
    }
}
