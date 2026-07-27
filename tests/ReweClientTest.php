<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\Database;
use Mampf\HttpClient;
use Mampf\HttpResponse;
use Mampf\ReweClient;
use Mampf\ReweAccessException;
use PHPUnit\Framework\TestCase;

final class ReweClientTest extends TestCase
{
    public function testCookieEditorExportIsConvertedToRequestHeader(): void
    {
        $path = sys_get_temp_dir() . '/mampf-cookies-' . bin2hex(string: random_bytes(length: 8)) . '.json';
        file_put_contents(
            filename: $path,
            data: json_encode(
                value: [
                    ['name' => 'session', 'value' => 'valid', 'domain' => '.rewe.de', 'path' => '/'],
                    ['name' => 'other', 'value' => 'ignored', 'domain' => '.example.org', 'path' => '/']
                ],
                flags: JSON_THROW_ON_ERROR
            )
        );
        $client = $this->client(cookieFile: $path);
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'cookieHeader');

        $this->assertSame('session=valid', $method->invoke($client, 'https://www.rewe.de/shop/productList'));
        unlink(filename: $path);
    }

    public function testShopAndAccountCookieExportsAreCombined(): void
    {
        $directory = sys_get_temp_dir() . '/mampf-cookies-' . bin2hex(string: random_bytes(length: 8));
        mkdir(directory: $directory);
        $cookieDirectory = $directory . '/cookies';
        mkdir(directory: $cookieDirectory);
        $shopPath = $cookieDirectory . '/rewe-shop.json';
        $accountPath = $cookieDirectory . '/rewe-account.json';
        file_put_contents(
            filename: $shopPath,
            data: json_encode(
                value: [['name' => 'rstp', 'value' => 'shop', 'domain' => '.www.rewe.de', 'path' => '/']],
                flags: JSON_THROW_ON_ERROR
            )
        );
        file_put_contents(
            filename: $accountPath,
            data: json_encode(
                value: [['name' => 'sso', 'value' => 'account', 'domain' => 'account.rewe.de', 'path' => '/']],
                flags: JSON_THROW_ON_ERROR
            )
        );
        $client = $this->client(cookieFile: $shopPath);
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'cookieHeader');
        $jarMethod = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'cookieJar');

        $this->assertSame('rstp=shop', $method->invoke($client, 'https://www.rewe.de/shop'));
        $this->assertSame('sso=account', $method->invoke($client, 'https://account.rewe.de/realms/sso/account'));
        $this->assertSame($directory . '/rewe.json.jar', $jarMethod->invoke($client));
        $this->assertFileExists($directory . '/rewe.json.jar');
        unlink(filename: $shopPath);
        unlink(filename: $accountPath);
        unlink(filename: $directory . '/rewe.json.jar');
        rmdir(directory: $cookieDirectory);
        rmdir(directory: $directory);
    }

    public function testProductsAreParsedAndRankedWithSmallDiscountBonus(): void
    {
        $client = $this->client();
        $html = <<<'HTML'
            <div id="plr-1"><input type="hidden" name="listingId" value="listing-1"><a href="/shop/p/irrelevant/1"><img src="one.jpg"><h4>Schokolade</h4></a><script type="application/json" data-tracking-type="product">{"id":"product-1","discount":true,"price":1.2}</script></div>
            <div id="plr-2"><input type="hidden" name="listingId" value="listing-2"><a href="/shop/p/kartoffeln/2"><img src="two.jpg"><h4>REWE Beste Wahl Kartoffeln festkochend</h4></a><script type="application/json" data-tracking-type="product">{"id":"product-2","discount":false,"price":2.5}</script></div>
        HTML;

        $products = $client->parseProducts(html: $html, query: 'Kartoffeln');

        $this->assertSame('listing-2', $products[0]['listing_id']);
        $this->assertSame('https://www.rewe.de/shop/p/kartoffeln/2', $products[0]['url']);
        $this->assertTrue($products[1]['discount']);
    }

    public function testProductSearchResponseIsParsedAndRanked(): void
    {
        $responseBody = json_encode(
            value: [
                'hits' => [
                    [
                        'productId' => 'product-1',
                        'listingId' => 'listing-1',
                        'title' => 'Schokolade',
                        'detailsUrl' => '/p/schokolade/1',
                        'imageURL' => 'one.jpg',
                        'pricing' => ['currentRetailPrice' => 120]
                    ],
                    [
                        'productId' => 'product-2',
                        'listingId' => 'listing-2',
                        'title' => 'REWE Regional Hackfleisch gemischt 500g',
                        'detailsUrl' => '/p/hackfleisch/2',
                        'imageURL' => 'two.jpg',
                        'baseQuantity' => 500,
                        'quantityType' => 'G',
                        'categoryDetails' => [
                            ['path' => 'Fleisch & Fisch/Fleisch/Hackfleisch'],
                            ['path' => 'Fleisch & Fisch/Fleisch/Hackfleisch/Gemischtes Hackfleisch']
                        ],
                        'pricing' => [
                            'currentRetailPrice' => 698,
                            'discount' => ['validTo' => '2026-07-24'],
                            'grammage' => '500g (1 kg = 13,96 €)'
                        ]
                    ],
                    [
                        'productId' => 'product-3',
                        'listingId' => 'listing-3',
                        'title' => 'Hackfleisch nicht verfügbar',
                        'detailsUrl' => '/p/hackfleisch/3',
                        'orderLimit' => 0,
                        'pricing' => ['currentRetailPrice' => 498]
                    ]
                ]
            ],
            flags: JSON_THROW_ON_ERROR
        );

        $products = $this->client()->parseProductSearchResponse(responseBody: $responseBody, query: 'Hackfleisch');

        $this->assertSame('listing-2', $products[0]['listing_id']);
        $this->assertSame('https://www.rewe.de/shop/p/hackfleisch/2', $products[0]['url']);
        $this->assertSame(6.98, $products[0]['price']);
        $this->assertTrue($products[0]['discount']);
        $this->assertSame(500.0, $products[0]['base_quantity']);
        $this->assertSame('G', $products[0]['quantity_type']);
        $this->assertSame('500g (1 kg = 13,96 €)', $products[0]['grammage']);
        $this->assertSame(
            ['Fleisch & Fisch/Fleisch/Hackfleisch', 'Fleisch & Fisch/Fleisch/Hackfleisch/Gemischtes Hackfleisch'],
            $products[0]['category_paths']
        );
        $this->assertFalse($products[1]['discount']);
        $this->assertCount(2, $products);
    }

    public function testProductSearchResponseCanReturnCompleteCatalogPage(): void
    {
        $hits = [];
        for ($index = 1; $index <= 6; $index++) {
            $hits[] = [
                'productId' => 'product-' . $index,
                'listingId' => 'listing-' . $index,
                'title' => 'Produkt ' . $index,
                'detailsUrl' => '/p/product/' . $index
            ];
        }
        $responseBody = json_encode(value: ['hits' => $hits], flags: JSON_THROW_ON_ERROR);
        $client = $this->client();

        $this->assertCount(5, $client->parseProductSearchResponse(responseBody: $responseBody, query: 'Produkt'));
        $this->assertCount(
            6,
            $client->parseProductSearchResponse(responseBody: $responseBody, query: '*', limit: null)
        );
    }

    public function testFreshCatalogIsSearchedBeforeIngredientCache(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->saveIngredientMapping(
            key: 'limette gewachst',
            query: 'Limette, gewachst',
            products: [['listing_id' => 'old-listing']],
            searchVersion: ReweClient::PRODUCT_SEARCH_VERSION
        );
        $client = new ReweClient(database: $database, httpClient: new HttpClient(), cookieFile: '/does/not/exist');
        $reflection = new \ReflectionClass(objectOrClass: $client);
        $reflection->getProperty(name: 'productCatalog')->setValue(
            $client,
            [
                [
                    'product' => [
                        'product_id' => 'lime-product',
                        'listing_id' => 'lime-listing',
                        'name' => 'REWE Bio Limetten',
                        'url' => 'https://www.rewe.de/shop/p/limetten/1',
                        'image' => '',
                        'price' => 0.69,
                        'discount' => false,
                        'score' => 0
                    ],
                    'normalized_name' => 'rewe bio limetten',
                    'normalized_words' => ['rewe', 'bio', 'limetten']
                ],
                [
                    'product' => [
                        'product_id' => 'chocolate-product',
                        'listing_id' => 'chocolate-listing',
                        'name' => 'Schokolade',
                        'url' => 'https://www.rewe.de/shop/p/schokolade/2',
                        'image' => '',
                        'price' => 1.29,
                        'discount' => false,
                        'score' => 0
                    ],
                    'normalized_name' => 'schokolade',
                    'normalized_words' => ['schokolade']
                ]
            ]
        );
        $reflection->getProperty(name: 'productCatalogByWordPrefix')->setValue(
            $client,
            ['lim' => [0], 'sch' => [1]]
        );
        $reflection->getProperty(name: 'productCatalogLoaded')->setValue($client, true);

        $products = $client->productsForIngredient(name: 'Limette, gewachst');

        $this->assertSame('lime-listing', $products[0]['listing_id']);
        $this->assertCount(1, $products);
        $this->assertSame(
            'lime-listing',
            $database->ingredientMapping(
                key: 'limette gewachst',
                searchVersion: ReweClient::PRODUCT_SEARCH_VERSION
            )[0]['listing_id']
        );
        unlink(filename: $path);
    }

    public function testMissingCatalogProductDoesNotTriggerFallbackSearch(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $client = new ReweClient(database: $database, httpClient: new HttpClient(), cookieFile: '/does/not/exist');
        new \ReflectionClass(objectOrClass: $client)
            ->getProperty(name: 'productCatalogLoaded')
            ->setValue($client, true);

        $this->assertSame([], $client->productsForIngredient(name: 'Nicht vorhandene Zutat'));
        $this->assertSame(
            [],
            $database->ingredientMapping(
                key: 'nicht vorhandene zutat',
                searchVersion: ReweClient::PRODUCT_SEARCH_VERSION
            )
        );
        unlink(filename: $path);
    }

    public function testFreshProductCatalogIsLoadedFromDiskCache(): void
    {
        $directory = sys_get_temp_dir() . '/mampf-catalog-' . bin2hex(string: random_bytes(length: 8));
        mkdir(directory: $directory);
        $databasePath = $directory . '/mampf.sqlite';
        $cookieFile = $directory . '/rewe-shop.json';
        $catalogFile = $directory . '/rewe-product-catalog.json';
        file_put_contents(filename: $cookieFile, data: '[]');
        file_put_contents(
            filename: $catalogFile,
            data: json_encode(
                value: [
                    'version' => 4,
                    'products' => [
                        [
                            'product_id' => 'potato-product',
                            'listing_id' => 'potato-listing',
                            'name' => 'Kartoffeln festkochend',
                            'url' => 'https://www.rewe.de/shop/p/kartoffeln/1',
                            'image' => '',
                            'price' => 2.49,
                            'discount' => false,
                            'score' => 0
                        ]
                    ]
                ],
                flags: JSON_THROW_ON_ERROR
            )
        );
        touch(filename: $catalogFile, mtime: time() + 1);
        $client = new ReweClient(
            database: new Database(path: $databasePath),
            httpClient: new HttpClient(),
            cookieFile: $cookieFile,
            productCatalogFile: $catalogFile
        );
        $progress = [];

        $productCount = $client->downloadProductCatalog(
            progress: static function (int $current, int $total, int $products, string $sorting) use (
                &$progress
            ): void {
                $progress = [$current, $total, $products, $sorting];
            }
        );

        $this->assertSame(1, $productCount);
        $this->assertSame([1, 1, 1, 'Cache'], $progress);
        $this->assertSame('potato-listing', $client->productsForIngredient(name: 'Kartoffeln')[0]['listing_id']);
        unset($client);
        foreach (glob(pattern: $directory . '/*') ?: [] as $file) {
            unlink(filename: $file);
        }
        rmdir(directory: $directory);
    }

    public function testBasketStateIsParsed(): void
    {
        $basket = $this->client()->parseBasket(
            html: '<script>window.ReweBasket.id = "basket-1"; window.ReweBasket.listingIdToQuantityLookup = {"listing-a":2,"listing-b":1};</script><script type="application/json">{&quot;isLoggedIn&quot;:true}</script>'
        );

        $this->assertSame('basket-1', $basket['id']);
        $this->assertSame(['listing-a', 'listing-b'], $basket['listing_ids']);
        $this->assertSame(['listing-a' => 2, 'listing-b' => 1], $basket['listing_quantities']);
        $this->assertTrue($basket['logged_in']);
    }

    public function testCurrentBasketStateIsParsed(): void
    {
        $basket = $this->client()->parseBasket(
            html: <<<'HTML'
                <script>
                    window.ReweBasket.id = "basket-2";
                    window.ReweBasket.listingIdToQuantityLookup = {
                        "listing-a": { quantity: 2, orderLimit: 15, details: { price: 279 } },
                        "listing-b": { quantity: 1, orderLimit: 99, details: { price: 129 } },
                    };
                </script>
                <script type="application/json">{&quot;isLoggedIn&quot;:true}</script>
            HTML
        );

        $this->assertSame('basket-2', $basket['id']);
        $this->assertSame(['listing-a', 'listing-b'], $basket['listing_ids']);
        $this->assertSame(['listing-a' => 2, 'listing-b' => 1], $basket['listing_quantities']);
        $this->assertTrue($basket['logged_in']);
    }

    public function testUnavailableBasketItemsAreIncludedForRemoval(): void
    {
        $basket = $this->client()->parseBasket(
            html: <<<'HTML'
                <script type="module">
                    const data = {"id":"basket-3","lineItems":[{"quantity":1,"product":{"listing":{"listingId":"listing-available"}}},{"quantity":0,"product":{"listing":{"listingId":"listing-unavailable"}},"changes":[{"id":"changes.type.availability.in.region"}]}]};
                </script>
                <script type="application/json">{&quot;isLoggedIn&quot;:true}</script>
            HTML
        );

        $this->assertSame('basket-3', $basket['id']);
        $this->assertSame(['listing-available', 'listing-unavailable'], $basket['listing_ids']);
        $this->assertSame(['listing-available' => 1], $basket['listing_quantities']);
        $this->assertTrue($basket['logged_in']);
    }

    public function testEmptyLoggedOutBasketStateIsParsed(): void
    {
        $basket = $this->client()->parseBasket(
            html: '<script>window.ReweBasket.id = ""; window.ReweBasket.listingIdToQuantityLookup = {};</script><script type="application/json">{&quot;isLoggedIn&quot;:false}</script>'
        );

        $this->assertSame('', $basket['id']);
        $this->assertSame([], $basket['listing_ids']);
        $this->assertSame([], $basket['listing_quantities']);
        $this->assertFalse($basket['logged_in']);
    }

    public function testEmptySearchResultIsReadFromCache(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->saveIngredientMapping(
            key: 'kartoffeln',
            query: 'Kartoffeln',
            products: [],
            searchVersion: ReweClient::PRODUCT_SEARCH_VERSION
        );
        $client = new ReweClient(database: $database, httpClient: new HttpClient(), cookieFile: '/does/not/exist');

        $this->assertSame([], $client->productsForIngredient(name: 'Kartoffeln'));
        unlink(filename: $path);
    }

    public function testNonEmptyResultFromPreviousSearchVersionRemainsCached(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $products = [['listing_id' => 'product-1']];
        $database->saveIngredientMapping(
            key: 'kartoffeln',
            query: 'Kartoffeln',
            products: $products,
            searchVersion: ReweClient::PRODUCT_SEARCH_VERSION - 1
        );
        $client = new ReweClient(database: $database, httpClient: new HttpClient(), cookieFile: '/does/not/exist');

        $this->assertSame($products, $client->productsForIngredient(name: 'Kartoffeln'));
        unlink(filename: $path);
    }

    public function testProductSearchQueriesRemoveQualifiersAndUseKnownSynonyms(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'productSearchQueries');

        $this->assertSame(['Limette, gewachst', 'Limette'], $method->invoke($client, 'Limette, gewachst'));
        $this->assertSame(
            ['gemischte Hackfleischzubereitung', 'gemischte Hackfleisch'],
            $method->invoke($client, 'gemischte Hackfleischzubereitung')
        );
        $this->assertSame(['Kartoffelstärke', 'Speisestärke'], $method->invoke($client, 'Kartoffelstärke'));
        $this->assertSame(['Stangenbohnen', 'grüne Bohnen'], $method->invoke($client, 'Stangenbohnen'));
        $this->assertContains('Knoblauch', $method->invoke($client, 'Knoblauchzehe'));
        $this->assertContains('Kirschtomaten', $method->invoke($client, 'rote Kirschtomaten'));
        $this->assertContains('Balsamico Creme', $method->invoke($client, 'Balsamicocreme'));
        $this->assertContains(
            'Paprika edelsüß',
            $method->invoke($client, 'Gewürzmischung „Hello Paprika“')
        );
        $this->assertContains('Petersilie', $method->invoke($client, 'Petersilie glatt/Schnittlauch'));
        $this->assertContains('Schnittlauch', $method->invoke($client, 'Petersilie glatt/Schnittlauch'));
        $this->assertContains('Rinder-Minutensteaks', $method->invoke($client, 'Bio Rinderhüftsteak'));
        $this->assertContains('Paprika geräuchert', $method->invoke($client, 'Paprikapulver, geräuchert'));
        $this->assertContains('Hot Dog Rolls', $method->invoke($client, 'Hot-Dog-Brötchen'));
        $this->assertContains('Kabeljaufilet', $method->invoke($client, 'Kabeljaufilet ohne Haut'));
        $this->assertContains('Soba Noodles', $method->invoke($client, 'Sobanudeln'));
        $this->assertContains('Reis Noodles', $method->invoke($client, 'Reisnudeln'));
        $this->assertContains('Cherrytomaten', $method->invoke($client, 'Kirschtomaten'));
        $this->assertNotContains(
            'Karotte',
            $method->invoke($client, 'Karotte, Brokkoli, Babymais, Buschbohnen Mix')
        );
    }

    public function testSobaNoodlesPreferUnseasonedNoodlesFromCatalog(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $client = new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: '/does/not/exist'
        );
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'hydrateProductCatalog');
        $method->invoke(
            $client,
            [
                [
                    'product_id' => 'instant-soba',
                    'listing_id' => 'instant-soba-listing',
                    'name' => 'Nissin Soba Nudeln mit Yakisoba-Sauce Classic 90g',
                    'url' => 'https://www.rewe.de/shop/p/instant-soba/1',
                    'discount' => false
                ],
                [
                    'product_id' => 'soba-noodles',
                    'listing_id' => 'soba-noodles-listing',
                    'name' => 'Ayuko Soba Noodles nach japanischer Art 300g',
                    'url' => 'https://www.rewe.de/shop/p/soba-noodles/2',
                    'discount' => false
                ]
            ]
        );

        $products = $client->productsForIngredient(name: 'Sobanudeln');

        $this->assertSame('soba-noodles-listing', $products[0]['listing_id']);
        unlink(filename: $path);
    }

    public function testCreamMappingRejectsDifferentProductsAndCoversTheRecipeAmount(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $client = new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: '/does/not/exist'
        );
        new \ReflectionClass(objectOrClass: $client)
            ->getMethod(name: 'hydrateProductCatalog')
            ->invoke(
                $client,
                [
                    [
                        'listing_id' => 'sour-cream',
                        'name' => 'REWE Bio Saure Sahne 200g',
                        'url' => 'https://www.rewe.de/shop/p/saure-sahne/1',
                        'base_quantity' => 200,
                        'quantity_type' => 'G',
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'cream-pudding',
                        'name' => 'Ruf Sahne-Pudding 3 Stück',
                        'url' => 'https://www.rewe.de/shop/p/sahne-pudding/2',
                        'base_quantity' => 3,
                        'quantity_type' => 'STK',
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'cooking-cream',
                        'name' => 'REWE Beste Wahl H-Sahne zum Kochen 15% 200g',
                        'url' => 'https://www.rewe.de/shop/p/koch-sahne/3',
                        'base_quantity' => 200,
                        'quantity_type' => 'G',
                        'discount' => false
                    ]
                ]
            );

        $products = $client->productsForIngredient(name: 'Sahne');
        $selected = $client->selectProductForIngredient(name: 'Sahne', amount: 400, unit: 'ml', products: $products);

        $this->assertCount(1, $products);
        $this->assertSame('cooking-cream', $selected['listing_id']);
        $this->assertSame(2, $selected['quantity']);
        unlink(filename: $path);
    }

    public function testPieceIngredientPrefersTheSmallMatchingProductOverAFlavouredProduct(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $client = new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: '/does/not/exist'
        );
        new \ReflectionClass(objectOrClass: $client)
            ->getMethod(name: 'hydrateProductCatalog')
            ->invoke(
                $client,
                [
                    [
                        'listing_id' => 'onion-bag',
                        'name' => 'Zwiebeln 1,5kg',
                        'url' => 'https://www.rewe.de/shop/p/zwiebeln/1',
                        'base_quantity' => 1500,
                        'quantity_type' => 'G',
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'red-onion',
                        'name' => 'Zwiebel rot ca. 100g',
                        'url' => 'https://www.rewe.de/shop/p/zwiebel-rot/2',
                        'base_quantity' => 100,
                        'quantity_type' => 'G',
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'onion-cream-cheese',
                        'name' => 'REWE Beste Wahl Frischkäse Rote Zwiebel-Porre 100g',
                        'url' => 'https://www.rewe.de/shop/p/frischkaese/3',
                        'base_quantity' => 100,
                        'quantity_type' => 'G',
                        'discount' => false
                    ]
                ]
            );

        $products = $client->productsForIngredient(name: 'rote Zwiebel');
        $selected = $client->selectProductForIngredient(
            name: 'rote Zwiebel',
            amount: 1,
            unit: 'Stück',
            products: $products
        );

        $this->assertNotContains('onion-cream-cheese', array_column(array: $products, column_key: 'listing_id'));
        $this->assertSame('red-onion', $selected['listing_id']);
        $this->assertSame(1, $selected['quantity']);
        unlink(filename: $path);
    }

    public function testProductSelectionRejectsWrongCategoriesProcessedProductsAndForms(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $client = new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: '/does/not/exist'
        );
        new \ReflectionClass(objectOrClass: $client)
            ->getMethod(name: 'hydrateProductCatalog')
            ->invoke(
                $client,
                [
                    [
                        'listing_id' => 'paprika-pastry',
                        'name' => 'Paprika Stange',
                        'url' => 'https://www.rewe.de/shop/p/paprika-stange/1',
                        'base_quantity' => 1,
                        'quantity_type' => 'ST',
                        'category_paths' => ['Brot, Cerealien & Aufstriche/Backwaren/Herzhafte Backwaren'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'red-pepper',
                        'name' => 'Paprika rot ca. 250g',
                        'url' => 'https://www.rewe.de/shop/p/paprika-rot/2',
                        'base_quantity' => 250,
                        'quantity_type' => 'G',
                        'category_paths' => ['Obst & Gemüse/Frisches Gemüse/Paprika'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'honey-candy',
                        'name' => 'Wick Honig 72g',
                        'url' => 'https://www.rewe.de/shop/p/wick-honig/3',
                        'base_quantity' => 72,
                        'quantity_type' => 'G',
                        'category_paths' => ['Süßes & Salziges/Süßwaren/Bonbons & Kaugummi'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'honey',
                        'name' => 'REWE Bio Blütenhonig 350g',
                        'url' => 'https://www.rewe.de/shop/p/bluetenhonig/4',
                        'base_quantity' => 350,
                        'quantity_type' => 'G',
                        'category_paths' => ['Brot, Cerealien & Aufstriche/Süße Brotaufstriche/Honig'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'broccoli-nuggets',
                        'name' => 'New leaf Brokkoli-Käse Nuggets 300g',
                        'url' => 'https://www.rewe.de/shop/p/brokkoli-nuggets/5',
                        'base_quantity' => 300,
                        'quantity_type' => 'G',
                        'category_paths' => ['Bewusste Ernährung/100% Pflanzlich/Pflanzliche Fertiggerichte'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'broccoli',
                        'name' => 'Broccoli 500g',
                        'url' => 'https://www.rewe.de/shop/p/broccoli/6',
                        'base_quantity' => 500,
                        'quantity_type' => 'G',
                        'category_paths' => ['Obst & Gemüse/Frisches Gemüse/Kohl & Brokkoli'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'hard-cheese-piece',
                        'name' => 'Tetê-de-Moine Hartkäse am Stück',
                        'url' => 'https://www.rewe.de/shop/p/hartkaese/7',
                        'base_quantity' => 400,
                        'quantity_type' => 'G',
                        'category_paths' => ['Käse, Eier & Molkerei/Käse & Käseersatz/Hartkäse'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'grated-hard-cheese',
                        'name' => 'Marca Italia Hartkäse gerieben und getrocknet 80g',
                        'url' => 'https://www.rewe.de/shop/p/hartkaese-gerieben/8',
                        'base_quantity' => 80,
                        'quantity_type' => 'G',
                        'category_paths' => ['Käse, Eier & Molkerei/Käse & Käseersatz/Hartkäse'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'mozzarella-pastry',
                        'name' => 'Käse Mozzarella-Fächer',
                        'url' => 'https://www.rewe.de/shop/p/mozzarella-faecher/9',
                        'base_quantity' => 1,
                        'quantity_type' => 'ST',
                        'category_paths' => [
                            'Brot, Cerealien & Aufstriche/Backwaren/Süße Backwaren/Kuchen & Mini-Kuchen'
                        ],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'buffalo-mozzarella',
                        'name' => 'Marca Italia Mozzarella di Bufala 125g',
                        'url' => 'https://www.rewe.de/shop/p/mozzarella-di-bufala/10',
                        'base_quantity' => 125,
                        'quantity_type' => 'G',
                        'category_paths' => ['Käse, Eier & Molkerei/Käse & Käseersatz/Mozzarella'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'lemongrass-tea',
                        'name' => 'Fuze Tea Schwarzer Tee Zitrone Zitronengras 1,25l',
                        'url' => 'https://www.rewe.de/shop/p/fuze-tea-zitronengras/11',
                        'base_quantity' => 1.25,
                        'quantity_type' => 'L',
                        'category_paths' => ['Getränke & Genussmittel/Erfrischungsgetränke/Eistee'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'tomato-crackers',
                        'name' => 'Tuc Bake Rolls Tomate & Olive 150g',
                        'url' => 'https://www.rewe.de/shop/p/bake-rolls-tomate/12',
                        'base_quantity' => 150,
                        'quantity_type' => 'G',
                        'category_paths' => ['Süßes & Salziges/Chips & Knabbereien/Cracker'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'tomato-baguette',
                        'name' => 'ja! Baguette Tomate Mozzarella 6x125g',
                        'url' => 'https://www.rewe.de/shop/p/baguette-tomate/13',
                        'base_quantity' => 125,
                        'quantity_type' => 'G',
                        'category_paths' => ['Tiefkühlkost/Pizza & Baguettes/Pizzabaguettes & Ofenbrote'],
                        'discount' => false
                    ],
                    [
                        'listing_id' => 'tomato',
                        'name' => 'Tomate San Marzano 1 Stück ca. 100g',
                        'url' => 'https://www.rewe.de/shop/p/tomate/14',
                        'base_quantity' => 1,
                        'quantity_type' => 'ST',
                        'category_paths' => ['Obst & Gemüse/Frisches Gemüse/Tomaten'],
                        'discount' => false
                    ]
                ]
            );

        $paprika = $client->selectProductForIngredient(
            name: 'rote Paprika',
            amount: 1,
            unit: 'Stück',
            products: $client->productsForIngredient(name: 'rote Paprika')
        );
        $honey = $client->selectProductForIngredient(
            name: 'Honig',
            amount: 28,
            unit: 'g',
            products: $client->productsForIngredient(name: 'Honig')
        );
        $broccoli = $client->selectProductForIngredient(
            name: 'Brokkoli',
            amount: 0.75,
            unit: 'Stück',
            products: $client->productsForIngredient(name: 'Brokkoli')
        );
        $hardCheese = $client->selectProductForIngredient(
            name: 'geriebener Hartkäse',
            amount: 20,
            unit: 'g',
            products: $client->productsForIngredient(name: 'geriebener Hartkäse')
        );
        $buffaloMozzarellaProducts = $client->productsForIngredient(name: 'Büffelmozzarella');
        $buffaloMozzarella = $client->selectProductForIngredient(
            name: 'Büffelmozzarella',
            amount: 2,
            unit: 'Stück',
            products: $buffaloMozzarellaProducts
        );
        $lemongrassProducts = $client->productsForIngredient(name: 'Zitronengras');
        $tomatoProducts = $client->productsForIngredient(name: 'Tomate');
        $tomato = $client->selectProductForIngredient(
            name: 'Tomate',
            amount: 3,
            unit: 'Stück',
            products: $tomatoProducts
        );

        $this->assertSame('red-pepper', $paprika['listing_id']);
        $this->assertSame('honey', $honey['listing_id']);
        $this->assertSame('broccoli', $broccoli['listing_id']);
        $this->assertSame('grated-hard-cheese', $hardCheese['listing_id']);
        $this->assertNotContains(
            'mozzarella-pastry',
            array_column(array: $buffaloMozzarellaProducts, column_key: 'listing_id')
        );
        $this->assertSame('buffalo-mozzarella', $buffaloMozzarella['listing_id']);
        $this->assertSame([], $lemongrassProducts);
        $this->assertNotContains('tomato-crackers', array_column(array: $tomatoProducts, column_key: 'listing_id'));
        $this->assertNotContains('tomato-baguette', array_column(array: $tomatoProducts, column_key: 'listing_id'));
        $this->assertSame('tomato', $tomato['listing_id']);
        unlink(filename: $path);
    }

    public function testProductCompatibilityUsesIngredientIntentAndProductCategories(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'productIsCompatible');

        $this->assertFalse(
            $method->invoke($client, 'Milch', 'ja! Milchreis 500g', ['Kochen & Backen/Reis/Milchreis'], 'Milch')
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Mais',
                'ja! Erdnuss-Mais-Mix 150g',
                ['Süßes & Salziges/Nüsse/Erdnüsse'],
                'Mais'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Salsiccia',
                'GrünGold Seitan Salsiccia vegan 240g',
                ['Fleisch & Fisch/Fleischalternativen/Veggie-Würstchen'],
                'Salsiccia'
            )
        );
        $this->assertTrue(
            $method->invoke(
                $client,
                'Salsiccia',
                'REWE Feine Welt Salsiccia Classica 300g',
                ['Fleisch & Fisch/Wurst & Aufschnitt/Brüh- & Bratwurst'],
                'Salsiccia'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Oregano',
                'ja! Oregano gerebelt 12g',
                ['Öle, Soßen & Gewürze/Gewürze/Gewürzkräuter'],
                'frischer Oregano'
            )
        );
        $this->assertTrue(
            $method->invoke(
                $client,
                'Oregano',
                'Oregano 20g',
                ['Obst & Gemüse/Frische Kräuter/Oregano'],
                'frischer Oregano'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Quinoa',
                'REWE Bio Quinoa Weiß 500g',
                ['Kochen & Backen/Getreide/Quinoa'],
                'roter Quinoa'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Tandoori',
                'REWE To Go Tandoori Chicken Style Sandwich 160g',
                ['Fertiggerichte & Konserven/Fertiggerichte/Frische Wraps & Sandwiches'],
                'Gewürzmischung Tandoori'
            )
        );
        $this->assertTrue(
            $method->invoke(
                $client,
                'Tandoori',
                'Tandoori Gewürzmischung 50g',
                ['Öle, Soßen & Gewürze/Gewürzmischungen'],
                'Gewürzmischung Tandoori'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Hüftsteak',
                'Schweine Hüftsteaks 300g',
                ['Fleisch & Fisch/Fleisch/Schweinefleisch'],
                'Hüftsteak vom Weiderind'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Schokolade',
                'Kinder Schokolade 50g',
                ['Süßes & Salziges/Schokolade'],
                'Schokolade 60%'
            )
        );
        $this->assertTrue(
            $method->invoke(
                $client,
                'Schokolade',
                'Gepa Bio Schokolade Zartbitter 70% 100g',
                ['Süßes & Salziges/Schokolade/Tafelschokolade'],
                'Schokolade 60%'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Manti',
                'Bruno Banani Deospray Man 150ml',
                ['Drogerie & Gesundheit/Körperpflege/Deodorants'],
                'Manti'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Bärlauch',
                'Schweine Hüftsteaks Tomate Bärlauch 300g',
                ['Fleisch & Fisch/Fleisch/Schweinefleisch'],
                'Bärlauch'
            )
        );
        $queries = new \ReflectionClass(objectOrClass: $client)
            ->getMethod(name: 'productSearchQueries')
            ->invoke($client, 'Hüftsteak vom Weiderind');
        $this->assertContains('RinderHüftsteak', $queries);
        $this->assertContains('Rinder-Minutensteaks', $queries);
    }

    public function testProductCompatibilityRejectsProcessedVariantsOfBasicIngredients(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'productIsCompatible');

        $this->assertTrue(
            $method->invoke(
                $client,
                'Gurke',
                'Salatgurke 1 Stück',
                ['Obst & Gemüse/Frisches Gemüse/Gurken'],
                'Gurke'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Gurke',
                'Specht Senf-Gurken 215g',
                ['Fertiggerichte & Konserven/Gemüsekonserven/Gewürzgurken'],
                'Gurke'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Kirschtomaten',
                'REWE Bio Kirschtomaten ganz 400g',
                ['Fertiggerichte & Konserven/Gemüsekonserven/Tomaten-Konserven'],
                'Kirschtomaten'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Seelachs',
                'Nadler Alaska Seelachs Mus 125g',
                ['Fleisch & Fisch/Fisch & Meeresfrüchte/Lachs & Seelachs'],
                'Seelachs'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Spinat',
                'Iglo Spinat mit Alpro vegan 550g',
                ['Tiefkühlkost/Tiefkühl-Gemüse/Tiefkühl-Spinat'],
                'Spinat'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'körniger Senf',
                'Maille Dijon-Senf Honig 200ml',
                ['Öle, Soßen & Gewürze/Soßen/Senf & Senfsoßen/Dijon-Senf'],
                'körniger Senf'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Sahnekefir',
                'ja! Sahnekefir mild Kirsche 250g',
                ['Käse, Eier & Molkerei/Joghurt, Desserts & Alternativen'],
                'Sahnekefir'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'lila Karotte',
                'Karotten 1kg',
                ['Obst & Gemüse/Frisches Gemüse/Wurzelgemüse/Möhren'],
                'lila Karotte'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Orange',
                'Ruf Bio Orangen Schale gerieben 5g',
                ['Kochen & Backen/Backzutaten/Klassische Backzutaten/Backaromen'],
                'Orange, bio'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Paprika',
                'Paprika rot 500g',
                ['Obst & Gemüse/Frisches Gemüse/Paprika & Chili'],
                'Paprika, eingelegt'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Chili-Nudeln',
                'Followfood Bio Tasty + Saucy Sweet Chili Noodles 160g',
                ['Fertiggerichte & Konserven/Instant-Nudeln'],
                'Chili-Nudeln'
            )
        );
        $this->assertFalse(
            $method->invoke(
                $client,
                'Orange',
                'Meßmer Bio Orange Ingwer 55g, 20 Beutel',
                ['Kaffee, Tee & Kakao/Tee/Früchtetee'],
                'Orange, bio'
            )
        );
    }

    public function testSmallSeasoningAmountPrefersSpiceOverFreshProduce(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $client = new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: '/does/not/exist'
        );
        new \ReflectionClass(objectOrClass: $client)
            ->getMethod(name: 'hydrateProductCatalog')
            ->invoke(
                $client,
                [
                    [
                        'listing_id' => 'fresh-chili-mix',
                        'name' => 'REWE Bio Chili Mix 80g',
                        'base_quantity' => 80,
                        'quantity_type' => 'G',
                        'category_paths' => ['Obst & Gemüse/Frisches Gemüse/Paprika & Chili']
                    ],
                    [
                        'listing_id' => 'ground-chili-mix',
                        'name' => 'REWE Beste Wahl Chili Mix gemahlen 39g',
                        'base_quantity' => 39,
                        'quantity_type' => 'G',
                        'category_paths' => ['Öle, Soßen & Gewürze/Gewürze/Chili-Gewürz']
                    ],
                    [
                        'listing_id' => 'dried-basil',
                        'name' => 'ja! Basilikum gerebelt 15g',
                        'base_quantity' => 15,
                        'quantity_type' => 'G',
                        'category_paths' => ['Öle, Soßen & Gewürze/Gewürze/Gewürzkräuter']
                    ],
                    [
                        'listing_id' => 'fresh-basil',
                        'name' => 'REWE Bio Basilikum im Topf',
                        'category_paths' => ['Obst & Gemüse/Frische Kräuter/Basilikum']
                    ],
                    [
                        'listing_id' => 'ground-coriander',
                        'name' => 'REWE Beste Wahl Koriander gemahlen 32g',
                        'base_quantity' => 32,
                        'quantity_type' => 'G',
                        'category_paths' => ['Öle, Soßen & Gewürze/Gewürze/Gewürzkräuter']
                    ],
                    [
                        'listing_id' => 'dried-coriander-leaves',
                        'name' => 'Ostmann Korianderblätter gerebelt 10g',
                        'base_quantity' => 10,
                        'quantity_type' => 'G',
                        'category_paths' => ['Öle, Soßen & Gewürze/Gewürze/Gewürzkräuter']
                    ]
                ]
            );

        $products = $client->productsForIngredient(name: 'milder Chili-Mix');
        $selected = $client->selectProductForIngredient(
            name: 'milder Chili-Mix',
            amount: 2,
            unit: 'g',
            products: $products
        );
        $corianderProducts = $client->productsForIngredient(name: 'Koriander');
        $coriander = $client->selectProductForIngredient(
            name: 'Koriander',
            amount: 10,
            unit: 'g',
            products: $corianderProducts
        );

        $this->assertSame('ground-chili-mix', $selected['listing_id']);
        $this->assertSame('fresh-basil', $client->productsForIngredient(name: 'Basilikum')[0]['listing_id']);
        $this->assertSame('dried-coriander-leaves', $coriander['listing_id']);
        unlink(filename: $path);
    }

    public function testCatalogMatchingRejectsUnrelatedPrefixesAndKeepsRelevanceAheadOfPackageSize(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $client = new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: '/does/not/exist'
        );
        new \ReflectionClass(objectOrClass: $client)
            ->getMethod(name: 'hydrateProductCatalog')
            ->invoke(
                $client,
                [
                    [
                        'listing_id' => 'mango',
                        'name' => 'Mango 1 Stück',
                        'base_quantity' => 1,
                        'quantity_type' => 'ST',
                        'category_paths' => ['Obst & Gemüse/Frisches Obst/Mango']
                    ],
                    [
                        'listing_id' => 'fried-onions',
                        'name' => 'REWE Beste Wahl Röstzwiebeln 150g',
                        'base_quantity' => 150,
                        'quantity_type' => 'G',
                        'category_paths' => ['Öle, Soßen & Gewürze/Gewürze/Zwiebel-Gewürz']
                    ],
                    [
                        'listing_id' => 'potato-snack',
                        'name' => 'Kartoffelpüree mit Röstzwiebeln und Croûtons 59g',
                        'base_quantity' => 59,
                        'quantity_type' => 'G',
                        'category_paths' => ['Fertiggerichte & Konserven/Instant-Snacks/Kartoffel-Snack']
                    ]
                ]
            );

        $this->assertSame([], $client->productsForIngredient(name: 'Mangold'));
        $onionProducts = $client->productsForIngredient(name: 'Würziges Zwiebel-Chutney');
        $selected = $client->selectProductForIngredient(
            name: 'Würziges Zwiebel-Chutney',
            amount: 53.6,
            unit: 'g',
            products: $onionProducts
        );
        $this->assertSame('fried-onions', $selected['listing_id']);
        unlink(filename: $path);
    }

    public function testCloudflareBasketChallengeHasSpecificError(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'assertBasketResponse');

        $this->expectException(exception: ReweAccessException::class);
        $this->expectExceptionMessage(message: 'Cloudflare-Menschprüfung');
        $method->invoke(
            $client,
            new HttpResponse(
                status: 403,
                body: '<h1>Zeig uns, dass du ein Mensch bist.</h1><script>window._cf_chl_opt = {};</script>'
            )
        );
    }

    public function testEveryForbiddenReweResponseIsTreatedAsCloudflareBlock(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'isCloudflareChallenge');

        $this->assertTrue(
            $method->invoke($client, new HttpResponse(status: 403, body: '<script>window._cf_chl_opt = {};</script>'))
        );
        $this->assertTrue($method->invoke($client, new HttpResponse(status: 403, body: 'Forbidden')));
        $this->assertFalse($method->invoke($client, new HttpResponse(status: 401, body: 'Unauthorized')));
    }

    public function testCloudflareAccessIsRetriedWithTheConfiguredBackoff(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'retryReweAccess');
        $attempts = 0;
        $retries = [];

        $result = $method->invoke(
            $client,
            static function () use (&$attempts): string {
                $attempts++;
                if ($attempts < 3) {
                    throw ReweAccessException::cloudflareChallenge();
                }
                return 'success';
            },
            [0, 0],
            static function (int $delaySeconds, int $nextAttempt, int $totalAttempts) use (&$retries): void {
                $retries[] = [$delaySeconds, $nextAttempt, $totalAttempts];
            },
            null
        );

        $this->assertSame('success', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame([[0, 2, 3], [0, 3, 3]], $retries);
    }

    private function client(?string $cookieFile = null): ReweClient
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        return new ReweClient(
            database: new Database(path: $path),
            httpClient: new HttpClient(),
            cookieFile: $cookieFile ?? '/does/not/exist'
        );
    }
}
