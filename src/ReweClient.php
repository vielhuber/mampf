<?php
declare(strict_types=1);

namespace Mampf;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class ReweClient
{
    public const PRODUCT_SEARCH_VERSION = 6;

    private const BASE_URL = 'https://www.rewe.de';
    private const SEARCH_URL = self::BASE_URL . '/shop/productList';
    private const SEARCH_API_URL = self::BASE_URL . '/shop/api/products';
    private const BASKET_URL = self::BASE_URL . '/shop/checkout/basket';
    private const ACCOUNT_URL = 'https://account.rewe.de/realms/sso/account/';
    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;
    private const EMPTY_CACHE_TTL_SECONDS = 60 * 60 * 24 * 7;
    private const CATALOG_PAGE_SIZE = 500;
    private const CATALOG_MAX_PAGES = 100;
    private const CATALOG_CACHE_VERSION = 1;
    private const CATALOG_CACHE_TTL_SECONDS = 60 * 60 * 24 * 7;
    private const CATALOG_SORTINGS = [
        'RELEVANCE_DESC' => 'Relevanz',
        'TOPSELLER_DESC' => 'Beliebtheit',
        'NAME_ASC' => 'Name',
        'PRICE_ASC' => 'Preis aufsteigend',
        'PRICE_DESC' => 'Preis absteigend'
    ];
    private const SEARCH_QUERY_ALIASES = [
        'aprikosenchutney' => 'Aprikosenkonfitüre',
        'baby pak choi' => 'Mini Pak Choi',
        'basmati wildreis mischung' => 'Basmati Reis',
        'balsamicocreme' => 'Balsamico Creme',
        'basilikumpaste' => 'Basilikum',
        'bergjausenkase' => 'Bergkäse',
        'bimi brokkoli' => 'Bimi Broccoli',
        'buffelmozzarella' => 'Mozzarella',
        'buntbarsch tilapia filet' => 'Pangasiusfilet',
        'burgerbrotchen' => 'Hamburger Brötchen',
        'beyond meat vegan burger patty' => 'Beyond Meat Beyond Burger Chicken-Style',
        'blattsalatmischung' => 'Salatmischung',
        'bulgogisosse' => 'Teriyaki Sauce',
        'butterbohnen' => 'Weiße Riesenbohnen',
        'buttermilch zitronen dressing' => 'Joghurt Dressing',
        'cannellinibohnen' => 'Weiße Bohnen',
        'chilischote' => 'Chili Jalapeno',
        'chipotle paste' => 'Chipotle Chili Sauce',
        'chili nudeln' => 'Penne Nudeln',
        'ciabatta brot' => 'Ciabatta',
        'chapati brot' => 'Pita',
        'coleslaw mix' => 'Weißkohl',
        'demi glace' => 'Braten-Fond',
        'dorade' => 'Doradenfilets',
        'fenchelknolle' => 'Fenchel',
        'feigenrelish' => 'Feigen Senfsauce',
        'fetakase' => 'Feta',
        'gehackter knoblauch ingwer in ol' => 'Ingwer',
        'gehackter knoblauch zwiebel in rapsol' => 'Knoblauch',
        'frische paccheri' => 'Penne Nudeln',
        'frische strozzapreti' => 'Penne Nudeln',
        'fruhlingszwiebel' => 'Lauchzwiebeln',
        'gemusebruhpulver' => 'Gemüsebrühe',
        'gewurzmischung hello buon appetito' => 'Italienische Kräuter',
        'gewurzmischung hello cajun' => 'Paprika geräuchert',
        'gewurzmischung hello curry' => 'Currypulver',
        'gewurzmischung hello dukkah' => 'Ras el Hanout Gewürzmischung',
        'gewurzmischung hello fiesta' => 'Kreuzkümmel gemahlen',
        'gewurzmischung hello grunzeug' => 'Kräuter der Provence',
        'gewurzmischung hello harissa' => 'Harissa Gewürzmischung',
        'gewurzmischung hello mezze' => 'Ras el Hanout Gewürzmischung',
        'gewurzmischung hello muskat' => 'Muskatnuss gemahlen',
        'gewurzmischung hello paprika' => 'Paprika edelsüß',
        'gewurzmischung hello patatas' => 'Bratkartoffel Gewürzsalz',
        'gewurzmischung hello piri piri' => 'Piri-Piri',
        'gewurzmischung hello smoky paprika' => 'Paprika geräuchert',
        'gewurzmischung hello souflaki' => 'Gyros Gewürzsalz',
        'gewurzmischung hello aloha' => 'Currypulver',
        'gewurzmischung hello baharat' => 'Ras el Hanout Gewürzmischung',
        'gewurzmischung hello kokos curry' => 'Currypulver',
        'gewurzmischung hello mediterraneo' => 'Italienische Kräuter',
        'gewurzmischung hellomediterraneo' => 'Italienische Kräuter',
        'gewurzmischung hello smokey' => 'Paprika geräuchert',
        'gewurzmischung linsensuppe' => 'Gemüsebrühe',
        'gewurzmischung paprikagewurz' => 'Paprika edelsüß',
        'grossgarnelen' => 'Garnelen',
        'hahnchenbruststreifen' => 'Hähnchen Filetstreifen',
        'hahnchengeschnetzeltes' => 'Hähnchen Geschnetzeltes',
        'hahncheninnenbrustfilet' => 'Hähnchen Innenbrustfilet',
        'hahnchenkeule in krautermarinade' => 'Hähnchenschenkel',
        'harissa paste' => 'Harissa Gewürzmischung',
        'hartkase ital art' => 'Hartkäse gerieben',
        'ingwerpaste' => 'Ingwer',
        'kampot pfeffer' => 'Pfeffer schwarz',
        'karotte lauch mix' => 'Wok Mix',
        'kartoffelstarke' => 'Speisestärke',
        'knoblauchzehe' => 'Knoblauch',
        'knoblauch ingwer zitronengras paste' => 'Ingwer',
        'knoblauch krauter mix' => 'Kräuter der Provence',
        'knoblauch zwiebel gehackt in rapsol' => 'Knoblauch',
        'knollensellerie' => 'Sellerie',
        'kochsahne' => 'Kochcreme',
        'korianderkorner' => 'Koriandersamen',
        'korniger senf' => 'Dijon Senf',
        'kirschtomatenpolpa' => 'Polpa Tomatenfruchtfleisch',
        'ketjap manis' => 'Sojasauce',
        'kumin' => 'Kreuzkümmel gemahlen',
        'maggikraut' => 'Liebstöckel',
        'mandelblattchen' => 'Mandeln gehobelt',
        'mandeln blanchiert' => 'Mandeln gehobelt',
        'madras curry pulver' => 'Currypulver',
        'misopaste' => 'Miso Paste',
        'maiskolben' => 'Goldmais',
        'marinierter tofu mit basilikum' => 'Tofu Natur',
        'mozzarella bocconcino' => 'Mozzarella',
        'mini klosse' => 'Kartoffel Knödel',
        'naan brot' => 'Fladenbrot',
        'norwegisches lachsfilet' => 'Lachsfilet',
        'nurnberger bratwurstchen' => 'Nürnberger Rostbratwürste',
        'ofenkartoffel' => 'Kartoffeln',
        'orzo nudeln' => 'Kritharaki Nudeln',
        'pangasius' => 'Pangasiusfilet',
        'pankomehl' => 'Panko Paniermehl',
        'paprika multicolor' => 'Paprika Mix',
        'paprikagewurz' => 'Paprika edelsüß',
        'paprikapulver edelsuss' => 'Paprika edelsüß',
        'pekanusskerne' => 'Pekan-Nusskerne',
        'pflaumenkonfiture' => 'Pflaumenmus',
        'pflucksalat' => 'Salatmischung',
        'pita brote' => 'Mini Pita',
        'portobello pilze' => 'Champignons braun',
        'pilzbruhepaste' => 'Gemüsebrühe',
        'ravigote sosse' => 'Remoulade',
        'risottoreis' => 'Risotto Reis',
        'rosmarinzweig' => 'Rosmarin',
        'sahnemeerrettich' => 'Sahne Meerrettich',
        'sambal badjak' => 'Sambal Oelek',
        'seehecht' => 'Alaska Seelachsfilet',
        'seelachs ohne haut' => 'Alaska Seelachsfilet',
        'senfsosse mit fruhlingszwiebeln' => 'Dijon Senf',
        'simmentaler rinderhackfleisch' => 'Rinderhackfleisch',
        'skipjack thunfisch im eigenen saft' => 'Thunfisch Filets in eigenem Saft',
        'schweinelachssteaks' => 'Schweinefilet',
        'schweinefleischstreifen' => 'Schweinefilet',
        'schweinefiletspitzen in rosmarinmarinade' => 'Schweinefilet',
        'schweineschnitzel' => 'Schweine Schnitzel',
        'stangenbohnen' => 'grüne Bohnen',
        'stangensellerie' => 'Staudensellerie',
        'stir fry mix' => 'Wok Mix',
        'susser chili grill tofu' => 'Tofu Natur',
        'tahini paste' => 'Tahini Sesammus',
        'thai basilikum' => 'Basilikum',
        'tikka masala paste' => 'Tikka Masala Sauce vegan',
        'tortellini spinat und ricotta' => 'Spinat Maultaschen',
        'tomatensugo' => 'Tomatensauce',
        'tomatenpolpa' => 'Polpa Tomatenfruchtfleisch',
        'hello umami' => 'Umami',
        'vegane mayonnaise' => 'Salat Mayo vegan',
        'vegane filetstucke hahnchen art' => 'Veganes Geschnetzeltes Hähnchen',
        'veganes knoblauch dressing' => 'Gartenkräuter Knoblauch Dressing',
        'vegane mini suppenmaultaschen' => 'Vegane Maultaschen',
        'vegane weisse misopaste' => 'Miso Paste',
        'veganes cremiges sojaprodukt' => 'vegane Kochcreme',
        'veganes schawarma' => 'Like Chicken vegan',
        'vorgegarte kartoffelwurfel' => 'Kartoffeln in Scheiben',
        'weizentortillas' => 'Weizen Tortillas',
        'wildpreiselbeerenmarmelade' => 'Wildpreiselbeeren',
        'wildpreiselbeermarmelade' => 'Wildpreiselbeeren',
        'wurziger zwiebel chutney' => 'Röstzwiebeln',
        'wurziges zwiebel chutney' => 'Röstzwiebeln',
        'wurziger gouda' => 'Gouda gerieben',
        'schwarze oliven ohne stein' => 'schwarze Oliven',
        'zaatar' => 'Kräuter der Provence',
        'the vegetarian butcher chick eria filets' => 'Like Chicken vegan',
        'ziegenfrischkase crumble mit honig' => 'Ziegenfrischkäse',
        'ziegenfrischkasetaler' => 'Ziegenfrischkäse',
        'ei' => 'Eier'
    ];
    private const SEARCH_COMPOUND_SUFFIXES = [
        'bohnen',
        'chutney',
        'couscous',
        'creme',
        'essig',
        'filet',
        'kase',
        'konfiture',
        'mischung',
        'nudeln',
        'paste',
        'reis',
        'salat',
        'sosse',
        'streifen',
        'tomaten',
        'tortillas'
    ];
    private const SEARCH_DELAY_MIN_MICROSECONDS = 1_500_000;
    private const SEARCH_DELAY_MAX_MICROSECONDS = 3_000_000;
    private const SEARCH_COOLDOWN_INTERVAL = 100;
    private const SEARCH_COOLDOWN_MIN_SECONDS = 30;
    private const SEARCH_COOLDOWN_MAX_SECONDS = 60;

