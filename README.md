# Admin Inventory Management System (IMS) - PHP/Laravel Edition

A modern, responsive, and secure Admin Inventory Management System rewritten from Python/Flask to PHP using the Laravel framework, optimized for deployment on Hostinger web hosting.

The frontend is styled with a modernized Navy & White/Slate layout using Bootstrap 5, featuring clean rounded cards, responsive spacing, dynamic status/balance badges, and interactive metrics widgets.

## Table of Contents
- [Features](#features)
- [Database Schema & Models](#database-schema--models)
- [Prerequisites](#prerequisites)
- [Installation & Local Setup](#installation--local-setup)
- [Database Migrations & Seeding](#database-migrations--seeding)
- [Excel Integration (Import & Export)](#excel-integration-import--export)
- [SQLite Historical Data Migration](#sqlite-historical-data-migration)
- [Deployment on Hostinger Shared Hosting](#deployment-on-hostinger-shared-hosting)

---

## Features

1. **Secure Admin Authentication**: Built-in session-based authentication with a seeded default admin account.
2. **Sleek Navy & Slate Theme**: Premium look-and-feel using clean typography (Inter), card-based statistics, shadow effects, and subtle hover animations.
3. **Dashboard Metrics**:
   - Total Orders
   - Total Qty Ordered
   - Total Qty Delivered
   - Total Remaining Balance (dynamic color coding: green for fully delivered, yellow for pending balance)
4. **Interactive Order Search**: Instant searching by Account Name or Sales Order Number (`SO#`).
5. **Order & Delivery Management (CRUD)**:
   - Create, read, update, and delete customer orders.
   - Record multiple deliveries against each order with status tracking (`PENDING`, `FULFILLED`, `CANCELLED`).
   - Visually crossed-out rows for cancelled deliveries to maintain clarity.
6. **Defensive Over-Shipping Validation**:
   - Built-in validation rule preventing deliveries from exceeding the remaining order balance.
   - Automatically handles active delivery edits by factoring in the pre-edit quantity when calculating the validation limit.
7. **Excel Export**:
   - Downloads live database state into a formatted spreadsheet in memory.
   - Groups deliveries under their respective orders in the exact structure as the legacy spreadsheet, converting status flags (e.g., `FULFILLED` -> `DONE`).
8. **Excel Web Import (Merge & Update)**:
   - Upload spreadsheets from the dashboard web UI.
   - Automatically merges and updates: updates order quantities and account names matching an existing `SO#` (or creates new orders), and updates/appends deliveries matching `DR#` under that order.

---

## Database Schema & Models

- **Admin** (`admins`):
  - `id` (Auto-increment, Primary Key)
  - `username` (VARCHAR 64, Unique)
  - `password` (VARCHAR 256, Hashed)
  - `remember_token` (VARCHAR 100)
- **Order** (`orders`):
  - `id` (Auto-increment, Primary Key)
  - `account` (VARCHAR 128)
  - `date` (DateTime)
  - `qty_ordered` (Integer)
  - `so_number` (VARCHAR 64)
  - *Eloquent Attributes*:
    - `total_qty_out`: Sum of non-cancelled deliveries' `qty_out`.
    - `remaining_balance`: `qty_ordered - total_qty_out`.
- **Delivery** (`deliveries`):
  - `id` (Auto-increment, Primary Key)
  - `order_id` (Foreign Key referencing `orders.id`, cascade delete enabled)
  - `dr_number` (VARCHAR 64)
  - `delivery_date` (DateTime)
  - `qty_out` (Integer)
  - `status` (VARCHAR 20: `PENDING`, `FULFILLED`, `CANCELLED`)
  - `remarks` (Text, Nullable)

---

## Prerequisites

- **PHP 8.2 or higher**
- **Composer 2**
- **MySQL or MariaDB** (Production) / **SQLite** (Local development option)

---

## Installation & Local Setup

If you have PHP and Composer installed locally and want to run it:

1. **Clone the Repository & Install Dependencies**:
   ```bash
   composer install
   ```

2. **Configure Environment File**:
   Copy `.env.example` to `.env` and fill in your database credentials:
   ```bash
   cp .env.example .env
   ```

3. **Generate Application Key**:
   ```bash
   php artisan key:generate
   ```

4. **Run Migrations & Seed Default Admin**:
   ```bash
   php artisan migrate --seed
   ```
   *Default Admin Login:*
   - **Username**: `admin`
   - **Password**: `admin`

5. **Start Local Development Server**:
   ```bash
   php artisan serve
   ```
   Open `http://127.0.0.1:8000` in your web browser.

---

## Excel Integration (Import & Export)

The system uses `maatwebsite/excel` (backed by `phpoffice/phpspreadsheet`) to handle spreadsheet files:
- **Exporting**: Accessible via the **Download Excel** button on the dashboard.
- **Importing**: Accessible via the **Import Excel** button. You can upload any `.xlsx` or `.xls` file. The column headers must be on the 2nd row, listing: `ACCOUNT`, `DATE`, `QTY ORDERED`, `SO#`, `DR#`, `DELIVERY DATE`, `QTY OUT`, `DELIVERY STATUS`, `REMARKS`.

---

## SQLite Historical Data Migration

To import your existing Python/Flask SQLite database records into the new MySQL database:

1. Ensure the SQLite database file (`inventory.db`) is placed at `instance/inventory.db`.
2. Run the custom Artisan migration command:
   ```bash
   php artisan import:sqlite-data
   ```
3. The command will read the SQLite tables, truncate the current MySQL tables, and insert all admins, orders, and deliveries, preserving records' IDs and foreign key relationships.

---

## Deployment on Hostinger Shared Hosting

See [DEPLOYMENT.md](file:///c:/Users/Doyen/Desktop/InventoryManagementSystem/DEPLOYMENT.md) for full instructions on setting up subdomains, MySQL databases, SSH access, storage symlinking, and running composer/migrations on Hostinger.
