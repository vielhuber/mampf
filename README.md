# 🥗mampf🥗

Recipe planning with HelloFresh import, REWE ingredient matching and weekly basket creation.

## Installation

```bash
git clone https://github.com/vielhuber/mampf.git
cd mampf
composer install
npm install
npm run prod
cp .env.example .env
secret=$(php -r 'echo bin2hex(random_bytes(32));')
sed -i "s/replace-with-a-long-random-value/$secret/" .env
mkdir -p .bin
curl -fsSL https://github.com/lexiforest/curl-impersonate/releases/download/v1.5.6/curl-impersonate-v1.5.6.x86_64-linux-gnu.tar.gz \
    | tar -xz -C .bin curl-impersonate
chmod +x .bin/curl-impersonate
php _public/auth/index.php create "mail@example.org" "password"
```

Point the virtual host document root to `/var/www/mampf/_public`. Application code, configuration and SQLite databases remain outside the public document root.

The curl-impersonate command installs the pinned Linux x86_64 release from [lexiforest/curl-impersonate](https://github.com/lexiforest/curl-impersonate). The application uses its Chrome 146 profile. Use the matching release archive on other architectures.

## Import

After logging in, use **Rezepte aktualisieren** to import the public HelloFresh recipe sitemap, ingredients for two portions, ratings and favorite counts incrementally. **Zutaten zuordnen** matches missing ingredients and regularly validates existing selections against current REWE search results. Search results are reused for six hours and refreshed once per normalized ingredient afterwards. Long-running actions open a separate progress view. Only ingredients marked as shipped by HelloFresh are imported.

Each logged-in user can submit one rating and keep one personal note per recipe. Rating averages show the individual ratings on hover.

## REWE cookie

Login at `https://www.rewe.de/shop` and export all current `rewe.de` Chrome cookies as a Cookie-Editor JSON array to:

```text
.data/cookies/rewe.json
```

The export seeds a generated `rewe.json.jar`, which persists cookies refreshed by REWE between requests. Saving a newer JSON export resets the jar automatically. Both files are excluded from Git. Ordering validates every ingredient mapping before clearing the existing basket.
