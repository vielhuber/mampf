<?php
declare(strict_types=1);

namespace Mampf;

use vielhuber\simpleauth\simpleauth;

final class Runtime
{
    public readonly Database $database;
    public readonly HttpClient $httpClient;
    public readonly HelloFreshScraper $helloFreshScraper;
    public readonly ReweClient $reweClient;
    public readonly simpleauth $auth;

    public function __construct(public readonly string $root)
    {
        \Dotenv\Dotenv::createImmutable(paths: $root . '/.config')->safeLoad();
        foreach (['DB_HOST'] as $pathVariable) {
            $path = (string) ($_SERVER[$pathVariable] ?? '');
            if ($path !== '' && !str_starts_with(haystack: $path, needle: '/')) {
                $_SERVER[$pathVariable] = $root . '/' . ltrim(string: $path, characters: '/');
            }
        }
        $databasePath = $root . '/.data/mampf.sqlite';
        $cookieFile = $root . '/.config/rewe-shop.json';
        $this->database = new Database(path: $databasePath);
        $this->httpClient = new HttpClient(impersonateBinary: $root . '/.bin/curl-impersonate');
        $this->helloFreshScraper = new HelloFreshScraper(database: $this->database, httpClient: $this->httpClient);
        $this->reweClient = new ReweClient(
            database: $this->database,
            httpClient: $this->httpClient,
            cookieFile: $cookieFile,
            cookieJarFile: $root . '/.data/rewe.json.jar'
        );
        $this->auth = new simpleauth(
            config: $root . '/.config/.env',
            table: 'users',
            login: 'email',
            ttl: 365,
            uuid: false,
            passkeys: false,
            cors: false
        );
    }
}
