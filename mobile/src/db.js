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
          is_default INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS sales (
          client_uuid TEXT PRIMARY KEY,
          device_ref TEXT NOT NULL,
          contact_id INTEGER NOT NULL,
          total TEXT NOT NULL,
          server_final_total TEXT,
          totals_differ INTEGER NOT NULL DEFAULT 0,
          transaction_date TEXT NOT NULL,
          server_id INTEGER,
          invoice_no TEXT,
          sync_state TEXT NOT NULL,
          error TEXT
        );
        CREATE TABLE IF NOT EXISTS sale_lines (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          client_uuid TEXT NOT NULL,
          product_id INTEGER NOT NULL,
          variation_id INTEGER NOT NULL,
          quantity TEXT NOT NULL,
          unit_price TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS outbox (
          client_uuid TEXT PRIMARY KEY,
          type TEXT NOT NULL,
          payload TEXT NOT NULL,
          state TEXT NOT NULL,
          error TEXT
        );
      `);
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

export async function metaSet(key, value) {
  const db = await database();
  await db.runAsync(
    'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
    key,
    value
  );
}
