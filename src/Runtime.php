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
        \Dotenv\Dotenv::createImmutable(paths: $root)->safeLoad();
        foreach (['DB_HOST', 'APP_DATABASE', 'REWE_COOKIE_FILE'] as $pathVariable) {
            $path = (string) ($_SERVER[$pathVariable] ?? '');
            if ($path !== '' && !str_starts_with(haystack: $path, needle: '/')) {
                $_SERVER[$pathVariable] = $root . '/' . ltrim(string: $path, characters: '/');
            }
        }
        $databasePath = (string) ($_SERVER['APP_DATABASE'] ?? $root . '/.data/mampf.sqlite');
        $cookieFile = (string) ($_SERVER['REWE_COOKIE_FILE'] ?? $root . '/.data/cookies/rewe.json');
        $this->database = new Database(path: $databasePath);
        $this->httpClient = new HttpClient(impersonateBinary: $root . '/.bin/curl-impersonate');
        $this->helloFreshScraper = new HelloFreshScraper(database: $this->database, httpClient: $this->httpClient);
        $this->reweClient = new ReweClient(
            database: $this->database,
            httpClient: $this->httpClient,
            cookieFile: $cookieFile
        );
        $this->auth = new simpleauth(
            config: $root . '/.env',
            table: 'users',
            login: 'email',
            ttl: 365,
            uuid: false,
            passkeys: false,
            cors: false
        );
    }
}
