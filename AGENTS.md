# AGENTS.md — IMS Project Deployment Context

This file documents the live deployment of the Inventory Management System (IMS) so any future development session (AI or human) has full context without re-discovering it from scratch. Keep this file updated as things change.

---

## Project Overview

- **What it is:** Admin Inventory Management System — originally a Python (Flask) prototype, rewritten to PHP/Laravel 11 for Hostinger shared hosting compatibility (Python web frameworks aren't supported on Hostinger shared hosting; only VPS).
- **Repo:** `https://github.com/gomez28-dev/IMS-prototype`
- **Stack:** Laravel 11, PHP 8.3, MySQL, Blade + Bootstrap (no Vite/Node build step — intentional, to avoid needing a build process on shared hosting).
- **Excel functionality:** Uses `maatwebsite/excel` (built on PhpSpreadsheet) for both exporting inventory to `.xlsx` and importing/seeding data from uploaded `.xlsx` files.

---

## Live Deployment

- **URL:** `https://ims.doyengroupofcompanies.com`
- **Host:** Hostinger, Business shared hosting plan, same account as the main `doyengroupofcompanies.com` site.
- **Server path:** `/home/u358921359/domains/doyengroupofcompanies.com/public_html/ims`
- **SSH:** Host `145.79.14.102`, Port `65002`, User `u358921359` (password stored privately, not here — change it via hPanel → Advanced → SSH Access if needed).
- **Database:** MySQL, database name `u358921359_ims_db`, user `u358921359_u358921359_ims` (password is in the server's `.env` file only — never committed to git).
- **PHP version:** 8.3.30 (confirmed via `php -v` on server; Laravel 11 requires 8.2+).

### Document root workaround (important)

Hostinger's hPanel does **not** allow changing a subdomain's document root to point directly at Laravel's `/public` folder on this plan — there's no UI option for it. Instead, this project uses a `.htaccess` rewrite at the project root to redirect all requests into `/public`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ /public/$1 [L]
```

This file lives at the project root (`.../ims/.htaccess`) and is **not** part of the git repo by default (Laravel's own `.gitignore` may not exclude it, but it was created manually on the server, not committed) — if the server is ever rebuilt from scratch, this file needs to be recreated manually as part of setup, or added to the repo so it's not lost.

---

## Known Issues From the Original Rewrite (fixed, but document for awareness)

The AI-driven Python-to-Laravel rewrite had several gaps that caused real deployment failures. All were fixed during initial deployment, but are worth knowing about in case similar gaps exist elsewhere in the codebase that haven't surfaced yet:

1. **`artisan` file was missing `->handleCommand(...)`** on the bootstrap line, causing every single `artisan` command to fatal-error with "Object of class Application could not be converted to string." Fixed by correcting line 17 of `artisan` to match Laravel 11's standard bootstrap pattern.
2. **`app/Http/Controllers/Controller.php`** (the base controller class every other controller extends) was missing entirely. Recreated manually.
3. **`config/auth.php`** was missing entirely — meant Laravel had no instruction to use the `Admin` model for authentication instead of the default `User` model, causing login to fail with "Class App\Models\User not found." Recreated manually, configured for the `Admin` model and `admins` guard/provider.
4. **`storage/framework/{views,cache,sessions}`, `storage/logs`, and `bootstrap/cache`** subfolders didn't exist after cloning (git doesn't track empty folders), causing cache-path errors. Created manually with `mkdir -p` and `chmod -R 775`.
5. **No `cache` table migration exists**, but `CACHE_DRIVER` defaults to `database` — this causes `php artisan cache:clear` to fail (table doesn't exist). Currently worked around in the deploy workflow with `|| true` so it doesn't fail deployments. **Not yet properly fixed** — either add a migration for a `cache` table, or switch `CACHE_DRIVER=file` in `.env` (simpler fix, no table needed).

**Recommendation for future sessions:** given how many core scaffolding files were missing, it's worth doing a deliberate audit of the rest of the codebase (compare against a fresh `laravel new` project's file structure) rather than assuming the rest is complete, since these gaps only surfaced one at a time as features were tested.

---

## Security Notes

- The default seeded admin account was `username: admin, password: admin` (from `database/seeders/DatabaseSeeder.php`). **This password has been changed** post-deployment via `php artisan tinker`. The seeder file itself still creates `admin`/`admin` by default — if the database is ever reseeded from scratch, the password will need to be manually changed again, or better, update the seeder itself to not hardcode a known-weak default.
- **No RBAC (role-based access control) exists yet** — there's currently a single shared admin account. RBAC with separate accounts/roles is planned for a future update.
- The repo contains `DPET SALES INVENTORY.xlsx` in its root — confirmed by the project owner to be **sample/template data only**, not sensitive real company data, so no action needed there. Worth re-confirming this assumption if the file's contents ever change.

---

## Deployment Process

### Auto-deploy via GitHub Actions (current method)

Every push to the `main` branch automatically deploys to the live server. Workflow file: `.github/workflows/deploy.yml`.

What it does on every push to `main`:
1. SSH into the Hostinger server (credentials via GitHub repo Secrets: `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY`)
2. `git pull origin main`
3. `composer2 install --no-dev --optimize-autoloader`
4. `php artisan migrate --force` (runs automatically, no confirmation prompt — be careful with destructive migrations)
5. `php artisan config:clear`, `view:clear`, `cache:clear` (cache:clear allowed to fail silently, see known issue #4 above)

The SSH key used for this is a dedicated deploy-only key (`~/.ssh/github_actions_deploy` on the server), separate from the personal SSH login — added to the server's `~/.ssh/authorized_keys`.

### Manual deploy (fallback, if GitHub Actions is ever unavailable)

```bash
ssh -p 65002 u358921359@145.79.14.102
cd domains/doyengroupofcompanies.com/public_html/ims
git pull origin main
composer2 install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan view:clear
```

### Local development (recommended before pushing)

Don't test changes only on the live site. Set up a local Laravel environment (Laragon or XAMPP on Windows, with PHP 8.2+, Composer, and local MySQL or SQLite), clone the repo, `composer install`, configure a local `.env` pointing at the local database, and run `php artisan serve` for a local dev server. Test there first, then commit and push once confirmed working — the live deploy should be for already-validated changes, not exploratory testing.

---

## Open / Future Work

- Fix the `cache` driver issue properly (see known issue #4) rather than relying on the `|| true` workaround.
- Build out RBAC for multiple admin accounts with different permission levels.
- Audit the rest of the codebase for any other missing scaffolding files, given the pattern of gaps found during initial deployment.
- Consider rotating the GitHub Actions deploy SSH key, since it passed through a screenshot during setup (not a confirmed compromise, just good hygiene).
- Test the full data flow: Excel import (via the in-app "Import Excel" button) and Excel export ("Download Excel" button) end-to-end with real data, not just the sample file.
- Decide on a path for migrating historical data from the original Python app's SQLite database, if that hasn't been done yet (`php artisan import:sqlite-data` custom command exists for this).
