# Hostinger Database Updates

This directory contains the safe, non-destructive schema update scripts for the live **GSO Integrated Services Operations and E-Ticketing System** database on Hostinger.

---

## Files in this Directory
- **`gso_db_dump.sql`**: Production schema updater script compatible with MySQL 8.0+ and MariaDB 10.4+.

---

## Zero-Data-Loss Safety Guarantees
1. **Never Drops Existing Tables**: All `DROP TABLE IF EXISTS` statements have been removed and replaced with `CREATE TABLE IF NOT EXISTS`.
2. **Automatic Column Patcher (`sp_gso_upgrade_schema`)**: A self-executing MySQL stored procedure inspects `information_schema.columns` and automatically applies missing columns (such as ticket recategorization fields, user security lockouts, email preferences, and delay reasons) without throwing duplicate column errors.
3. **Protected Seed Inserts**: Core seed rows use `INSERT IGNORE` and `ON DUPLICATE KEY UPDATE` to preserve all live user accounts, passwords, test tickets, and saved settings.

---

## How to Import on Hostinger phpMyAdmin

1. **Log in to Hostinger hPanel**:
   - Go to [Hostinger hPanel](https://hpanel.hostinger.com/).
   - Navigate to **Databases** → **phpMyAdmin**.
   - Click **Enter phpMyAdmin** next to your database (e.g., `u123456789_bsu_gso_db`).

2. **Select Your Database (Critical)**:
   - In the **left sidebar** of phpMyAdmin, click directly on your database name to enter it.
   - *(If not selected first, phpMyAdmin will show `#1046 - No database selected`)*.

3. **(Optional) Take a Safety Snapshot**:
   - Click the **Export** tab → Select **Quick** → Click **Export**.

4. **Import the Updater**:
   - Click the **Import** tab on the top menu.
   - Click **Choose File** (or "Browse") and select `gso_db_dump.sql`.
   - Character Set: `utf-8` / `utf8mb4`.
   - Format: `SQL`.
   - Click **Import** (or **Go**) at the bottom.

5. **Confirmation**:
   - A green notification banner will confirm: `"Import has been successfully finished, XXX queries executed."`
   - Your existing alpha test data will be fully preserved, and all new tables/columns will be available immediately.
