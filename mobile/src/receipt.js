function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function moneyText(value) {
  const amount = Number(value);
  const safe = Number.isFinite(amount) ? amount : 0;
  return `GH₵${safe.toFixed(2)}`;
}

function row(left, right) {
  return `<tr><td>${left}</td><td class="right">${right}</td></tr>`;
}

export function receiptHtml({ sale, layout = {}, methods = [] }) {
  const labelFor = (id) => methods.find((method) => method.id === id)?.label || id || '';
  const heading = layout.invoice_heading || 'Invoice';
  const reference = sale.invoiceNo || sale.deviceRef || sale.subtitle || '';
  const lines = sale.lines || [];
  const payments = (sale.payments || []).filter((payment) => Number(payment.amount) > 0 || Number(payment.tendered) > 0);
  const paid = payments.reduce((sum, payment) => {
    if (payment.is_return) {
      return sum;
    }
    return sum + Number(payment.amount || 0);
  }, 0);
  const change = Number(sale.change) || payments
    .filter((payment) => payment.is_return)
    .reduce((sum, payment) => sum + Number(payment.amount || 0), 0);
  const total = Number(sale.total ?? sale.amount ?? 0);
  const due = Math.max(0, total - (Number(sale.paid ?? paid) || 0));
  const customer = layout.show_customer === false ? '' : sale.customerName || sale.title || '';

  const itemRows = lines
    .map((line) => {
      const name = esc(line.name || 'Item');
      const qty = Number(line.quantity);
      const price = Number(line.unit_price);
      return row(`${name}<div class="muted">${esc(String(qty))} × ${esc(moneyText(price))}</div>`, esc(moneyText(qty * price)));
    })
    .join('');

  const paymentRows = payments
    .map((payment) => {
      const name = payment.is_return ? 'Change' : labelFor(payment.method);
      const amount = payment.is_return ? payment.amount : payment.tendered || payment.amount;
      return row(esc(name), esc(moneyText(amount)));
    })
    .join('');

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(reference || heading)}</title>
<style>
  body { margin: 0; color: #000; background: #fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
  .sheet { max-width: 320px; margin: 0 auto; padding: 16px 12px 28px; }
  h1 { font-size: 20px; text-align: center; margin: 0 0 4px; }
  h2 { font-size: 16px; text-align: center; margin: 12px 0; letter-spacing: 0.04em; }
  p { text-align: center; margin: 4px 0; font-size: 13px; line-height: 1.4; }
  .muted { color: #333; font-size: 12px; font-weight: 400; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  td { padding: 6px 0; vertical-align: top; font-size: 14px; }
  .right { text-align: right; white-space: nowrap; font-weight: 600; }
  .rule { border-top: 1px dashed #000; }
  .total td { font-size: 18px; font-weight: 700; padding-top: 8px; }
</style>
</head>
<body>
<div class="sheet">
  ${layout.header_text || ''}
  <h1>${esc(layout.display_name || '')}</h1>
  ${layout.address ? `<p>${esc(layout.address)}</p>` : ''}
  ${layout.contact ? `<p>${esc(layout.contact)}</p>` : ''}
  ${(layout.subheadings || []).map((line) => `<p>${esc(line)}</p>`).join('')}
  <h2>${esc(heading)}</h2>
  <p>${esc(reference)}</p>
  <p>${esc(sale.whenLabel || sale.transactionDate || '')}</p>
  ${customer ? `<p>${esc(customer)}</p>` : ''}
  <table>
    ${itemRows}
    <tr class="rule"><td></td><td></td></tr>
    <tr class="total"><td>Total</td><td class="right">${esc(moneyText(total))}</td></tr>
    ${paymentRows}
    ${change > 0.009 ? row('Change', esc(moneyText(change))) : ''}
    ${due > 0.009 ? row('Balance due', esc(moneyText(due))) : ''}
  </table>
  ${layout.footer_text || ''}
</div>
</body>
</html>`;
}
