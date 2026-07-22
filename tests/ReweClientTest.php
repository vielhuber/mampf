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
                        'pricing' => ['currentRetailPrice' => 698, 'discount' => ['validTo' => '2026-07-24']]
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
        $this->assertFalse($products[1]['discount']);
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
                    'version' => 1,
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

    public function testCloudflareChallengeIsDetectedInReweResponses(): void
    {
        $client = $this->client();
        $method = new \ReflectionClass(objectOrClass: $client)->getMethod(name: 'isCloudflareChallenge');

        $this->assertTrue(
            $method->invoke($client, new HttpResponse(status: 403, body: '<script>window._cf_chl_opt = {};</script>'))
        );
        $this->assertFalse($method->invoke($client, new HttpResponse(status: 403, body: 'Forbidden')));
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
