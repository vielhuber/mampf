<?php
declare(strict_types=1);

namespace Mampf\Tests;

use Mampf\Database;
use Mampf\HttpClient;
use Mampf\ReweClient;
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

    public function testProductsAreParsedAndRankedWithSmallDiscountBonus(): void
    {
        $client = $this->client();
        $html = <<<'HTML'
            <div id="plr-1"><a href="/shop/p/irrelevant/1"><img src="one.jpg"><h4>Schokolade</h4></a><script type="application/json" data-tracking-type="product">{"listingId":"listing-1","discount":true,"price":1.2}</script></div>
            <div id="plr-2"><a href="/shop/p/kartoffeln/2"><img src="two.jpg"><h4>REWE Beste Wahl Kartoffeln festkochend</h4></a><script type="application/json" data-tracking-type="product">{"id":"listing-2","discount":false,"price":2.5}</script></div>
        HTML;

        $products = $client->parseProducts(html: $html, query: 'Kartoffeln');

        $this->assertSame('listing-2', $products[0]['listing_id']);
        $this->assertSame('https://www.rewe.de/shop/p/kartoffeln/2', $products[0]['url']);
        $this->assertTrue($products[1]['discount']);
    }

    public function testBasketStateIsParsed(): void
    {
        $basket = $this->client()->parseBasket(
            html: '<script>window.ReweBasket.id = "basket-1"; window.ReweBasket.listingIdToQuantityLookup = {"listing-a":2,"listing-b":1};</script>'
        );

        $this->assertSame('basket-1', $basket['id']);
        $this->assertSame(['listing-a', 'listing-b'], $basket['listing_ids']);
    }

    public function testEmptySearchResultIsReadFromCache(): void
    {
        $path = sys_get_temp_dir() . '/mampf-' . bin2hex(string: random_bytes(length: 8)) . '.sqlite';
        $database = new Database(path: $path);
        $database->saveIngredientMapping(key: 'kartoffeln', query: 'Kartoffeln', products: []);
        $client = new ReweClient(database: $database, httpClient: new HttpClient(), cookieFile: '/does/not/exist');

        $this->assertSame([], $client->productsForIngredient(name: 'Kartoffeln'));
        unlink(filename: $path);
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
