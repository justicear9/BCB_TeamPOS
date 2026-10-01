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
      await db.runAsync('DELETE FROM customers WHERE id > 0');
    }
    for (const product of json.products) {
      await db.runAsync(
        `INSERT INTO products (variation_id, product_id, name, variation_name, sku, sell_price, qty_available, enable_stock, prices)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(variation_id) DO UPDATE SET
           name = excluded.name,
           variation_name = excluded.variation_name,
           sku = excluded.sku,
           sell_price = excluded.sell_price,
           qty_available = excluded.qty_available,
           enable_stock = excluded.enable_stock,
           prices = excluded.prices`,
        product.variation_id,
        product.product_id,
        product.name,
        product.variation_name,
        product.sku,
        product.sell_price,
        product.qty_available,
        product.enable_stock === 0 ? 0 : 1,
        product.prices ? JSON.stringify(product.prices) : null
      );
    }
    const listed = json.products.map((product) => Number(product.variation_id));
    if (listed.length) {
      await db.runAsync(
        `DELETE FROM products WHERE variation_id NOT IN (${listed.map(() => '?').join(',')})`,
        ...listed
      );
    }
    if (!since) {
      await db.runAsync('DELETE FROM server_sales');
    }
    for (const sale of json.sales || []) {
      await db.runAsync(
        `INSERT INTO server_sales (id, location_id, invoice_no, contact_id, final_total, payment_status, transaction_date, total_paid, total_returned)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
           total_returned = excluded.total_returned,
           location_id = excluded.location_id,
           invoice_no = excluded.invoice_no,
           contact_id = excluded.contact_id,
           final_total = excluded.final_total,
           payment_status = excluded.payment_status,
           transaction_date = excluded.transaction_date,
           total_paid = excluded.total_paid`,
        sale.id,
        Number(locationId),
        sale.invoice_no,
        sale.contact_id,
        String(sale.final_total),
        sale.payment_status,
        sale.transaction_date,
        String(sale.total_paid ?? sale.final_total),
        String(sale.total_returned ?? '0')
      );
    }
    for (const customer of json.customers) {
      await db.runAsync(
        `INSERT INTO customers (id, name, business_name, mobile, is_default, credit_limit, amount_due, advance, pay_term_number, pay_term_type, amount_due_here, reward_points, email, address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
           amount_due_here = excluded.amount_due_here,
           reward_points = excluded.reward_points,
           email = excluded.email,
           address = excluded.address,
           name = excluded.name,
           business_name = excluded.business_name,
           mobile = excluded.mobile,
           is_default = excluded.is_default,
           credit_limit = excluded.credit_limit,
           amount_due = excluded.amount_due,
           advance = excluded.advance,
           pay_term_number = excluded.pay_term_number,
           pay_term_type = excluded.pay_term_type`,
        customer.id,
        customer.name,
        customer.business_name || null,
        customer.mobile,
        customer.is_default,
        customer.credit_limit == null ? null : String(customer.credit_limit),
        String(customer.amount_due ?? '0'),
        String(customer.advance ?? '0'),
        customer.pay_term_number == null ? null : String(customer.pay_term_number),
        customer.pay_term_type || null,
        String(customer.amount_due_here ?? '0'),
        Number(customer.reward_points) || 0,
        customer.email || null,
        customer.address || null
      );
    }
    const waitingAdvances = await db.getAllAsync(
      `SELECT payload FROM outbox WHERE state = 'pending' AND type = 'payment.advance'`
    );
    for (const waiting of waitingAdvances) {
      const advance = JSON.parse(waiting.payload);
      await db.runAsync(
        'UPDATE customers SET advance = COALESCE(advance, 0) + ? WHERE id = ?',
        advance.amount,
        advance.contact_id
      );
    }
    const refreshed = new Set(json.products.map((product) => Number(product.variation_id)));
    const pending = await db.getAllAsync(
      `SELECT sale_lines.variation_id, sale_lines.quantity
       FROM sale_lines
       JOIN sales ON sales.client_uuid = sale_lines.client_uuid
       JOIN products ON products.variation_id = sale_lines.variation_id
       WHERE sales.sync_state = 'pending' AND products.enable_stock = 1 AND sales.location_id = ?`,
      locationId
    );
    for (const line of pending) {
      if (!refreshed.has(Number(line.variation_id))) {
        continue;
      }
      await db.runAsync(
        'UPDATE products SET qty_available = qty_available - ? WHERE variation_id = ?',
        line.quantity,
        line.variation_id
      );
    }
    for (const item of await waitingReturnStock(db, locationId)) {
      if (refreshed.has(Number(item.variation_id))) {
        await db.runAsync(
          'UPDATE products SET qty_available = qty_available + ? WHERE variation_id = ? AND enable_stock = 1',
          item.quantity,
          item.variation_id
        );
      }
    }
  });

  await metaSet(`since_${locationId}`, json.server_time);
  await metaSet('location_id', String(locationId));
  await metaSet('location_name', json.location.name);
  if (json.location.business_name) {
    await metaSet('business_name', json.location.business_name);
  }
  await metaSet('payment_methods', JSON.stringify(json.payment_methods || []));
  await metaSet('price_groups', JSON.stringify(json.price_groups || { options: [], default_id: null }));
  if (json.receipt) {
    await metaSet('receipt_layout', JSON.stringify(json.receipt));
  }
  if (json.cashier) {
    await metaSet('cashier_settings', JSON.stringify(json.cashier));
  }
  if (json.register) {
    await metaSet('register', JSON.stringify(json.register));
  }
  await pruneLocal();
  return json;
}

