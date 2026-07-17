# 🥗 mampf 🥗

recipe planning with hellofresh import, rewe ingredient matching and weekly basket creation.

<img src="screenshot.png" alt="mampf">

## installation

```bash
mkdir mampf
cd mampf
git clone https://github.com/vielhuber/mampf.git .
composer install
cp .env.example .env
sed -i "s/replace-with-a-long-random-value/$(php -r 'echo bin2hex(random_bytes(32));')/" .env
sed -i "s/replace-with-a-random-cron-token/$(php -r 'echo bin2hex(random_bytes(32));')/" .env
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

rewe requests require a linux x86-64 host that permits `exec()`, custom executables and outgoing https connections.

point a virtual host document root to `public`. sign into rewe and export the cookies with cookie-editor as json:

- [https://www.rewe.de/shop](https://www.rewe.de/shop) to `.config/rewe-shop.json`
- [https://account.rewe.de/realms/sso/account](https://account.rewe.de/realms/sso/account) to `.config/rewe-account.json`

update recipes and rewe ingredient mappings through cron:

```bash
curl --fail --show-error --silent "https://mampf.example.org/cron?token=YOUR_CRON_TOKEN"
```

runs are written to `.data/cron.log`; concurrent runs are rejected. every six hours is sufficient for recipe and product updates.
