# Adornia Jems

Laravel storefront and administration for a Nigerian jewelry business. Blade serves the pages and the frontend assets live in `public/`.

## cPanel deployment

Clone the private repository into your cPanel home directory using an SSH deploy key. Set the domain’s document root to the clone’s `public` directory. For a main domain with a fixed document root, ask your host to map that domain to the Laravel `public` directory. The project root contains private configuration and storage and should stay outside the document root.

Use PHP 8.4.1 or newer for the versions in `composer.lock`, with the same PHP version in cPanel Terminal and on the website. Enable the Laravel PHP extensions, including PDO for your chosen database. Create the production database and database user in cPanel.

From the clone directory, on a fresh deployment:

```sh
cp .env.example .env
composer install --no-dev --prefer-dist --optimize-autoloader
```

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to your HTTPS domain, `SESSION_SECURE_COOKIE=true`, and the production `DB_*` values in `.env`. Keep `APP_KEY` stable after the first deployment because Laravel uses it to decrypt saved credentials.

```sh
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=HomepageSeeder --force
php artisan db:seed --class=DeliverySeeder --force
php artisan storage:link
php artisan store:setup-admin
php artisan optimize
```

Run the key generation and admin setup only on a fresh instance. Use the temporary admin credentials at `/admin/login` and replace them when prompted. The production seed commands set up the homepage and delivery zone templates. Add your real catalog, enable delivery zones, and configure payment keys, webhooks, bank transfer, and SMTP in admin before accepting orders.

The host must allow PHP to write to `storage` and `bootstrap/cache`. Run `php artisan schedule:run` every minute for scheduled jobs. Run `php artisan queue:work database --queue=emails --tries=3` under a process manager, or schedule `php artisan queue:work database --queue=emails --tries=3 --stop-when-empty` via cPanel Cron Jobs with overlap protection. Use the full PHP CLI path on the host for cron. After updates, install from the lockfile, run `php artisan migrate --force` and `php artisan optimize`, then restart any long-running queue worker with `php artisan queue:restart`.

## Local development

Use `php artisan serve` to run the shop at the server root. XAMPP can also mount Apache directly to `public/` using the project-specific alias, which keeps `/public` out of the storefront URL. Production cPanel uses its domain document root instead of the Windows alias.

Run `php artisan test` to run the test suite.
