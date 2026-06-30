# IMS: Python → PHP/Laravel Rewrite + Hostinger Deployment Guide

This guide has two parts:
1. A prompt to hand to an AI coding tool (with access to your repo) to do the rewrite.
2. Step-by-step instructions to deploy the result on your Hostinger Business shared hosting plan.

---

## Part 1: The Rewrite Prompt

Use this with a coding-capable AI that can read your actual repo files (Claude Code, Cursor, Windsurf, or by uploading/pasting your repo into a chat). Don't just paste this into a plain chat without the code attached — it needs to see your actual app to do this well.

```
I need you to rewrite an existing inventory management system (IMS) from Python 
to PHP using the Laravel framework, and migrate its database from SQLite to MySQL.

CONTEXT
- This will be deployed on Hostinger shared hosting, which supports PHP/Laravel/MySQL 
  natively but does not support persistent Python processes (no Flask/Django hosting).
- The current app is in this repo: [attach/paste the repo, or give the path]
- Current stack: Python ([Flask/Django/FastAPI — specify which]), SQLite database

STEP 1 — ANALYZE FIRST, DON'T CODE YET
Before writing anything, inspect the existing codebase and give me a summary of:
- Every route/page/endpoint and what it does
- All database tables/models and their fields and relationships
- Any business logic worth flagging (validation rules, calculations, stock logic, 
  permissions/roles, etc.)
- The Excel functionality specifically: what gets exported (which data, what format) 
  and what gets imported/seeded (expected file structure, what fields map where)
- Anything else the app does (auth, file uploads, scheduled tasks, etc.)

Show me this summary before proceeding so I can confirm nothing is missed.

STEP 2 — REWRITE TO LARAVEL
Once confirmed, rewrite the application as a Laravel project with:
- Laravel migrations defining the MySQL schema (equivalent to the current SQLite schema, 
  using proper MySQL types — INT/BIGINT for IDs, DECIMAL for money/quantities if relevant, 
  DATETIME for timestamps, VARCHAR with sensible lengths)
- Eloquent models for each entity, with relationships defined (hasMany/belongsTo/etc.)
- Controllers + routes replicating every feature from the original app, 1:1 in behavior
- Simple, clean Blade views using Bootstrap (via CDN, not a build step — I want to avoid 
  needing Node/Vite on the server) for the UI
- Form validation matching whatever validation existed in the original app
- Authentication if the original app had login/users (use Laravel's built-in auth scaffolding 
  unless there's a reason not to)

EXCEL REQUIREMENTS (important)
- Use the `maatwebsite/excel` package (built on PhpSpreadsheet) for both:
  - Exporting/downloading current data to .xlsx
  - Importing/"seeding" data from an uploaded .xlsx file into the database
- Match the existing export format (same columns/sheets/order) and the existing 
  import behavior (same expected columns, same validation/error handling on bad rows)

DATA MIGRATION
- Write a one-time Artisan command (e.g. `php artisan import:sqlite-data`) that reads 
  the existing SQLite .db file and inserts all its data into the new MySQL tables via 
  Eloquent, preserving relationships/foreign keys correctly. I will run this once after 
  deployment to bring over existing data.

CONSTRAINTS FOR HOSTINGER SHARED HOSTING
- No background workers/queues that need to run continuously — keep everything 
  request/response or cron-based (Laravel's scheduler run via cPanel cron job is fine)
- No reliance on `symlink()` for storage — Hostinger shared hosting often disables it; 
  use `Storage::disk('public')` paths the app controls directly, or flag if 
  `php artisan storage:link` is unavoidable so I know to do it manually
- Keep dependencies to what's available via Composer; avoid anything requiring 
  system-level packages I can't install on shared hosting

DELIVERABLE
- Full Laravel project structure
- A README noting: required PHP version, the migrate/seed commands to run, the 
  data-import command, and any manual steps needed after upload (e.g. storage linking)

Ask me clarifying questions about any ambiguous business logic before assuming behavior.
```

A few notes on this prompt: it deliberately tells the AI to analyze first and show you a summary before rewriting — this catches the AI misunderstanding a feature before it builds the wrong thing. It also steers away from Vite/Node build steps, since that's an extra complication on shared hosting you don't need. If your current app has anything unusual (barcode scanning, specific report formats, multi-user roles, etc.), add a line calling that out explicitly — the more specific you are about edge cases, the less the AI will guess.

