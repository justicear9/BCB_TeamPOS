import * as SQLite from 'expo-sqlite';

let dbPromise;

export function database() {
  if (!dbPromise) {
    dbPromise = SQLite.openDatabaseAsync('cashier.db').then(async (db) => {
      await db.execAsync(`
        CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS products (
          variation_id INTEGER PRIMARY KEY,
          product_id INTEGER NOT NULL,
          name TEXT NOT NULL,
          variation_name TEXT,
          sku TEXT,
          sell_price TEXT NOT NULL,
          qty_available TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS customers (
          id INTEGER PRIMARY KEY,
          name TEXT NOT NULL,
          mobile TEXT,
          is_default INTEGER NOT NULL DEFAULT 0,
          credit_limit TEXT,
          amount_due TEXT NOT NULL DEFAULT '0',
          pay_term_number TEXT,
          pay_term_type TEXT
        );
        CREATE TABLE IF NOT EXISTS sales (
          client_uuid TEXT PRIMARY KEY,
          device_ref TEXT NOT NULL,
          contact_id INTEGER NOT NULL,
          total TEXT NOT NULL,
          payment_method TEXT,
          server_final_total TEXT,
          totals_differ INTEGER NOT NULL DEFAULT 0,
          transaction_date TEXT NOT NULL,
          server_id INTEGER,
          invoice_no TEXT,
          sync_state TEXT NOT NULL,
          error TEXT,
          total_paid TEXT
        );
        CREATE TABLE IF NOT EXISTS server_sales (
          id INTEGER PRIMARY KEY,
          invoice_no TEXT,
          contact_id INTEGER,
          final_total TEXT NOT NULL,
          payment_status TEXT,
          transaction_date TEXT NOT NULL,
          total_paid TEXT
        );
        CREATE TABLE IF NOT EXISTS sale_lines (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          client_uuid TEXT NOT NULL,
          product_id INTEGER NOT NULL,
          variation_id INTEGER NOT NULL,
          quantity TEXT NOT NULL,
          unit_price TEXT NOT NULL,
          name TEXT
        );
        CREATE TABLE IF NOT EXISTS sale_payments (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          client_uuid TEXT NOT NULL,
          method TEXT NOT NULL,
          amount TEXT NOT NULL,
          tendered TEXT
        );
        CREATE TABLE IF NOT EXISTS server_sale_lines (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          sale_id INTEGER NOT NULL,
          name TEXT,
          variation_name TEXT,
          quantity TEXT NOT NULL,
          unit_price TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS server_sale_payments (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          sale_id INTEGER NOT NULL,
          method TEXT NOT NULL,
          amount TEXT NOT NULL,
          is_return INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS outbox (
          client_uuid TEXT PRIMARY KEY,
          type TEXT NOT NULL,
          payload TEXT NOT NULL,
          state TEXT NOT NULL,
          error TEXT
        );
      `);
      const alters = [
        'ALTER TABLE sales ADD COLUMN payment_method TEXT',
        'ALTER TABLE sales ADD COLUMN total_paid TEXT',
        'ALTER TABLE customers ADD COLUMN credit_limit TEXT',
        "ALTER TABLE customers ADD COLUMN amount_due TEXT NOT NULL DEFAULT '0'",
        'ALTER TABLE customers ADD COLUMN pay_term_number TEXT',
        'ALTER TABLE customers ADD COLUMN pay_term_type TEXT',
        'ALTER TABLE server_sales ADD COLUMN total_paid TEXT',
        'ALTER TABLE sale_lines ADD COLUMN name TEXT',
        'ALTER TABLE customers ADD COLUMN business_name TEXT',
        'ALTER TABLE sales ADD COLUMN latitude TEXT',
        'ALTER TABLE sales ADD COLUMN longitude TEXT',
        'ALTER TABLE sales ADD COLUMN accuracy TEXT',
        "ALTER TABLE customers ADD COLUMN advance TEXT NOT NULL DEFAULT '0'",
        'ALTER TABLE sales ADD COLUMN location_id INTEGER',
        'ALTER TABLE server_sales ADD COLUMN location_id INTEGER',
        'ALTER TABLE products ADD COLUMN enable_stock INTEGER NOT NULL DEFAULT 1',
        "ALTER TABLE customers ADD COLUMN amount_due_here TEXT NOT NULL DEFAULT '0'",
        'ALTER TABLE customers ADD COLUMN reward_points INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE customers ADD COLUMN email TEXT',
        'ALTER TABLE customers ADD COLUMN address TEXT',
        "ALTER TABLE server_sales ADD COLUMN total_returned TEXT NOT NULL DEFAULT '0'",
        'ALTER TABLE sales ADD COLUMN cashier TEXT',
        "ALTER TABLE sales ADD COLUMN discount TEXT NOT NULL DEFAULT '0'",
        "ALTER TABLE sales ADD COLUMN total_returned TEXT NOT NULL DEFAULT '0'",
        'ALTER TABLE sale_lines ADD COLUMN price_override INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE server_sale_lines ADD COLUMN sell_line_id INTEGER',
        "ALTER TABLE server_sale_lines ADD COLUMN quantity_returned TEXT NOT NULL DEFAULT '0'",
        'ALTER TABLE server_sale_lines ADD COLUMN variation_id INTEGER',
        'ALTER TABLE outbox ADD COLUMN cashier TEXT',
        'ALTER TABLE outbox ADD COLUMN created_at TEXT',
        'ALTER TABLE products ADD COLUMN prices TEXT',
      ];
      for (const statement of alters) {
        try {
          await db.execAsync(statement);
        } catch (error) {
          if (!/duplicate column/i.test(String(error))) {
            throw error;
          }
        }
      }
      await db.runAsync('UPDATE sales SET total_paid = total WHERE total_paid IS NULL');
      return db;
    });
  }
  return dbPromise;
}

export async function metaGet(key) {
  const db = await database();
  const row = await db.getFirstAsync('SELECT value FROM meta WHERE key = ?', key);
  return row ? row.value : null;
}

export async function metaDelete(key) {
  const db = await database();
  await db.runAsync('DELETE FROM meta WHERE key = ?', key);
}

export async function metaSet(key, value) {
  const db = await database();
  await db.runAsync(
    'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
    key,
    value
  );
}
