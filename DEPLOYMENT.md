# Hostinger Shared Hosting Deployment Guide

This guide describes how to deploy the rewritten **Admin Inventory Management System (IMS)** Laravel application on a **Hostinger Business Shared Hosting Plan** (or similar cPanel/hPanel plans).

---

## Prerequisites
- SSH access enabled on your Hostinger account.
- MySQL database created in Hostinger's hPanel.
- Subdomain (e.g., `ims.doyengroupofcompanies.com`) created in hPanel.

---

## Deployment Steps

### 1. Enable SSH Access
1. Log in to Hostinger hPanel.
2. Go to **Advanced → SSH Access**.
3. Turn on SSH access and set an SSH password if you haven't already. Keep the SSH port and hostname info handy.

### 2. Create a MySQL Database
1. Go to **Databases → MySQL Databases** in hPanel.
2. Create a new database and MySQL user. Set a strong password.
3. Write down:
   - Database Name
   - Database Username
   - Database Password

### 3. SSH into Hostinger and Clone the Repo
1. Open your terminal/PuTTY and connect using the SSH credentials provided in hPanel:
   ```bash
   ssh -p [PORT] [USERNAME]@[HOST]
   ```
2. Navigate to your subdomain's document directory. The typical path is:
   ```bash
   cd domains/doyengroupofcompanies.com/ims
   ```
3. Clone your Git repository directly into this folder:
   ```bash
   git clone https://github.com/yourusername/ims-laravel.git .
   ```

### 4. Install Dependencies
Make sure you use **Composer 2** for Laravel 11. Run:
```bash
composer2 install --no-dev --optimize-autoloader
```
*(If `composer2` is not recognized, try standard `composer install --no-dev --optimize-autoloader`.)*

### 5. Configure `.env` File
1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```
2. Edit `.env` (using SSH `nano .env` or Hostinger's File Manager) and configure your database credentials:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://ims.doyengroupofcompanies.com

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=your_hostinger_db_name
   DB_USERNAME=your_hostinger_db_user
   DB_PASSWORD=your_hostinger_db_password
   ```
3. Generate the application key:
   ```bash
   php artisan key:generate
   ```

### 6. Set Subdomain Document Root
Laravel serves the application from the `public/` directory.
1. In hPanel, go to **Domains → Subdomains**.
2. Locate your subdomain and change its **document root** to point to the `public` folder inside the project, e.g., `/public_html/ims/public` or `/domains/doyengroupofcompanies.com/ims/public`.
3. If Hostinger doesn't allow editing the document root, add a `.htaccess` file in the subdomain's root folder to rewrite requests into the `/public` directory.

### 7. Run Migrations & Seed Default Admin
Run Laravel's migration tool to build MySQL tables and seed the initial administrator account:
```bash
php artisan migrate --force --seed
```

### 8. Import Historical SQLite Data
If you have historical data from the Python/Flask application:
1. Upload your historical SQLite database file `inventory.db` to the `instance/` directory on Hostinger (create the `instance` directory at the root if it doesn't exist).
2. Run the custom import command:
   ```bash
   php artisan import:sqlite-data
   ```
3. Follow the CLI prompt to verify and execute the transfer.

### 9. Fix Directory Permissions
Set read/write permissions for Laravel storage and cache directories:
```bash
chmod -R 775 storage bootstrap/cache
```

### 10. Handle Storage Link (If uploads are used)
Since Hostinger shared hosting disables the `symlink()` PHP function, you can run a symbolic link manually via SSH if needed:
```bash
ln -s /home/your_ssh_user/domains/doyengroupofcompanies.com/ims/storage/app/public \
      /home/your_ssh_user/domains/doyengroupofcompanies.com/ims/public/storage
```

---

## Testing & Verifying
1. Visit `https://ims.doyengroupofcompanies.com` and log in with `admin` / `admin`.
2. Check that the dashboard loads all migrated orders.
3. Test exporting inventory using **Download Excel**.
4. Test updating inventory using the **Import Excel** upload page.