const KEEP_DAYS = 45;

/**
 * Synced sales live on in TeamPOS. The phone only keeps recent ones so the
 * database and every screen stay fast.
 */
export async function pruneLocal() {
  const db = await database();
  const cutoff = new Date(Date.now() - KEEP_DAYS * 86400000);
  const pad = (n) => String(n).padStart(2, '0');
  const stamp = `${cutoff.getFullYear()}-${pad(cutoff.getMonth() + 1)}-${pad(cutoff.getDate())} 00:00:00`;
  await db.withTransactionAsync(async () => {
    const old = `SELECT client_uuid FROM sales WHERE sync_state = 'synced' AND transaction_date < ?
      AND CAST(total_paid AS REAL) >= CAST(total AS REAL) - 0.009`;
    await db.runAsync(`DELETE FROM sale_lines WHERE client_uuid IN (${old})`, stamp);
    await db.runAsync(`DELETE FROM sale_payments WHERE client_uuid IN (${old})`, stamp);
    await db.runAsync(`DELETE FROM outbox WHERE client_uuid IN (${old})`, stamp);
    await db.runAsync(
      `DELETE FROM sales WHERE sync_state = 'synced' AND transaction_date < ?
        AND CAST(total_paid AS REAL) >= CAST(total AS REAL) - 0.009`,
      stamp
    );
    await db.runAsync("DELETE FROM outbox WHERE state = 'sent' AND created_at IS NOT NULL AND created_at < ?", stamp);
    const oldServer = "SELECT id FROM server_sales WHERE transaction_date < ? AND payment_status = 'paid'";
    await db.runAsync(`DELETE FROM server_sale_lines WHERE sale_id IN (${oldServer})`, stamp);
    await db.runAsync(`DELETE FROM server_sale_payments WHERE sale_id IN (${oldServer})`, stamp);
    await db.runAsync("DELETE FROM server_sales WHERE transaction_date < ? AND payment_status = 'paid'", stamp);
  });
}

