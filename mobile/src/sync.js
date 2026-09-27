import { authFetch } from './api';
import { database, metaGet, metaSet } from './db';

export async function deviceId() {
  let id = await metaGet('device_id');
  if (!id) {
    id = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
    await metaSet('device_id', id);
  }
  return id;
}

export async function nextDeviceRef() {
  const id = await deviceId();
  const seq = Number((await metaGet('sale_seq')) || '0') + 1;
  await metaSet('sale_seq', String(seq));
  return `C-${id.slice(0, 6)}-${String(seq).padStart(6, '0')}`;
}

export async function fetchLocations() {
  const json = await authFetch('/cashier/api/sync/locations');
  return json.data;
}

export async function pullLocation(locationId) {
  const since = await metaGet(`since_${locationId}`);
  const query = since
    ? `?location_id=${locationId}&since=${encodeURIComponent(since)}`
    : `?location_id=${locationId}`;
  const json = await authFetch(`/cashier/api/sync/changes${query}`);
  const db = await database();

  await db.withTransactionAsync(async () => {
    if (!since) {
      await db.runAsync('DELETE FROM products');
      await db.runAsync('DELETE FROM customers');
    }
    for (const product of json.products) {
      await db.runAsync(
        `INSERT INTO products (variation_id, product_id, name, variation_name, sku, sell_price, qty_available)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(variation_id) DO UPDATE SET
           name = excluded.name,
           variation_name = excluded.variation_name,
           sku = excluded.sku,
           sell_price = excluded.sell_price,
           qty_available = excluded.qty_available`,
        product.variation_id,
        product.product_id,
        product.name,
        product.variation_name,
        product.sku,
        product.sell_price,
        product.qty_available
      );
    }
    for (const customer of json.customers) {
      await db.runAsync(
        `INSERT INTO customers (id, name, mobile, is_default) VALUES (?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET name = excluded.name, mobile = excluded.mobile, is_default = excluded.is_default`,
        customer.id,
        customer.name,
        customer.mobile,
        customer.is_default
      );
    }
    const pending = await db.getAllAsync(
      `SELECT sale_lines.variation_id, sale_lines.quantity
       FROM sale_lines
       JOIN sales ON sales.client_uuid = sale_lines.client_uuid
       WHERE sales.sync_state = 'pending'`
    );
    for (const line of pending) {
      await db.runAsync(
        'UPDATE products SET qty_available = qty_available - ? WHERE variation_id = ?',
        line.quantity,
        line.variation_id
      );
    }
  });

  await metaSet(`since_${locationId}`, json.server_time);
  await metaSet('location_id', String(locationId));
  await metaSet('location_name', json.location.name);
  return json;
}

export async function pushOutbox() {
  const db = await database();
  const rows = await db.getAllAsync(
    "SELECT * FROM outbox WHERE state = 'pending' AND type = 'sale.create' ORDER BY rowid"
  );
  for (const row of rows) {
    try {
      const result = await authFetch('/cashier/api/sync/operations', {
        method: 'POST',
        body: JSON.stringify(JSON.parse(row.payload)),
      });
      const local = await db.getFirstAsync(
        'SELECT total FROM sales WHERE client_uuid = ?',
        row.client_uuid
      );
      const differ = Number(local.total) !== Number(result.final_total) ? 1 : 0;
      await db.runAsync(
        `UPDATE sales
         SET server_id = ?, invoice_no = ?, server_final_total = ?, totals_differ = ?, sync_state = 'synced', error = NULL
         WHERE client_uuid = ?`,
        result.entity_id,
        result.invoice_no,
        String(result.final_total),
        differ,
        row.client_uuid
      );
      await db.runAsync(
        "UPDATE outbox SET state = 'sent', error = NULL WHERE client_uuid = ?",
        row.client_uuid
      );
    } catch (error) {
      await db.runAsync(
        "UPDATE outbox SET error = ? WHERE client_uuid = ?",
        error.message,
        row.client_uuid
      );
      await db.runAsync(
        "UPDATE sales SET error = ? WHERE client_uuid = ?",
        error.message,
        row.client_uuid
      );
    }
  }
}
