# Adornia Jems

Adornia Jems is a Laravel storefront and admin workspace for a Nigerian jewelry brand. It brings a 3D product showcase, NGN checkout, and day-to-day store operations into one application.

**Stack** · Laravel 13 · PHP · Blade · Eloquent · Three.js · Vanilla JavaScript

## What it does

| Storefront | Administration |
| --- | --- |
| Interactive 3D ring, searchable catalog, and product photography | Product and stock management, plus editable homepage sections and branding |
| Accounts, wishlists, shopping bag, and buy-again orders | Order fulfillment, courier tracking, and filtered sales CSV exports |
| Nigeria delivery zones, with pay on delivery where enabled | Delivery pricing and pay-on-delivery rules by state or zone |
| Paystack, Flutterwave, Kora, wallet balance, and manual bank transfers | Separate test and live payment settings, receipt review, and payment records |
| Order timeline, shipment tracking, and email updates | SMTP settings, queued notifications, and delivery logs |

Gateway payments are verified server-side before an order is marked paid or a wallet is credited. Test payments use a separate balance. Customers upload manual transfer proofs privately for admin review.

## Run locally

Use PHP **8.4.1 or newer** for the versions in `composer.lock`, Composer 2, and the PDO SQLite extension for the local database. Blade and the checked-in frontend assets require no Node build.

```sh
composer install
php -r "copy('.env.example', '.env'); touch('database/database.sqlite');"
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan store:setup-admin
php artisan serve
```

Run these steps in a fresh clone. `store:setup-admin` asks for an email and generates a temporary password that must be changed on first sign-in. Open [the storefront](http://127.0.0.1:8000/) or [the admin sign-in](http://127.0.0.1:8000/admin/login). Demo seeding adds sample products and inactive delivery zones. Enable a zone with a delivery fee in admin to try checkout.

```sh
php artisan test
```

| Path | Responsibility |
| --- | --- |
| `routes/` and `app/Http/` | Storefront, account, checkout, and admin entry points |
| `app/Services/` | Payment verification, wallet ledger updates, delivery quotes, and notifications |
| `app/Jobs/` and `app/Mail/` | Background delivery of transactional email |
| `resources/views/` and `public/` | Blade templates, storefront styles, and browser scripts |
| `database/` and `tests/Feature/` | Schema, seed data, and HTTP feature tests |

## Credentials and private data

- Commit `.env.example` with empty secret fields. Keep `.env`, private keys, database files, uploads, and payment receipts out of Git.
- Generate `APP_KEY` once per environment and keep it stable. The app uses it to encrypt gateway credentials and SMTP settings entered in admin.
- Configure provider keys and webhook secrets in the admin dashboard or through server environment variables. Configure SMTP in admin or through the mail environment variables. Local mail defaults to the log driver.
- Tests use synthetic provider values and a generated account password. They do not require live payment or SMTP credentials.

## Deploy to cPanel

1. Clone the private repository into your cPanel home directory with a read-only SSH deploy key. Point the domain’s document root to the clone’s `public/` directory so the site opens at the domain URL. If the host locks your main domain’s document root, ask the host to map it to Laravel’s `public/` directory. [cPanel private Git setup](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-access-to-private-repositories/)
2. Select PHP 8.4.1 or newer for both the website and the cPanel terminal. Create a production database and user, then create the server’s `.env` from `.env.example`. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the HTTPS domain, `SESSION_SECURE_COOKIE=true`, and the `DB_*` settings. Ensure PHP can write to `storage/` and `bootstrap/cache/`. [Laravel deployment guide](https://laravel.com/docs/13.x/deployment)
3. Choose the browser installer below or run the terminal setup for a fresh deployment:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --class=HomepageSeeder --force
   php artisan db:seed --class=DeliverySeeder --force
   php artisan storage:link
   php artisan store:setup-admin
   php artisan optimize
   ```

4. Add the real catalog, delivery rates, payment settings, and SMTP credentials in admin. Verify your payment webhooks against the HTTPS domain before accepting live payments.
5. Run `php artisan schedule:run` every minute. Run `php artisan queue:work database --queue=emails --tries=3` under a process manager, or use the `--stop-when-empty` option in a non-overlapping cPanel cron job. Use your host’s full PHP CLI path.

For updates, pull `main`, install from `composer.lock`, run `php artisan migrate --force` and `php artisan optimize`, then restart any long-running queue worker with `php artisan queue:restart`. Keep the existing production `APP_KEY` and database.

### Browser installer

Use this option on a fresh deployment after creating the database and configuring the server's `.env`. Run Composer and generate the application key before opening the page:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan key:generate
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Keep `SESSION_DRIVER=file` during initial setup. Set `INSTALL_TOKEN` in the server's `.env` to the generated value. If configuration was cached, run `php artisan config:clear`. Open `/install` on your HTTPS domain, enter the token, and choose the administrator's name, email, and password.

The installer applies database migrations and seeds the homepage and delivery templates. It closes once an administrator exists. Remove `INSTALL_TOKEN` from `.env` after setup, then run `php artisan storage:link` and `php artisan optimize`. Continue with the catalog, delivery zones, payment settings, and email setup in the admin dashboard.
