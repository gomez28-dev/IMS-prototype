# Admin Inventory Management System (IMS)

A modern, responsive, and secure prototype Admin Inventory Management System built with Python, Flask, Flask-SQLAlchemy (SQLite), Flask-Login, and Flask-WTF. The frontend is styled using Bootstrap 5 with responsive components, dynamic balance badges, and administrative safety confirmations.

## Table of Contents
- [Features](#features)
- [Database Schema & Models](#database-schema--models)
- [Prerequisites](#prerequisites)
- [Installation & Local Setup](#installation--local-setup)
- [Data Seeding from Excel](#data-seeding-from-excel)
- [Running the Application](#running-the-application)
- [Default Credentials](#default-credentials)
- [Code Quality & Architecture](#code-quality--architecture)

---

## Features

1. **Secure Admin Authentication**: Protected routes using Flask-Login and secure password hashing via `werkzeug.security`.
2. **Dynamic Dashboard Overview**: View all orders, ordered quantities, delivered quantities, and remaining balances at a glance.
   - Balance badges are styled dynamically (Green for `0` remaining, Yellow for `>0` remaining).
3. **Advanced Order Search**: Locate records instantly using the case-insensitive search bar (filters by account name or SO#).
4. **Order & Delivery Management**:
   - Create, edit, view, and delete sales orders.
   - Track multiple deliveries linked to each order with status choices (`PENDING`, `FULFILLED`, `CANCELLED`).
   - Cancelled deliveries are visually greyed out/crossed out to preserve order clarity.
5. **Defensive Over-Shipping Validation**:
   - Automatically prevents users from adding or editing delivery quantities that exceed the order's remaining balance.
   - Intelligently adjusts balance when editing active deliveries so that a delivery doesn't validate against its own pre-edit quantity.
6. **Administrative Delete/Void Safety**:
   - Admins can delete orders (which cascade-deletes all associated deliveries) or individual deliveries.
   - All delete actions are protected by a JavaScript confirmation dialog to prevent accidental deletion.
7. **Excel Data Export**:
   - Export live tracking data into an Excel spreadsheet in memory using a flattened, grouped-row layout matching the legacy sheet format.
   - Includes automatic status mappings (`FULFILLED` -> `DONE`) and running `DELIVERY BALANCE` math.

---

## Database Schema & Models

The SQLite database uses three primary tables configured with SQLAlchemy:

- **Admin**:
  - `id` (Integer, Primary Key)
  - `username` (String, Unique, Nullable=False)
  - `password_hash` (String, Nullable=False)
  - Implements password set/check methods.
- **Order**:
  - `id` (Integer, Primary Key)
  - `account` (String, Nullable=False)
  - `date` (DateTime, Nullable=False)
  - `qty_ordered` (Integer, Nullable=False)
  - `so_number` (String, Nullable=False)
  - *Dynamic Properties*:
    - `total_qty_out`: Calculates the sum of `qty_out` for all associated deliveries except those with `CANCELLED` status.
    - `remaining_balance`: Calculates `qty_ordered - total_qty_out`.
- **Delivery**:
  - `id` (Integer, Primary Key)
  - `order_id` (Integer, Foreign Key to `orders.id`, cascade delete enabled)
  - `dr_number` (String, Nullable=False)
  - `delivery_date` (DateTime, Nullable=False)
  - `qty_out` (Integer, Nullable=False)
  - `status` (String, default='PENDING', choices: `PENDING`, `FULFILLED`, `CANCELLED`)
  - `remarks` (Text, Nullable=True)

---

## Prerequisites

- Python 3.8 or higher installed on your machine.
- Local command line access (PowerShell, CMD, or Terminal).

---

## Installation & Local Setup

1. **Clone the Repository**:
   ```bash
   git clone https://github.com/gomez28-dev/IMS-prototype.git
   cd IMS-prototype
   ```

2. **Create a Virtual Environment**:
   - **Windows**:
     ```powershell
     python -m venv venv
     .\venv\Scripts\activate
     ```
   - **macOS/Linux**:
     ```bash
     python3 -m venv venv
     source venv/bin/activate
     ```

3. **Install Dependencies**:
   ```bash
   pip install Flask Flask-SQLAlchemy Flask-Login Flask-WTF pandas openpyxl
   ```

---

## Data Seeding from Excel

The application includes an ingestion script `seed.py` that reads legacy inventory data from `DPET SALES INVENTORY.xlsx`, initializes database tables, and registers the default administrator.

To seed the database, run:
```bash
python seed.py
```

*Note: The script parses rows, automatically grouping deliveries under their respective sales orders using the `ACCOUNT` indicator, mapping status choices (e.g. `DONE` -> `FULFILLED`), and cleaning numeric formatting (like trailing `.0` on numbers).*

---

## Running the Application

After installation and database seeding, start the development server using:
```bash
python run.py
```

Open your web browser and navigate to:
```
http://127.0.0.1:5000
```

---

## Default Credentials

Use the following default credentials to sign in to the administrator portal:
- **Username**: `admin`
- **Password**: `admin`

---

## Code Quality & Architecture

This application adheres to modern, clean development paradigms:
- **Application Factory Pattern**: Setup in `app/__init__.py` using Flask's `create_app()` factory method to isolate configurations.
- **SQLAlchemy 2.0 Syntax**: Replaced legacy query patterns with modern `db.session.get()`, `db.session.scalars()`, and `db.select()` queries.
- **CSRF Protection**: Form inputs use Flask-WTF forms integrated with security tokens.