export async function fetchSale(transactionId) {
  const json = await authFetch(`/cashier/api/sync/sales/${transactionId}`);
  const db = await database();
  await db.withTransactionAsync(async () => {
    await db.runAsync('DELETE FROM server_sale_lines WHERE sale_id = ?', transactionId);
    await db.runAsync('DELETE FROM server_sale_payments WHERE sale_id = ?', transactionId);
    for (const line of json.lines || []) {
      await db.runAsync(
        `INSERT INTO server_sale_lines (sale_id, name, variation_name, quantity, unit_price, sell_line_id, quantity_returned, variation_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        transactionId,
        line.name,
        line.variation_name || '',
        String(line.quantity),
        String(line.unit_price),
        line.sell_line_id ?? null,
        String(line.quantity_returned ?? '0'),
        line.variation_id ?? null
      );
    }
    for (const payment of json.payments || []) {
      await db.runAsync(
        `INSERT INTO server_sale_payments (sale_id, method, amount, is_return)
         VALUES (?, ?, ?, ?)`,
        transactionId,
        payment.method,
        String(payment.amount),
        payment.is_return ? 1 : 0
      );
    }
    await db.runAsync(
      `UPDATE server_sales SET total_paid = ?, payment_status = ?, total_returned = ? WHERE id = ?`,
      String(json.total_paid),
      json.payment_status,
      String(json.total_returned ?? '0'),
      transactionId
    );
    await db.runAsync(
      'UPDATE sales SET total_returned = ? WHERE server_id = ?',
      String(json.total_returned ?? '0'),
      transactionId
    );
  });
  return json;
}

export async function fetchRegister() {
  const json = await authFetch('/cashier/api/register');
  await metaSet('register', JSON.stringify(json));
  return json;
}

export async function openRegister(locationId, openingCash) {
  const json = await authFetch('/cashier/api/register/open', {
    method: 'POST',
    body: JSON.stringify({ location_id: locationId, opening_cash: openingCash }),
  });
  await metaSet('register', JSON.stringify(json));
  return json;
}

export async function closeRegister(closingCash, note) {
  const json = await authFetch('/cashier/api/register/close', {
    method: 'POST',
    body: JSON.stringify({ closing_cash: closingCash, note: note || null }),
  });
  await metaSet('register', JSON.stringify({ open: false }));
  return json;
}

async function saveCustomer(customer) {
  const db = await database();
  await db.runAsync(
    `INSERT INTO customers (id, name, business_name, mobile, email, address, is_default, credit_limit, amount_due, advance, amount_due_here, reward_points)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, '0', ?, '0', ?)
     ON CONFLICT(id) DO UPDATE SET
       name = excluded.name,
       business_name = excluded.business_name,
       mobile = excluded.mobile,
       email = excluded.email,
       address = excluded.address`,
    customer.id,
    customer.name,
    customer.business_name || null,
    customer.mobile || null,
    customer.email || null,
    customer.address || null,
    Number(customer.is_default) || 0,
    customer.credit_limit == null ? null : String(customer.credit_limit),
    String(customer.advance ?? '0'),
    Number(customer.reward_points) || 0
  );
}

async function pendingCreateFor(db, localId) {
  const rows = await db.getAllAsync(
    "SELECT client_uuid, payload FROM outbox WHERE state = 'pending' AND type = 'customer.create'"
  );
  return rows.find((row) => Number(JSON.parse(row.payload).local_id) === Number(localId)) || null;
}

/**
 * Saves a customer on this phone and queues it for TeamPOS. A new customer
 * gets a negative id until TeamPOS gives it a real one.
 */
export async function saveCustomerLocally({ fields, editing, clientUuid, cashier }) {
  const db = await database();
  const now = new Date().toISOString();
  const clean = {
    name: fields.name,
    business_name: fields.business_name || null,
    mobile: fields.mobile,
    email: fields.email || null,
    address: fields.address || null,
  };
  if (!editing) {
    const localId = -Date.now();
    await db.withTransactionAsync(async () => {
      await saveCustomer({ ...clean, id: localId, credit_limit: '0', advance: '0' });
      await db.runAsync(
        "INSERT INTO outbox (client_uuid, type, payload, state, cashier, created_at) VALUES (?, 'customer.create', ?, 'pending', ?, ?)",
        clientUuid,
        JSON.stringify({ ...clean, local_id: localId }),
        cashier,
        now
      );
    });
    return { id: localId, ...clean };
  }
  await db.withTransactionAsync(async () => {
    await saveCustomer({ ...editing, ...clean });
    const create = Number(editing.id) < 0 ? await pendingCreateFor(db, editing.id) : null;
    if (create) {
      const payload = { ...JSON.parse(create.payload), ...clean };
      await db.runAsync(
        'UPDATE outbox SET payload = ?, error = NULL WHERE client_uuid = ?',
        JSON.stringify(payload),
        create.client_uuid
      );
      return;
    }
    await db.runAsync(
      "INSERT INTO outbox (client_uuid, type, payload, state, cashier, created_at) VALUES (?, 'customer.update', ?, 'pending', ?, ?)",
      clientUuid,
      JSON.stringify({ ...clean, id: editing.id }),
      cashier,
      now
    );
  });
  return { ...editing, ...clean };
}

/**
 * Points everything saved against a phone-only customer at the id TeamPOS
 * gave it.
 */
async function adoptCustomer(db, localId, customer) {
  await db.withTransactionAsync(async () => {
    await db.runAsync('DELETE FROM customers WHERE id = ?', localId);
    await saveCustomer(customer);
    await db.runAsync('UPDATE sales SET contact_id = ? WHERE contact_id = ?', customer.id, localId);
    const waiting = await db.getAllAsync(
      "SELECT client_uuid, payload FROM outbox WHERE state = 'pending' AND type IN ('sale.create', 'payment.advance', 'customer.update')"
    );
    for (const row of waiting) {
      const payload = JSON.parse(row.payload);
      const key = payload.contact_id !== undefined ? 'contact_id' : 'id';
      if (Number(payload[key]) === Number(localId)) {
        payload[key] = customer.id;
        await db.runAsync('UPDATE outbox SET payload = ? WHERE client_uuid = ?', JSON.stringify(payload), row.client_uuid);
      }
    }
  });
}

/**
 * Saves a return on this phone. Stock goes back on the shelf now; TeamPOS
 * works out the exact refund when the return syncs.
 */
export async function saveReturnLocally({ clientUuid, cashier, payload, local }) {
  const db = await database();
  await db.withTransactionAsync(async () => {
    await db.runAsync(
      "INSERT INTO outbox (client_uuid, type, payload, state, cashier, created_at) VALUES (?, 'return.create', ?, 'pending', ?, ?)",
      clientUuid,
      JSON.stringify({ ...payload, local }),
      cashier,
      new Date().toISOString()
    );
    for (const item of local.stock || []) {
      await db.runAsync(
        'UPDATE products SET qty_available = qty_available + ? WHERE variation_id = ? AND enable_stock = 1',
        item.quantity,
        item.variation_id
      );
    }
  });
}

async function waitingReturns(db) {
  const rows = await db.getAllAsync(
    "SELECT payload, error FROM outbox WHERE state = 'pending' AND type = 'return.create' ORDER BY rowid"
  );
  return rows.map((row) => ({ ...JSON.parse(row.payload), error: row.error }));
}

async function waitingReturnStock(db, locationId) {
  return (await waitingReturns(db))
    .filter((item) => Number(item.local?.location_id) === Number(locationId))
    .flatMap((item) => item.local?.stock || []);
}

/** Returns saved on this phone that TeamPOS has not taken yet, by sale. */
export async function pendingReturns() {
  const db = await database();
  const bySale = {};
  for (const item of await waitingReturns(db)) {
    const entry = bySale[item.transaction_id] || { value: 0, lines: {}, error: '' };
    entry.value += Number(item.local?.value || 0);
    for (const line of item.lines || []) {
      entry.lines[line.sell_line_id] = (entry.lines[line.sell_line_id] || 0) + Number(line.quantity);
    }
    entry.error = item.error || entry.error;
    bySale[item.transaction_id] = entry;
  }
  return bySale;
}

export async function fetchReceipt(transactionId) {
  const json = await authFetch(`/cashier/api/sync/receipts/${transactionId}`);
  return json.html;
}

/**
 * Sends work saved on this phone. Rows belong to the cashier who rang them
 * up, so a second cashier signing in does not send the first one's sales
 * under their own name.
 */
/**
 * A payment taken on a sale that has not reached TeamPOS yet becomes part of
 * that sale, so a sale TeamPOS refused (for example a walk-in left owing) is
 * sent again with the balance paid.
 */
async function foldWaitingPayments(db) {
  const waiting = await db.getAllAsync(
    "SELECT client_uuid, payload FROM outbox WHERE state = 'pending' AND type = 'payment.add' ORDER BY rowid"
  );
  for (const row of waiting) {
    const payment = JSON.parse(row.payload);
    if (payment.transaction_id || !payment.sale_client_uuid) {
      continue;
    }
    const sale = await db.getFirstAsync(
      "SELECT payload FROM outbox WHERE client_uuid = ? AND type = 'sale.create' AND state = 'pending'",
      payment.sale_client_uuid
    );
    if (!sale) {
      continue;
    }
    const payload = JSON.parse(sale.payload);
    payload.payments = [
      ...(payload.payments || []),
      { method: payment.method, amount: payment.amount, tendered: payment.amount },
    ];
    await db.withTransactionAsync(async () => {
      await db.runAsync(
        'UPDATE outbox SET payload = ?, error = NULL WHERE client_uuid = ?',
        JSON.stringify(payload),
        payment.sale_client_uuid
      );
      await db.runAsync("UPDATE outbox SET state = 'sent', error = NULL WHERE client_uuid = ?", row.client_uuid);
      await db.runAsync('UPDATE sales SET error = NULL WHERE client_uuid = ?', payment.sale_client_uuid);
    });
  }
}

export async function pushOutbox(cashier) {
  const db = await database();
  await foldWaitingPayments(db);
  const rows = await db.getAllAsync(
    `SELECT client_uuid, type FROM outbox
     WHERE state = 'pending'
       AND type IN ('customer.create', 'customer.update', 'sale.create', 'payment.add', 'return.create', 'payment.advance')
       AND (cashier IS NULL OR LOWER(cashier) = LOWER(?))
     ORDER BY CASE type
       WHEN 'customer.create' THEN 0 WHEN 'customer.update' THEN 1 WHEN 'sale.create' THEN 2
       WHEN 'payment.add' THEN 3 WHEN 'return.create' THEN 4 ELSE 5 END, rowid`,
    cashier || ''
  );
  const failed = [];
  const adopted = {};
  for (const { client_uuid: clientUuid } of rows) {
    const row = await db.getFirstAsync(
      "SELECT * FROM outbox WHERE client_uuid = ? AND state = 'pending'",
      clientUuid
    );
    if (!row) {
      continue;
    }
    let payload = {};
    try {
      payload = JSON.parse(row.payload);
      if (Number(payload.contact_id) < 0 || (row.type === 'customer.update' && Number(payload.id) < 0)) {
        // Waits until the customer it belongs to reaches TeamPOS.
        continue;
      }
      if (row.type === 'payment.add' && !payload.transaction_id && payload.sale_client_uuid) {
        const sale = await db.getFirstAsync('SELECT server_id FROM sales WHERE client_uuid = ?', payload.sale_client_uuid);
        if (!sale?.server_id) {
          // The payment waits on this phone until its sale reaches TeamPOS.
          continue;
        }
        payload = { ...payload, transaction_id: sale.server_id };
      }
      if (row.type === 'customer.create') {
        const { local_id: localId, ...fields } = payload;
        const json = await authFetch('/cashier/api/customers', {
          method: 'POST',
          body: JSON.stringify({ ...fields, client_uuid: row.client_uuid }),
        });
        await adoptCustomer(db, localId, json.customer);
        adopted[localId] = json.customer.id;
      } else if (row.type === 'customer.update') {
        const { id, ...fields } = payload;
        const json = await authFetch(`/cashier/api/customers/${id}`, { method: 'PUT', body: JSON.stringify(fields) });
        await saveCustomer(json.customer);
      } else if (row.type === 'return.create') {
        const { local, ...body } = payload;
        await authFetch('/cashier/api/returns', {
          method: 'POST',
          body: JSON.stringify({ ...body, client_uuid: row.client_uuid }),
        });
      } else {
        await syncOperation(db, row, payload);
      }
      await db.runAsync(
        "UPDATE outbox SET state = 'sent', error = NULL WHERE client_uuid = ?",
        row.client_uuid
      );
    } catch (error) {
      if (error.status === 401 || !error.status) {
        throw error;
      }
      await db.runAsync(
        "UPDATE outbox SET error = ? WHERE client_uuid = ?",
        error.message,
        row.client_uuid
      );
      failed.push({ type: row.type, message: error.message, name: payload.name || '' });
      if (row.type === 'sale.create') {
        await db.runAsync(
          "UPDATE sales SET error = ? WHERE client_uuid = ?",
          error.message,
          row.client_uuid
        );
      }
    }
  }
  return { failed, adopted };
}

async function syncOperation(db, row, payload) {
  const result = await authFetch('/cashier/api/sync/operations', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
  if (row.type === 'payment.add') {
    if (payload.sale_client_uuid) {
      await db.runAsync(
        'UPDATE sales SET total_paid = ?, error = NULL WHERE client_uuid = ?',
        String(result.total_paid),
        payload.sale_client_uuid
      );
    }
    if (result.transaction_id) {
      await db.runAsync(
        'UPDATE server_sales SET total_paid = ?, payment_status = ? WHERE id = ?',
        String(result.total_paid),
        result.payment_status,
        result.transaction_id
      );
    }
  } else if (row.type === 'sale.create') {
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
  }
}