    /** @var array<string, list<array<string, mixed>>> */
    private array $productsByIngredient = [];
    /** @var list<array{product: array<string, mixed>, normalized_name: string, normalized_words: list<string>}> */
    private array $productCatalog = [];
    /** @var array<string, list<int>> */
    private array $productCatalogByWordPrefix = [];
    private bool $productCatalogLoaded = false;
    private bool $shopSessionReady = false;
    private int $networkSearchCount = 0;

    public function __construct(
        private readonly Database $database,
        private readonly HttpClient $httpClient,
        private readonly string $cookieFile,
        private readonly ?string $cookieJarFile = null,
        private readonly ?string $productCatalogFile = null
    ) {}

    public function searchUrl(string $query): string
    {
        return self::SEARCH_URL . '?' . http_build_query(data: ['search' => $query]);
    }

    public function downloadProductCatalog(?callable $progress = null, ?callable $checkpoint = null): int
    {
        if ($this->productCatalogLoaded) {
            return count(value: $this->productCatalog);
        }
        if ($this->productCatalogFile !== null && is_file(filename: $this->productCatalogFile)) {
            $cookieFiles = array_values(
                array: array_filter(
                    array: [$this->cookieFile, dirname(path: $this->cookieFile) . '/rewe-account.json'],
                    callback: is_file(...)
                )
            );
            $latestCookieTime =
                $cookieFiles === []
                    ? 0
                    : max(
                        array_map(callback: fn(string $file): int => (int) filemtime(filename: $file), array: $cookieFiles)
                    );
            $cacheTime = (int) filemtime(filename: $this->productCatalogFile);
            if (
                $cacheTime >= time() - self::CATALOG_CACHE_TTL_SECONDS &&
                $cacheTime >= $latestCookieTime
            ) {
                $cache = json_decode(
                    json: (string) file_get_contents(filename: $this->productCatalogFile),
                    associative: true
                );
                $cachedProducts = is_array(value: $cache['products'] ?? null) ? $cache['products'] : [];
                if ((int) ($cache['version'] ?? 0) === self::CATALOG_CACHE_VERSION && $cachedProducts !== []) {
                    $productCount = $this->hydrateProductCatalog(products: $cachedProducts);
                    $progress?->__invoke(1, 1, $productCount, 'Cache');
                    return $productCount;
                }
            }
        }
        $this->ensureShopSession();
        $productsByListingId = [];
        $pagesPerSorting = null;
        $completedPages = 0;
        foreach (self::CATALOG_SORTINGS as $sorting => $sortingLabel) {
            $page = 1;
            $pageCount = 1;
            while ($page <= $pageCount) {
                $checkpoint?->__invoke();
                $response = $this->request(
                    url: self::SEARCH_API_URL .
                        '?' .
                        http_build_query(
                            data: [
                                'search' => '*',
                                'objectsPerPage' => self::CATALOG_PAGE_SIZE,
                                'page' => $page,
                                'sorting' => $sorting
                            ]
                        ),
                    headers: ['Accept: application/vnd.rewe.digital.products+json;client=web;version=2']
                );
                if ($this->isCloudflareChallenge(response: $response)) {
                    throw ReweAccessException::cloudflareChallenge();
                }
                if ($response->status !== 200) {
                    throw new RuntimeException(
                        message: 'Der REWE-Produktbestand antwortete mit HTTP ' . $response->status . '.'
                    );
                }
                $data = json_decode(json: $response->body, associative: true);
                $pagination = is_array(value: $data['pagination'] ?? null) ? $data['pagination'] : [];
                if ($page === 1) {
                    $pageCount = (int) ($pagination['pageCount'] ?? 0);
                    if ($pageCount < 1 || $pageCount > self::CATALOG_MAX_PAGES) {
                        throw new RuntimeException(
                            message: 'Der REWE-Produktbestand meldete eine ungültige Seitenzahl.'
                        );
                    }
                    $pagesPerSorting ??= $pageCount;
                    if ($pageCount !== $pagesPerSorting) {
                        throw new RuntimeException(
                            message: 'Die REWE-Sortierungen meldeten unterschiedliche Seitenzahlen.'
                        );
                    }
                }
                $pageProducts = $this->parseProductSearchResponse(
                    responseBody: $response->body,
                    query: '*',
                    limit: null
                );
                if ($pageProducts === []) {
                    throw new RuntimeException(message: 'Der REWE-Produktbestand enthielt keine Produkte.');
                }
                foreach ($pageProducts as $product) {
                    $listingId = trim(string: (string) ($product['listing_id'] ?? ''));
                    if ($listingId !== '') {
                        $productsByListingId[$listingId] = $product;
                    }
                }
                $completedPages++;
                $progress?->__invoke(
                    $completedPages,
                    $pagesPerSorting * count(value: self::CATALOG_SORTINGS),
                    count(value: $productsByListingId),
                    $sortingLabel
                );
                $page++;
            }
        }
        $products = array_values(array: $productsByListingId);
        if ($this->productCatalogFile !== null) {
            $temporaryFile = $this->productCatalogFile . '.' . bin2hex(string: random_bytes(length: 8));
            $cacheJson = json_encode(
                value: ['version' => self::CATALOG_CACHE_VERSION, 'products' => $products],
                flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (file_put_contents(filename: $temporaryFile, data: $cacheJson) === false) {
                throw new RuntimeException(message: 'Der REWE-Produktbestand konnte nicht gecacht werden.');
            }
            chmod(filename: $temporaryFile, permissions: 0600);
            rename(from: $temporaryFile, to: $this->productCatalogFile);
        }
        return $this->hydrateProductCatalog(products: $products);
    }

    /** @param list<array<string, mixed>> $products */
    private function hydrateProductCatalog(array $products): int
    {
        $this->productCatalog = [];
        $this->productCatalogByWordPrefix = [];
        foreach ($products as $product) {
            if (!is_array(value: $product)) {
                continue;
            }
            $normalizedName = $this->normalize(value: (string) ($product['name'] ?? ''));
            if ($normalizedName === '' || trim(string: (string) ($product['listing_id'] ?? '')) === '') {
                continue;
            }
            $normalizedWords = $this->searchWords(normalizedValue: $normalizedName);
            $catalogIndex = count(value: $this->productCatalog);
            $this->productCatalog[] = [
                'product' => $product,
                'normalized_name' => $normalizedName,
                'normalized_words' => $normalizedWords
            ];
            foreach ($normalizedWords as $normalizedWord) {
                $this->productCatalogByWordPrefix[substr(string: $normalizedWord, offset: 0, length: 3)][] =
                    $catalogIndex;
            }
        }
        $this->productsByIngredient = [];
        $this->productCatalogLoaded = true;
        return count(value: $this->productCatalog);
    }

    /** @return list<array<string, mixed>> */
    public function productsForIngredient(string $name, bool $refresh = false): array
    {
        $key = $this->normalize(value: $name);
        if (array_key_exists(key: $key, array: $this->productsByIngredient)) {
            return $this->productsByIngredient[$key];
        }
        if ($this->productCatalogLoaded) {
            $products = $this->productsFromCatalog(name: $name);
            $this->database->saveIngredientMapping(
                key: $key,
                query: $name,
                products: $products,
                searchVersion: self::PRODUCT_SEARCH_VERSION
            );
            $this->productsByIngredient[$key] = $products;
            return $products;
        }
        if (!$refresh) {
            $cached = $this->database->ingredientMapping(
                key: $key,
                maxAgeSeconds: self::CACHE_TTL_SECONDS,
                searchVersion: self::PRODUCT_SEARCH_VERSION
            );
            $emptyCacheIsFresh =
                $cached === [] &&
                $this->database->ingredientMapping(
                    key: $key,
                    maxAgeSeconds: self::EMPTY_CACHE_TTL_SECONDS,
                    searchVersion: self::PRODUCT_SEARCH_VERSION
                ) !== null;
            if ($cached !== null && ($cached !== [] || $emptyCacheIsFresh)) {
                $this->productsByIngredient[$key] = $cached;
                return $cached;
            }
            $previousVersionCache = $this->database->ingredientMapping(
                key: $key,
                maxAgeSeconds: self::CACHE_TTL_SECONDS
            );
            if ($previousVersionCache !== null && $previousVersionCache !== []) {
                $this->productsByIngredient[$key] = $previousVersionCache;
                return $previousVersionCache;
            }
        }
        $products = $this->searchProductsAtRewe(name: $name);
        $this->database->saveIngredientMapping(
            key: $key,
            query: $name,
            products: $products,
            searchVersion: self::PRODUCT_SEARCH_VERSION
        );
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
        $this->ensureShopSession();
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
                    $missing[] = (string) $recipe['name'] . ': ungültiger Zutateneintrag';
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
        $this->assertBasketResponse(response: $basketResponse);
        $basket = $this->parseBasket(html: $basketResponse->body);
        if (!$basket['logged_in']) {
            throw new RuntimeException(
                message: 'Die REWE-Shop-Sitzung ist während der Vorbereitung abgelaufen. Starte die Bestellung erneut.'
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
                        'includeTimeslot' => true,
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
            'removed' => array_sum(array: $basket['listing_quantities']),
            'removed_distinct' => count(value: $basket['listing_ids']),
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
            $listingId = trim(string: (string) ($tracking['listingId'] ?? ($tracking['listing_id'] ?? '')));
            $listingNode = $xpath
                ->query(expression: './/input[@name="listingId"][1]', contextNode: $node)
                ?->item(index: 0);
            if ($listingId === '' && $listingNode instanceof DOMElement) {
                $listingId = trim(string: $listingNode->getAttribute(qualifiedName: 'value'));
            }
            if ($listingId === '') {
                $listingId = trim(string: $node->getAttribute(qualifiedName: 'data-listing-id'));
            }
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
        return $this->rankProducts(products: $products);
    }

    /** @return list<array<string, mixed>> */
    public function parseProductSearchResponse(string $responseBody, string $query, ?int $limit = 5): array
    {
        $response = json_decode(json: $responseBody, associative: true);
        if (!is_array(value: $response) || !isset($response['hits']) || !is_array(value: $response['hits'])) {
            throw new RuntimeException(message: 'Die REWE-Produktsuche lieferte ein unbekanntes Antwortformat.');
        }
        $products = [];
        foreach ($response['hits'] as $hit) {
            if (!is_array(value: $hit)) {
                continue;
            }
            $productId = trim(string: (string) ($hit['productId'] ?? ''));
            $listingId = trim(string: (string) ($hit['listingId'] ?? ''));
            $name = trim(string: (string) ($hit['title'] ?? ''));
            $detailsUrl = trim(string: (string) ($hit['detailsUrl'] ?? ''));
            $pricing = is_array(value: $hit['pricing'] ?? null) ? $hit['pricing'] : [];
            $tags = is_array(value: $hit['tags'] ?? null) ? $hit['tags'] : [];
            if ($listingId === '' || $name === '' || $detailsUrl === '') {
                continue;
            }
            $url = $detailsUrl;
            if (str_starts_with(haystack: $url, needle: '/p/')) {
                $url = self::BASE_URL . '/shop' . $url;
            }
            if (str_starts_with(haystack: $url, needle: '/') && !str_starts_with(haystack: $url, needle: '/p/')) {
                $url = self::BASE_URL . $url;
            }
            $discount =
                (is_array(value: $pricing['discount'] ?? null) && $pricing['discount'] !== []) ||
                in_array(needle: 'discounted', haystack: $tags, strict: true);
            $price = $pricing['currentRetailPrice'] ?? null;
            $products[] = [
                'product_id' => $productId,
                'listing_id' => $listingId,
                'name' => $name,
                'url' => $url,
                'image' => (string) ($hit['imageURL'] ?? ''),
                'price' => is_numeric(value: $price) ? (float) $price / 100 : null,
                'discount' => $discount,
                'score' => $this->productScore(query: $query, productName: $name, discount: $discount)
            ];
        }
        return $this->rankProducts(products: $products, limit: $limit);
    }

    /** @return array{id: string, listing_ids: list<string>, listing_quantities: array<string, int>, logged_in: bool} */
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
        $listingQuantities = [];
        if (
            preg_match(
                pattern: '~listingIdToQuantityLookup\s*=\s*(\{.*?\})\s*;~s',
                subject: $html,
                matches: $matches
            ) === 1
        ) {
            $lookup = json_decode(json: $matches[1], associative: true);
            if (is_array(value: $lookup)) {
                foreach ($lookup as $listingId => $value) {
                    $quantity = is_array(value: $value) ? (int) ($value['quantity'] ?? 0) : (int) $value;
                    if ($quantity > 0) {
                        $listingQuantities[(string) $listingId] = $quantity;
                    }
                }
            }
            if (
                $listingQuantities === [] &&
                preg_match_all(
                    pattern: '~["\']([^"\']+)["\']\s*:\s*\{\s*["\']?quantity["\']?\s*:\s*(\d+)~',
                    subject: $matches[1],
                    matches: $quantityMatches,
                    flags: PREG_SET_ORDER
                ) > 0
            ) {
                foreach ($quantityMatches as $quantityMatch) {
                    $listingQuantities[$quantityMatch[1]] = (int) $quantityMatch[2];
                }
            }
        }
        $loggedIn = preg_match(pattern: '~(?:&quot;|")isLoggedIn(?:&quot;|")\s*:\s*true~i', subject: $html) === 1;
        return [
            'id' => $basketId,
            'listing_ids' => array_keys(array: $listingQuantities),
            'listing_quantities' => $listingQuantities,
            'logged_in' => $loggedIn
        ];
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
        if ($normalizedName === $normalizedQuery) {
            $score += 100;
        }
        $score -= min(50, max(0, strlen(string: $normalizedName) - strlen(string: $normalizedQuery)));
        return $score + ($discount ? 5 : 0);
    }

    /** @return list<array<string, mixed>> */
    private function productsFromCatalog(string $name): array
    {
        $matches = [];
        foreach ($this->productSearchQueries(name: $name) as $query) {
            $normalizedQuery = $this->normalize(value: $query);
            $queryWords = $this->searchWords(normalizedValue: $normalizedQuery);
            if ($normalizedQuery === '' || $queryWords === []) {
                continue;
            }
            $candidateIndexes = null;
            foreach ($queryWords as $queryWord) {
                $wordCandidateIndexes = [];
                $prefix = substr(string: $queryWord, offset: 0, length: 3);
                foreach ($this->productCatalogByWordPrefix[$prefix] ?? [] as $catalogIndex) {
                    $productWords = $this->productCatalog[$catalogIndex]['normalized_words'];
                    $wordMatches = false;
                    foreach ($productWords as $productWord) {
                        $lengthDifference = abs(strlen(string: $queryWord) - strlen(string: $productWord));
                        if (
                            $queryWord === $productWord ||
                            ($lengthDifference <= 3 &&
                                (str_starts_with(haystack: $queryWord, needle: $productWord) ||
                                    str_starts_with(haystack: $productWord, needle: $queryWord)))
                        ) {
                            $wordMatches = true;
                            break;
                        }
                    }
                    if ($wordMatches) {
                        $wordCandidateIndexes[$catalogIndex] = true;
                    }
                }
                $candidateIndexes =
                    $candidateIndexes === null
                        ? $wordCandidateIndexes
                        : array_intersect_key($candidateIndexes, $wordCandidateIndexes);
                if ($candidateIndexes === []) {
                    break;
                }
            }
            foreach (array_keys(array: $candidateIndexes ?? []) as $catalogIndex) {
                $catalogEntry = $this->productCatalog[$catalogIndex];
                $product = $catalogEntry['product'];
                $listingId = (string) ($product['listing_id'] ?? '');
                $product['score'] = $this->productScore(
                    query: $query,
                    productName: (string) ($product['name'] ?? ''),
                    discount: ($product['discount'] ?? false) === true
                );
                if (!isset($matches[$listingId]) || $product['score'] > $matches[$listingId]['score']) {
                    $matches[$listingId] = $product;
                }
            }
        }
        return $this->rankProducts(products: array_values(array: $matches));
    }

    /** @return list<array<string, mixed>> */
    private function searchProductsAtRewe(string $name): array
    {
        $this->ensureShopSession();
        $this->networkSearchCount++;
        if ($this->networkSearchCount % self::SEARCH_COOLDOWN_INTERVAL === 0) {
            sleep(seconds: random_int(min: self::SEARCH_COOLDOWN_MIN_SECONDS, max: self::SEARCH_COOLDOWN_MAX_SECONDS));
        }
        usleep(
            microseconds: random_int(min: self::SEARCH_DELAY_MIN_MICROSECONDS, max: self::SEARCH_DELAY_MAX_MICROSECONDS)
        );
        $products = [];
        foreach ($this->productSearchQueries(name: $name) as $searchIndex => $searchQuery) {
            if ($searchIndex > 0) {
                usleep(microseconds: random_int(min: 500_000, max: 1_000_000));
            }
            $response = $this->request(
                url: self::SEARCH_API_URL . '?' . http_build_query(data: ['search' => $searchQuery]),
                headers: ['Accept: application/vnd.rewe.digital.products+json;client=web;version=2']
            );
            if ($this->isCloudflareChallenge(response: $response)) {
                throw ReweAccessException::cloudflareChallenge();
            }
            if ($response->status !== 200) {
                throw new RuntimeException(
                    message: 'Die REWE-Suche antwortete mit HTTP ' .
                        $response->status .
                        '. Prüfe den exportierten Cookie.'
                );
            }
            $products = $this->parseProductSearchResponse(responseBody: $response->body, query: $searchQuery);
            if ($products !== []) {
                break;
            }
        }
        return $products;
    }

    /** @return list<string> */
    private function productSearchQueries(string $name): array
    {
        $queries = [];
        $addQuery = static function (string $query) use (&$queries): void {
            $query = trim(
                string: preg_replace(pattern: '~\s+~u', replacement: ' ', subject: $query) ?? $query,
                characters: " \t\n\r\0\x0B,;"
            );
            if (
                $query !== '' &&
                !in_array(
                    needle: mb_strtolower(string: $query),
                    haystack: array_map(mb_strtolower(...), $queries),
                    strict: true
                )
            ) {
                $queries[] = $query;
            }
        };
        $addQuery($name);
        $expandedName = preg_replace(
            pattern: ['~\bvorw\.?\s*festk\.?\b~iu', '~\bmehligk\.?\b~iu', '~\bfestk\.?\b~iu'],
            replacement: ['vorwiegend festkochend', 'mehligkochend', 'festkochend'],
            subject: $name
        );
        $addQuery(is_string(value: $expandedName) ? $expandedName : $name);
        foreach ($queries as $query) {
            $withoutParentheses = trim(
                string: preg_replace(pattern: '~\s*\([^)]*\)~u', replacement: '', subject: $query) ?? $query
            );
            $addQuery($withoutParentheses);
            $addQuery(explode(separator: ',', string: $withoutParentheses, limit: 2)[0]);
        }
        foreach ($queries as $query) {
            $withoutPreparation = trim(
                string: preg_replace(pattern: '~(\p{L}+)zubereitung\b~iu', replacement: '$1', subject: $query) ?? $query
            );
            $addQuery($withoutPreparation);
            $addQuery(
                preg_replace(
                    pattern: '~^Gewürzmischung\s+~iu',
                    replacement: '',
                    subject: $withoutPreparation
                ) ?? $withoutPreparation
            );
            $withoutQualifiers = preg_replace(
                pattern: [
                    '~\bital\.?\s+Art\b~iu',
                    '~\b(?:in Lake|in Scheiben|im Kühlbeutel|vom Weiderind|pro Person)\b~iu',
                    '~\b(?:bio|baby|lila|blanchiert(?:e|er|es|en|em)?|libanesisch(?:e|er|es|en|em)?|getrocknet(?:e|er|es|en|em)?|gemahlen(?:e|er|es|en|em)?|rot(?:e|er|es|en|em)?|gelb(?:e|er|es|en|em)?|grün(?:e|er|es|en|em)?|bunt(?:e|er|es|en|em)?|klein(?:e|er|es|en|em)?|mild(?:e|er|es|en|em)?|frisch(?:e|er|es|en|em)?|braun(?:e|er|es|en|em)?|gereift(?:e|er|es|en|em)?|gerieben(?:e|er|es|en|em)?|geraspelt(?:e|er|es|en|em)?|mariniert(?:e|er|es|en|em)?|gewachst(?:e|er|es|en|em)?|vorgegart(?:e|er|es|en|em)?|vorgekocht(?:e|er|es|en|em)?|glatt|leicht(?:e|er|es|en|em)?|ganz(?:e|er|es|en|em)?)\b~iu'
                ],
                replacement: ' ',
                subject: $withoutPreparation
            );
            $addQuery(is_string(value: $withoutQualifiers) ? $withoutQualifiers : $withoutPreparation);
            foreach ([$withoutPreparation, $withoutQualifiers] as $queryWithParts) {
                if (!is_string(value: $queryWithParts) || !str_contains(haystack: $queryWithParts, needle: '/')) {
                    continue;
                }
                foreach (explode(separator: '/', string: $queryWithParts) as $queryPart) {
                    $addQuery($queryPart);
                }
            }
        }
        foreach ($queries as $query) {
            $alias = self::SEARCH_QUERY_ALIASES[$this->normalize(value: $query)] ?? null;
            if ($alias !== null) {
                $addQuery($alias);
            }
        }
        return $queries;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function rankProducts(array $products, ?int $limit = 5): array
    {
        usort(array: $products, callback: fn(array $first, array $second): int => $second['score'] <=> $first['score']);
        if ($limit === null) {
            return $products;
        }
        return array_slice(array: $products, offset: 0, length: $limit);
    }

    private function normalize(string $value): string
    {
        $value = str_ireplace(search: 'sauce', replace: 'soße', subject: $value);
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
    private function searchWords(string $normalizedValue): array
    {
        $words = [];
        foreach (array_filter(array: explode(separator: ' ', string: $normalizedValue)) as $word) {
            if (strlen(string: $word) < 3) {
                continue;
            }
            $compoundWasSplit = false;
            foreach (self::SEARCH_COMPOUND_SUFFIXES as $suffix) {
                if (!str_ends_with(haystack: $word, needle: $suffix)) {
                    continue;
                }
                $prefix = substr(string: $word, offset: 0, length: -strlen(string: $suffix));
                if (strlen(string: $prefix) < 3) {
                    continue;
                }
                $words[] = $prefix;
                $words[] = $suffix;
                $compoundWasSplit = true;
                break;
            }
            if (!$compoundWasSplit) {
                $words[] = $word;
            }
        }
        return array_values(array: array_unique(array: $words));
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

    private function ensureShopSession(): void
    {
        if ($this->shopSessionReady) {
            return;
        }
        $basketResponse = $this->request(url: self::BASKET_URL);
        $this->assertBasketResponse(response: $basketResponse);
        if ($this->parseBasket(html: $basketResponse->body)['logged_in']) {
            $this->shopSessionReady = true;
            return;
        }

        $this->request(url: self::ACCOUNT_URL);
        $loginResponse = $this->request(url: self::BASE_URL . '/mydata/login');
        if ($loginResponse->status !== 200) {
            throw new RuntimeException(
                message: 'Die REWE-Anmeldung antwortete mit HTTP ' . $loginResponse->status . '.'
            );
        }
        $document = new DOMDocument();
        libxml_use_internal_errors(use_errors: true);
        $document->loadHTML(source: $loginResponse->body);
        libxml_clear_errors();
        $xpath = new DOMXPath(document: $document);
        $form = $xpath->query(expression: '//form[.//button[@name="login"]][1]')?->item(index: 0);
        if ($form instanceof DOMElement) {
            $action = html_entity_decode(string: trim(string: $form->getAttribute(qualifiedName: 'action')));
            if ($action !== '') {
                $this->request(
                    url: $action,
                    method: 'POST',
                    headers: [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Origin: https://account.rewe.de',
                        'Referer: https://account.rewe.de/'
                    ],
                    body: 'login='
                );
            }
        }

        $basketResponse = $this->request(url: self::BASKET_URL);
        $this->assertBasketResponse(response: $basketResponse);
        if (!$this->parseBasket(html: $basketResponse->body)['logged_in']) {
            throw new RuntimeException(
                message: 'Die REWE-Sitzung konnte nicht erneuert werden. Exportiere rewe-shop.json und rewe-account.json erneut.'
            );
        }
        $this->shopSessionReady = true;
    }

    private function assertBasketResponse(HttpResponse $response): void
    {
        if ($response->status === 200) {
            return;
        }
        if ($this->isCloudflareChallenge(response: $response)) {
            throw ReweAccessException::cloudflareChallenge();
        }
        throw new RuntimeException(message: 'Der REWE-Warenkorb antwortete mit HTTP ' . $response->status . '.');
    }

    private function isCloudflareChallenge(HttpResponse $response): bool
    {
        return $response->status === 403 &&
            (str_contains(haystack: $response->body, needle: 'Zeig uns, dass du ein Mensch bist') ||
                str_contains(haystack: $response->body, needle: '_cf_chl_opt'));
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
        $jarFile = $this->cookieJarFile ?? dirname(path: $this->cookieFile, levels: 2) . '/rewe.json.jar';
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
