const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function tracksStock(product) {
  return Number(product?.enable_stock ?? 1) !== 0;
}

export function stockLabel(value, product) {
  if (product && !tracksStock(product)) {
    return 'Always available';
  }
  const qty = Number(value);
  if (!Number.isFinite(qty) || qty <= 0) {
    return 'Out of stock';
  }
  const shown = Number.isInteger(qty) ? String(qty) : String(Math.round(qty * 100) / 100);
  return `${shown} in stock`;
}

export function money(value) {
  const amount = Number(value);
  const safe = Number.isFinite(amount) ? amount : 0;
  return `GH₵${safe.toFixed(2)}`;
}

export function prettyDate(value) {
  const date = value instanceof Date ? value : parseDate(value);
  if (!date) {
    return value ? String(value) : '';
  }
  const hours = date.getHours();
  const hour = hours % 12 || 12;
  const minutes = String(date.getMinutes()).padStart(2, '0');
  const suffix = hours < 12 ? 'AM' : 'PM';
  return `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()} · ${hour}:${minutes} ${suffix}`;
}

export function dayKey(value) {
  const date = value instanceof Date ? value : parseDate(value);
  if (!date) {
    return '';
  }
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

export function registerIsOpen(register) {
  return Boolean(register?.open);
}

function parseDate(value) {
  if (!value) {
    return null;
  }
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? null : date;
}

export function variationLabel(name) {
  if (!name || /^dummy$/i.test(String(name).trim())) {
    return '';
  }
  return String(name);
}

export function visibleProducts(products, query, filter) {
  const needle = query.trim().toLowerCase();
  return products.filter((product) => {
    const qty = Number(product.qty_available);
    const tracked = tracksStock(product);
    if (filter === 'stock' && tracked && qty <= 0) {
      return false;
    }
    if (filter === 'low' && !(tracked && qty > 0 && qty <= 5)) {
      return false;
    }
    if (!needle) {
      return true;
    }
    const haystack = `${product.name} ${product.variation_name || ''} ${product.sku || ''}`.toLowerCase();
    return haystack.includes(needle);
  });
}

export function cartTotal(lines) {
  return lines.reduce((sum, line) => sum + line.quantity * line.unit_price, 0);
}

/**
 * Mirrors the TeamPOS total: items, less the sale discount, less points used.
 */
export function saleTotals(lines, discount, points, rewards) {
  const subtotal = roundMoney(cartTotal(lines));
  const amount = Number(discount?.amount) || 0;
  let discountAmount = 0;
  if (amount > 0) {
    discountAmount = discount.type === 'percentage' ? (subtotal * Math.min(amount, 100)) / 100 : Math.min(amount, subtotal);
  }
  discountAmount = roundMoney(discountAmount);
  const pointsAmount = roundMoney((Number(points) || 0) * (Number(rewards?.amount_per_point) || 0));
  const total = roundMoney(Math.max(0, subtotal - discountAmount - pointsAmount));
  return { subtotal, discountAmount, pointsAmount, total };
}

export function isoWithOffset(date) {
  const pad = (n) => String(Math.floor(Math.abs(n))).padStart(2, '0');
  const offset = -date.getTimezoneOffset();
  const sign = offset >= 0 ? '+' : '-';
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}` +
    `${sign}${pad(offset / 60)}:${pad(offset % 60)}`
  );
}

export function cartCount(lines) {
  return lines.reduce((sum, line) => sum + line.quantity, 0);
}

export function withQuantity(lines, variationId, quantity) {
  if (quantity <= 0) {
    return lines.filter((line) => line.variation_id !== variationId);
  }
  return lines.map((line) =>
    line.variation_id === variationId ? { ...line, quantity } : line
  );
}

export function buildSalePayload({
  clientUuid,
  deviceRef,
  locationId,
  customerId,
  transactionDate,
  lines,
  payments,
  place,
  discount,
  points,
}) {
  const payload = {
    type: 'sale.create',
    client_uuid: clientUuid,
    device_ref: deviceRef,
    location_id: locationId,
    contact_id: customerId,
    transaction_date: transactionDate,
    products: lines.map((line) => ({
      product_id: line.product_id,
      variation_id: line.variation_id,
      quantity: line.quantity,
      unit_price: line.unit_price,
      ...(line.price_override ? { price_override: true } : {}),
    })),
    payments: payments.map((payment) => ({
      method: payment.method,
      amount: payment.amount,
      tendered: payment.tendered,
    })),
  };
  if (Number(discount?.amount) > 0) {
    payload.discount_type = discount.type === 'percentage' ? 'percentage' : 'fixed';
    payload.discount_amount = Number(discount.amount);
  }
  if (Number(points) > 0) {
    payload.rp_redeemed = Math.floor(Number(points));
  }
  if (place && Number.isFinite(place.latitude) && Number.isFinite(place.longitude)) {
    payload.latitude = place.latitude;
    payload.longitude = place.longitude;
    if (Number.isFinite(place.accuracy)) {
      payload.accuracy = place.accuracy;
    }
  }
  return payload;
}

export function customerHeading(customer) {
  const business = String(customer?.business_name || '').trim();
  const name = String(customer?.name || '').trim();
  if (!name && business) {
    return { title: business, subtitle: '' };
  }
  if (business) {
    return { title: business, subtitle: name };
  }
  return { title: name || 'Customer', subtitle: '' };
}

export function customerHint(customer) {
  const heading = customerHeading(customer);
  const extra = Number(customer?.is_default) === 1 ? 'Walk-in' : String(customer?.mobile || '').trim();
  return [heading.subtitle, extra].filter(Boolean).join(' · ');
}

function roundMoney(value) {
  return Math.round((Number(value) || 0) * 100) / 100;
}

export function settlePayments(total, rows) {
  const bill = roundMoney(total);
  if (rows.some((row) => row.method === 'credit')) {
    return { error: '', payments: [], change: 0, due: bill, paid: 0 };
  }
  if (!rows.length) {
    return { error: 'Choose how this sale is paid.', payments: [], change: 0, due: bill, paid: 0 };
  }
  let remaining = bill;
  let change = 0;
  const payments = [];
  for (const row of rows) {
    const raw = String(row.received ?? '').trim();
    if (raw === '' || raw === '.') {
      return { error: 'Enter the amount received.', payments: [], change: 0, due: bill, paid: 0 };
    }
    const received = roundMoney(raw);
    if (received <= 0) {
      return { error: 'Enter an amount greater than zero.', payments: [], change: 0, due: bill, paid: 0 };
    }
    if (row.method === 'cash') {
      const applied = roundMoney(Math.min(received, Math.max(remaining, 0)));
      change = roundMoney(change + received - applied);
      payments.push({ method: 'cash', amount: applied, tendered: received });
      remaining = roundMoney(remaining - applied);
    } else if (received - remaining > 0.009) {
      return {
        error: 'That amount is more than the balance still due.',
        payments: [],
        change: 0,
        due: bill,
        paid: 0,
      };
    } else {
      payments.push({ method: row.method, amount: received, tendered: received });
      remaining = roundMoney(remaining - received);
    }
  }
  const paid = roundMoney(payments.reduce((sum, payment) => sum + payment.amount, 0));
  return { error: '', payments, change, due: Math.max(0, remaining), paid };
}

export function creditAllowed(customer, due) {
  if (due <= 0.009) {
    return { ok: true, reason: '' };
  }
  if (!customer || Number(customer.is_default) === 1) {
    return { ok: false, reason: 'Walk-in has to pay the full amount.' };
  }
  const limit = customer.credit_limit;
  if (limit == null || String(limit).trim() === '') {
    return { ok: true, reason: 'The balance stays on this customer’s account.' };
  }
  const cap = Number(limit);
  if (!Number.isFinite(cap) || cap <= 0.009) {
    return { ok: false, reason: 'This customer has to pay the full amount.' };
  }
  const already = Number(customer.amount_due) || 0;
  if (!Number.isFinite(cap) || already + due > cap + 0.009) {
    const room = Math.max(0, cap - already);
    return { ok: false, reason: `Credit limit is ${money(cap)}. ${money(room)} is still available.` };
  }
  return { ok: true, reason: `Credit limit ${money(cap)}.` };
}

export function paymentStatus(total, paid) {
  const due = Number(total) - Number(paid);
  if (due <= 0.009) {
    return 'paid';
  }
  if (Number(paid) > 0.009) {
    return 'partial';
  }
  return 'due';
}

export function addProduct(lines, product) {
  const tracked = tracksStock(product);
  const onHand = tracked ? Number(product.qty_available) : Infinity;
  if (tracked && (!Number.isFinite(onHand) || onHand <= 0)) {
    return lines;
  }
  const found = lines.find((line) => line.variation_id === product.variation_id);
  if (found) {
    if (found.quantity + 1 > onHand + 0.0001) {
      return lines;
    }
    return withQuantity(lines, product.variation_id, found.quantity + 1);
  }
  return [
    ...lines,
    {
      product_id: product.product_id,
      variation_id: product.variation_id,
      name: product.name,
      variation_name: variationLabel(product.variation_name),
      quantity: 1,
      unit_price: Number(product.sell_price),
      list_price: Number(product.sell_price),
      qty_available: tracked ? Number(product.qty_available) : null,
      enable_stock: tracked ? 1 : 0,
    },
  ];
}