---

## Part 2: Deploying to Hostinger Shared Hosting

Your Business plan supports this (it includes SSH access, Composer, Git, and MySQL — all you need for Laravel). Here's the sequence.

### 1. Create a subdomain for the IMS

Since `doyengroupofcompanies.com` is already your live site on this plan, don't deploy the IMS into the same `public_html`. Instead, in hPanel go to **Domains → Subdomains** and create something like `ims.doyengroupofcompanies.com`. This gives you a separate folder to work in and avoids touching your live site.

### 2. Create the MySQL database

In hPanel, go to **Databases → MySQL Databases**. Create a new database and a new user, assign the user to the database with full privileges, and write down the database name, username, and password — Hostinger won't show you the password again after creation.

### 3. Enable SSH access

In hPanel, go to **Advanced → SSH Access**, turn it on, and set a password if asked. You'll get a hostname/port to connect with (via PuTTY on Windows, or Terminal on Mac, or any SSH client).

### 4. Push your Laravel project to GitHub (or GitLab)

If it isn't already, get the finished Laravel project into a Git repo — this is the cleanest way to get it onto the server and to push future updates.

### 5. SSH in and clone the project

Connect via SSH, then navigate to the subdomain's folder (something like `~/domains/doyengroupofcompanies.com/ims/` — the exact path is shown in hPanel under the subdomain's details), and clone your repo there:

```bash
cd domains/doyengroupofcompanies.com/ims
git clone https://github.com/yourusername/your-ims-repo.git .
```

### 6. Install dependencies

Hostinger sometimes has both Composer 1 and 2 installed — make sure you use Composer 2:

```bash
composer2 install --no-dev --optimize-autoloader
```

(If `composer2` isn't recognized, try plain `composer install --no-dev --optimize-autoloader` first.)

### 7. Set up the `.env` file

Copy `.env.example` to `.env`, then edit it (via SSH with `nano .env`, or via hPanel's File Manager) with your database credentials from step 2:

```
APP_URL=https://ims.doyengroupofcompanies.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

Then generate the app key:

```bash
php artisan key:generate
```

### 8. Fix the public folder issue

Hostinger expects your subdomain's web root to serve directly from its folder, but Laravel expects the web root to be its `/public` subfolder specifically. The cleanest fix: in hPanel, when you set up the subdomain, you can usually point its **document root** directly to the project's `public` folder (look for this option under the subdomain's settings in hPanel). If that option isn't available, the fallback is moving the contents of `/public` up into the subdomain's root folder and adjusting the two path references inside `index.php` to point back into the Laravel app folder — this works but is fiddlier, so try the document-root option first.

### 9. Run migrations

```bash
php artisan migrate --force
```

### 10. Import your existing data

Run the data-migration Artisan command the AI built for you in Part 1:

```bash
php artisan import:sqlite-data
```

(Upload your existing `.sqlite`/`.db` file to the server first via File Manager or SCP, and point the command at its path if needed.)

### 11. Handle storage links

Laravel normally runs `php artisan storage:link`, but Hostinger shared hosting often disables PHP's `symlink()` function for security. If you get an error here, create the link manually via SSH instead:

```bash
ln -s /home/yourusername/domains/doyengroupofcompanies.com/ims/storage/app/public \
      /home/yourusername/domains/doyengroupofcompanies.com/ims/public/storage
```

(Only needed if your app stores/serves uploaded files — skip if not.)

### 12. Set folder permissions

```bash
chmod -R 775 storage bootstrap/cache
```

### 13. Test it

Visit `https://ims.doyengroupofcompanies.com` and click through the core flows: login (if applicable), viewing inventory, exporting to Excel, and importing/seeding from an Excel file. Check `storage/logs/laravel.log` via SSH or File Manager if anything errors out.

---

### A couple of things to watch for

If you ever update the code, you don't need to redo all of this — just SSH in, `git pull`, re-run `composer2 install --no-dev --optimize-autoloader` if dependencies changed, and `php artisan migrate --force` if there are new migrations.

Also, free up about an hour the first time you do this. Steps 8 and 11 (the public folder and symlink issues) are the two spots most people get stuck on with Laravel-on-shared-hosting — if you hit an error there, it's a known, fixable thing, not a sign something's fundamentally wrong with the setup.
