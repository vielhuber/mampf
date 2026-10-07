# 🥗 mampf 🥗

recipe planning with hellofresh import, rewe ingredient matching and weekly basket creation.

<img src="screenshot.png" alt="mampf">

## installation

```bash
mkdir mampf
cd mampf
git clone https://github.com/vielhuber/mampf.git .
composer install
cp .config/.env.example .config/.env
sed -i "s/replace-with-a-long-random-value/$(php -r 'echo bin2hex(random_bytes(32));')/" .config/.env
sed -i "s/replace-with-a-random-cron-token/$(php -r 'echo bin2hex(random_bytes(32));')/" .config/.env
mkdir -p .bin
curl -fsSL https://github.com/lexiforest/curl-impersonate/releases/download/v1.5.6/curl-impersonate-v1.5.6.x86_64-linux-gnu.tar.gz | tar -xz -C .bin curl-impersonate
chmod +x .bin/curl-impersonate
php public/auth/index.php create "mail@example.org" "password"
```

## build

```bash
npm install
npm run prod
```

## auth

point a virtual host document root to `public`. export the rewe cookies with a dedicated local chrome profile:

```bash
npm run cookies:rewe
```

solve the human check, sign in, verify the delivery location and confirm the export in the terminal. the script includes httponly cookies and writes `.config/rewe-shop.json` and `.config/rewe-account.json`. chrome reuses the persistent profile in `.data/rewe-chrome-profile`, so the rewe session and settings remain available for later exports.

ingredient matching downloads the current market-specific rewe catalog in pages of 500 products for all five sorting modes, merges duplicate listings and replaces the cache on every run. unavailable listings are excluded before recipes are remapped locally without individual fallback searches. ordering reuses the latest cache.

set `PROXY_URL` in `.config/.env` to an HTTP proxy gateway such as `http://username:password@host:port` (HTTPS proxies are also accepted). alternatively, set `PROXY_LIST_URL` to a URL returning one proxy per line: `host:port:username:password` or a full proxy URL. `PROXY_URL` takes precedence when both are set. REWE requests use `curl-impersonate` through the gateway or one randomly selected list entry per application run; the list is loaded once, and an unavailable, empty or invalid list fails instead of connecting directly. leaving both values empty keeps direct access. proxy credentials are passed through a private temporary cURL configuration, removed after each request. HelloFresh requests remain direct.

## cron

update recipes and rewe ingredient mappings through cron:

```bash
curl --fail --show-error --silent "https://mampf.example.org/cron?token=YOUR_CRON_TOKEN"
```
