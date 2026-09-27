import { useEffect, useRef, useState } from 'react';
import { Button, FlatList, Text, TextInput, View } from 'react-native';
import { database, metaGet } from './src/db';
import { login } from './src/api';
import { fetchLocations, nextDeviceRef, pullLocation, pushOutbox } from './src/sync';

export default function App() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [locations, setLocations] = useState([]);
  const [locationId, setLocationId] = useState(null);
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [sales, setSales] = useState([]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);

  async function refreshLocal() {
    const db = await database();
    setProducts(await db.getAllAsync('SELECT * FROM products ORDER BY name'));
    setSales(await db.getAllAsync('SELECT * FROM sales ORDER BY transaction_date DESC'));
  }

  useEffect(() => {
    (async () => {
      const savedLocation = await metaGet('location_id');
      if (savedLocation) {
        setLocationId(Number(savedLocation));
      }
      await refreshLocal();
    })().catch((e) => setError(e.message));
  }, []);

  async function onLogin() {
    setError('');
    try {
      await login(username, password);
      const rows = await fetchLocations();
      setLocations(rows);
      if (rows.length === 1) {
        await chooseLocation(rows[0].id);
      }
    } catch (e) {
      setError(e.message);
    }
  }

  async function chooseLocation(id) {
    setError('');
    try {
      await pullLocation(id);
      setLocationId(id);
      setLocations([]);
      await refreshLocal();
    } catch (e) {
      setError(e.message);
    }
  }

  function addToCart(product) {
    setCart((current) => {
      const found = current.find((line) => line.variation_id === product.variation_id);
      if (found) {
        return current.map((line) =>
          line.variation_id === product.variation_id
            ? { ...line, quantity: line.quantity + 1 }
            : line
        );
      }
      return [
        ...current,
        {
          product_id: product.product_id,
          variation_id: product.variation_id,
          name: product.name,
          quantity: 1,
          unit_price: Number(product.sell_price),
        },
      ];
    });
  }

  const total = cart.reduce((sum, line) => sum + line.quantity * line.unit_price, 0);

  async function finishSale() {
    if (savingRef.current) {
      return;
    }
    savingRef.current = true;
    setSaving(true);
    try {
      await saveSale();
    } catch (e) {
      setError(e.message);
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function saveSale() {
    setError('');
    const db = await database();
    const customer = await db.getFirstAsync(
      'SELECT * FROM customers WHERE is_default = 1 LIMIT 1'
    );
    if (!customer || cart.length === 0) {
      setError('Add a product. A walk-in customer must be on the device.');
      return;
    }
    const clientUuid = await newClientUuid();
    const deviceRef = await nextDeviceRef();
    const transactionDate = localDateTime(new Date());
    const payload = {
      type: 'sale.create',
      client_uuid: clientUuid,
      device_ref: deviceRef,
      location_id: locationId,
      contact_id: customer.id,
      transaction_date: transactionDate,
      products: cart.map((line) => ({
        product_id: line.product_id,
        variation_id: line.variation_id,
        quantity: line.quantity,
        unit_price: line.unit_price,
      })),
      payments: [{ method: 'cash', amount: total }],
    };

    await db.withTransactionAsync(async () => {
      await db.runAsync(
        `INSERT INTO sales (client_uuid, device_ref, contact_id, total, transaction_date, sync_state)
         VALUES (?, ?, ?, ?, ?, 'pending')`,
        clientUuid,
        deviceRef,
        customer.id,
        String(total),
        transactionDate
      );
      for (const line of cart) {
        await db.runAsync(
          `INSERT INTO sale_lines (client_uuid, product_id, variation_id, quantity, unit_price)
           VALUES (?, ?, ?, ?, ?)`,
          clientUuid,
          line.product_id,
          line.variation_id,
          String(line.quantity),
          String(line.unit_price)
        );
        await db.runAsync(
          'UPDATE products SET qty_available = qty_available - ? WHERE variation_id = ?',
          line.quantity,
          line.variation_id
        );
      }
      await db.runAsync(
        `INSERT INTO outbox (client_uuid, type, payload, state) VALUES (?, 'sale.create', ?, 'pending')`,
        clientUuid,
        JSON.stringify(payload)
      );
    });
    setCart([]);
    await refreshLocal();
  }

  async function onSync() {
    setError('');
    try {
      await pushOutbox();
      if (locationId) {
        await pullLocation(locationId);
      }
      await refreshLocal();
    } catch (e) {
      setError(e.message);
    }
  }

  if (!locationId) {
    return (
      <View style={{ padding: 24, paddingTop: 64 }}>
        <Text>TeamPOS cashier</Text>
        <TextInput placeholder="Username" autoCapitalize="none" value={username} onChangeText={setUsername} />
        <TextInput placeholder="Password" secureTextEntry value={password} onChangeText={setPassword} />
        <Button title="Sign in" onPress={onLogin} />
        {locations.map((location) => (
          <Button key={location.id} title={location.name} onPress={() => chooseLocation(location.id)} />
        ))}
        <Text>{error}</Text>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, padding: 16, paddingTop: 64 }}>
      <Button title="Sync" onPress={onSync} />
      <Text>{error}</Text>
      <FlatList
        data={products}
        keyExtractor={(item) => String(item.variation_id)}
        renderItem={({ item }) => (
          <Button
            title={`${item.name}  ${item.sell_price}  qty ${item.qty_available}`}
            onPress={() => addToCart(item)}
          />
        )}
      />
      <Text>Cart {total}</Text>
      {cart.map((line) => (
        <Text key={line.variation_id}>{line.name} x {line.quantity}</Text>
      ))}
      <Button title="Cash sale" onPress={finishSale} disabled={saving} />
      {sales.map((sale) => (
        <Text key={sale.client_uuid}>
          {sale.device_ref} {sale.invoice_no || sale.sync_state} {sale.error || ''}
        </Text>
      ))}
    </View>
  );
}

function localDateTime(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
    `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
  );
}

async function newClientUuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}
