import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Animated,
  AppState,
  BackHandler,
  Easing,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  Platform,
  ToastAndroid,
  useWindowDimensions,
  View,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import * as Print from 'expo-print';
import * as SplashScreen from 'expo-splash-screen';
import * as Network from 'expo-network';
import Constants from 'expo-constants';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import {
  ArrowLeftRight,
  Banknote,
  Check,
  ChevronRight,
  CircleCheck,
  Clock,
  CloudOff,
  Croissant,
  Eye,
  EyeOff,
  Info,
  Lock,
  LockOpen,
  LogOut,
  MapPin,
  RefreshCw,
  Smartphone,
  Store,
  User,
  UserPen,
  UserPlus,
  Vault,
  Wallet,
} from 'lucide-react-native';
import { database, metaDelete, metaGet, metaSet } from './src/db';
import { login, logout, whenSignedOut } from './src/api';
import {
  closeRegister,
  deviceId,
  fetchLocations,
  fetchRegister,
  fetchSale,
  nextDeviceRef,
  openRegister,
  pullLocation,
  pendingReturns,
  pushOutbox,
  saveCustomerLocally,
  saveReturnLocally,
} from './src/sync';
import { SwipeBack } from './src/kit';
import Checkout from './src/screens/Checkout';
import CustomerForm from './src/screens/CustomerForm';
import Drawer from './src/screens/Drawer';
import RegisterGate from './src/screens/RegisterGate';
import SaleReturn from './src/screens/SaleReturn';
import SaleView from './src/screens/SaleView';
import Transactions from './src/screens/Transactions';
import { receiptHtml } from './src/receipt';
import { capturePlace } from './src/place';
import { remindToCloseRegister } from './src/reminders';
import logo from './assets/logo.png';
import {
  addProduct,
  buildSalePayload,
  cartCount,
  cartTotal,
  creditAllowed,
  customerHeading,
  customerHint,
  dayKey,
  isoWithOffset,
  money,
  paymentStatus,
  prettyDate,
  registerIsOpen,
  saleTotals,
  settlePayments,
  tracksStock,
  visibleProducts,
  withQuantity,
} from './src/format';
import {
  Banner,
  CartBar,
  Chip,
  PrimaryButton,
  ProductCard,
  QuietButton,
  SaleCard,
  ScreenHeader,
  SearchField,
  StatusPill,
  StockFilter,
  TabBar,
  colors,
} from './src/ui';

const SPLASH_MS = 2500;
const launchedAt = Date.now();
SplashScreen.preventAutoHideAsync().catch(() => {});

export default function App() {
  return (
    <SafeAreaProvider>
      <StatusBar style="dark" />
      <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
        <Frame>
          <Root />
        </Frame>
      </SafeAreaView>
    </SafeAreaProvider>
  );
}

function Frame({ children }) {
  const { width } = useWindowDimensions();
  return <View style={[styles.frame, { maxWidth: Math.min(width, 480) }]}>{children}</View>;
}

function Root() {
  const previewName = webPreviewName();
  if (previewName) {
    return <WebPreview name={previewName} />;
  }
  return <Cashier />;
}

function Cashier() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [locations, setLocations] = useState([]);
  const [branches, setBranches] = useState([]);
  const [locationId, setLocationId] = useState(null);
  const [locationName, setLocationName] = useState('');
  const [businessName, setBusinessName] = useState('');
  const [cashierName, setCashierName] = useState('');
  const [deviceCode, setDeviceCode] = useState('');
  const [saleView, setSaleView] = useState(null);
  const [customerView, setCustomerView] = useState(null);
  const [tenders, setTenders] = useState([{ method: 'cash', received: '' }]);
  const online = useOnline();
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [sales, setSales] = useState([]);
  const [returnsWaiting, setReturnsWaiting] = useState({});
  const [serverSales, setServerSales] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [customerId, setCustomerId] = useState(null);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [booting, setBooting] = useState(true);
  const [opening, setOpening] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [tab, setTab] = useState('home');
  const [query, setQuery] = useState('');
  const [saleQuery, setSaleQuery] = useState('');
  const [stockFilter, setStockFilter] = useState('stock');
  const [saleFilter, setSaleFilter] = useState('all');
  const [stage, setStage] = useState('register');
  const [receipt, setReceipt] = useState(null);
  const [lastSync, setLastSync] = useState(null);
  const [discount, setDiscount] = useState({ type: 'fixed', amount: 0 });
  const [points, setPoints] = useState(0);
  const [settings, setSettings] = useState({});
  const [register, setRegister] = useState(null);
  const [overlay, setOverlay] = useState(null);
  const [busy, setBusy] = useState(false);
  const savingRef = useRef(false);
  const syncingRef = useRef(false);

  const refreshLocal = useCallback(async () => {
    const db = await database();
    const savedLocation = await metaGet('location_id');
    setProducts(await db.getAllAsync('SELECT * FROM products ORDER BY name'));
    setSales(
      await db.getAllAsync(
        `SELECT sales.*,
           CASE
             WHEN TRIM(COALESCE(customers.name, '')) = '' THEN COALESCE(NULLIF(TRIM(customers.business_name), ''), 'Customer')
             ELSE TRIM(customers.name)
           END AS customer_name
         FROM sales
         LEFT JOIN customers ON customers.id = sales.contact_id
         WHERE sales.location_id IS NULL OR sales.location_id = ?
         ORDER BY transaction_date DESC`,
        Number(savedLocation) || 0
      )
    );
    setServerSales(
      await db.getAllAsync(
        `SELECT server_sales.*,
           CASE
             WHEN TRIM(COALESCE(customers.name, '')) = '' THEN COALESCE(NULLIF(TRIM(customers.business_name), ''), 'Customer')
             ELSE TRIM(customers.name)
           END AS customer_name
         FROM server_sales
         LEFT JOIN customers ON customers.id = server_sales.contact_id
         WHERE server_sales.location_id IS NULL OR server_sales.location_id = ?
         ORDER BY transaction_date DESC`,
        Number(savedLocation) || 0
      )
    );
    setReturnsWaiting(await pendingReturns());
    const customerRows = await db.getAllAsync(
      'SELECT * FROM customers ORDER BY is_default DESC, name'
    );
    setCustomers(customerRows);
    setLocationName((await metaGet('location_name')) || '');
    setBusinessName((await metaGet('business_name')) || '');
    setCashierName((await metaGet('username')) || '');
    setDeviceCode(`C-${(await deviceId()).slice(0, 6).toUpperCase()}`);
    setLastSync(savedLocation ? await metaGet(`since_${savedLocation}`) : null);
    let storedMethods = [];
    try {
      storedMethods = JSON.parse((await metaGet('payment_methods')) || '[]');
    } catch {
      storedMethods = [];
    }
    setPaymentMethods(Array.isArray(storedMethods) ? storedMethods : []);
    setSettings(readJson(await metaGet('cashier_settings'), {}));
    setRegister(readJson(await metaGet('register'), null));
    setBranches(readJson(await metaGet('locations'), []));
    setCustomerId((current) => {
      if (current && customerRows.some((row) => row.id === current)) {
        return current;
      }
      const walkIn = customerRows.find((row) => row.is_default);
      return walkIn ? walkIn.id : customerRows[0]?.id ?? null;
    });
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const savedLocation = await metaGet('location_id');
        if (savedLocation) {
          await openSavedLocation(Number(savedLocation));
        }
      } catch (loadError) {
        setError(loadError.message);
      }
      await refreshLocal();
    })()
      .catch((loadError) => setError(loadError.message))
      .finally(() => {
        setBooting(false);
        const wait = Math.max(0, SPLASH_MS - (Date.now() - launchedAt));
        setTimeout(() => SplashScreen.hideAsync().catch(() => {}), wait);
      });
  }, [refreshLocal]);

  async function openSavedLocation(savedLocation) {
    try {
      await pullLocation(savedLocation);
      setLocationId(savedLocation);
      return;
    } catch (error) {
      if (error.status !== 403) {
        setLocationId(savedLocation);
        throw error;
      }
    }

    const rows = await rememberBranches();
    setLocations(rows);
    if (rows.length === 1) {
      await pullLocation(rows[0].id);
      setLocationId(rows[0].id);
      return;
    }
    await metaSet('location_id', '');
    setLocationId(null);
  }

  useEffect(() => {
    if (stage === 'confirm' && cart.length === 0) {
      setStage('register');
    }
  }, [stage, cart.length]);

  useEffect(() => {
    whenSignedOut(() => {
      logout().finally(signOutLocally);
      setError('Your sign-in has expired. Sign in again.');
    });
    return () => whenSignedOut(null);
  }, []);

  useEffect(() => {
    if (paymentMethods.length === 0) {
      return;
    }
    setPaymentMethod((current) => {
      if (paymentMethods.some((method) => method.id === current)) {
        return current;
      }
      const cash = paymentMethods.find((method) => method.id === 'cash');
      return cash ? cash.id : paymentMethods[0].id;
    });
  }, [paymentMethods]);

  async function onLogin() {
    setError('');
    setOpening(true);
    try {
      await metaSet('username', username.trim());
      setCashierName(username.trim());
      await login(username.trim(), password);
      const rows = await rememberBranches();
      setLocations(rows);
      if (rows.length === 1) {
        await chooseLocation(rows[0].id);
      }
    } catch (loginError) {
      setError(loginError.message);
    } finally {
      setOpening(false);
    }
  }

  async function rememberBranches() {
    const rows = await fetchLocations();
    await metaSet('locations', JSON.stringify(rows.map((row) => ({ id: row.id, name: row.name }))));
    setBranches(rows);
    return rows;
  }

  async function chooseLocation(id) {
    setError('');
    setOpening(true);
    try {
      // Products and stock belong to one branch, so a new branch starts from a full download.
      await metaSet(`since_${id}`, '');
      await pullLocation(id);
      setLocationId(id);
      setLocations([]);
      setCart([]);
      setDiscount({ type: 'fixed', amount: 0 });
      setPoints(0);
      setStage('register');
      setTab('home');
      await refreshLocal();
    } catch (locationError) {
      setError(locationError.message);
    } finally {
      setOpening(false);
    }
  }

  const addToCart = useCallback((product) => {
    setCart((current) => addProduct(current, product));
  }, []);

  function changeLine(variationId, quantity) {
    setCart((current) => {
      const line = current.find((item) => item.variation_id === variationId);
      const product = products.find((item) => item.variation_id === variationId);
      const onHand = tracksStock(product || line) ? Number(product?.qty_available ?? line?.qty_available ?? 0) : Infinity;
      return withQuantity(current, variationId, Math.min(quantity, onHand));
    });
  }

  function changePrice(variationId, price) {
    setCart((current) =>
      current.map((line) => {
        if (line.variation_id !== variationId) {
          return line;
        }
        const list = line.list_price ?? line.unit_price;
        if (price == null || Math.abs(price - list) < 0.005) {
          return { ...line, unit_price: list, price_override: false };
        }
        return { ...line, list_price: list, unit_price: Math.round(price * 100) / 100, price_override: true };
      })
    );
  }

  async function push() {
    const { failed, adopted } = await pushOutbox(await metaGet('username'));
    setCustomerId((current) => adopted[current] ?? current);
    setCustomerView((current) => adopted[current] ?? current);
    const problem = failed.find((item) => item.type !== 'sale.create');
    if (problem) {
      setError(outboxProblem(problem));
    }
  }

  const subtotal = cartTotal(cart);
  const total = saleTotals(cart, discount, points, settings.rewards).total;
  const count = cartCount(cart);
  const selectedCustomer = customers.find((customer) => customer.id === customerId) || null;
  const methodLabel =
    paymentMethods.find((method) => method.id === paymentMethod)?.label || 'Cash';

  async function finishSale() {
    if (savingRef.current) {
      return;
    }
    savingRef.current = true;
    setSaving(true);
    try {
      await saveSale();
    } catch (saleError) {
      setError(saleError.message);
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function saveSale() {
    setError('');
    const customer = selectedCustomer;
    if (!customer || cart.length === 0) {
      setError('Add a product. A customer must be on the device.');
      return;
    }
    if (!registerIsOpen(register)) {
      setError('Open the register before selling.');
      return;
    }
    const stockProblem = cart.find((line) => {
      const product = products.find((item) => item.variation_id === line.variation_id);
      if (!tracksStock(product || line)) {
        return false;
      }
      const onHand = Number(product?.qty_available ?? line.qty_available);
      return line.quantity > onHand + 0.0001;
    });
    if (stockProblem) {
      setError(`${stockProblem.name} does not have enough stock.`);
      return;
    }
    const settlement = settlePayments(total, tenders);
    const credit = creditAllowed(customer, settlement.due);
    if (settlement.error || !credit.ok) {
      setError(settlement.error || credit.reason);
      return;
    }
    if (settlement.due > 0.009 && settings.can_sell_on_credit === false) {
      setError('Your account cannot sell on credit.');
      return;
    }
    const place = await capturePlace();
    const clientUuid = newClientUuid();
    const deviceRef = await nextDeviceRef();
    const now = new Date();
    const transactionDate = localDateTime(now);
    const cashier = (await metaGet('username')) || '';
    const lines = cart.map((line) => ({ ...line }));
    const usedPoints = Number(points) || 0;
    const discountTaken = Math.round((subtotal - total) * 100) / 100;
    const payload = buildSalePayload({
      clientUuid,
      deviceRef,
      locationId,
      customerId: customer.id,
      transactionDate: isoWithOffset(now),
      lines,
      payments: settlement.payments,
      place,
      discount,
      points: usedPoints,
    });
    const methodLabelForSale = settlement.due > 0.009 && settlement.paid <= 0.009
      ? 'Credit'
      : settlement.payments
          .map((payment) => paymentMethods.find((method) => method.id === payment.method)?.label || payment.method)
          .join(' + ') || 'Credit';
    const db = await database();
    await db.withTransactionAsync(async () => {
      await db.runAsync(
        `INSERT INTO sales (client_uuid, device_ref, location_id, contact_id, total, total_paid, payment_method, transaction_date, sync_state, latitude, longitude, accuracy, cashier, discount)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)`,
        clientUuid,
        deviceRef,
        Number(locationId),
        customer.id,
        String(total),
        String(settlement.paid),
        methodLabelForSale,
        transactionDate,
        place ? String(place.latitude) : null,
        place ? String(place.longitude) : null,
        place?.accuracy == null ? null : String(place.accuracy),
        cashier,
        String(discountTaken)
      );
      for (const payment of settlement.payments) {
        await db.runAsync(
          `INSERT INTO sale_payments (client_uuid, method, amount, tendered) VALUES (?, ?, ?, ?)`,
          clientUuid,
          payment.method,
          String(payment.amount),
          String(payment.tendered)
        );
      }
      if (settlement.due > 0.009) {
        await db.runAsync(
          'UPDATE customers SET amount_due = amount_due + ? WHERE id = ?',
          settlement.due,
          customer.id
        );
      }
      if (usedPoints > 0) {
        await db.runAsync(
          'UPDATE customers SET reward_points = MAX(reward_points - ?, 0) WHERE id = ?',
          usedPoints,
          customer.id
        );
      }
      for (const line of lines) {
        await db.runAsync(
          `INSERT INTO sale_lines (client_uuid, product_id, variation_id, quantity, unit_price, name, price_override)
           VALUES (?, ?, ?, ?, ?, ?, ?)`,
          clientUuid,
          line.product_id,
          line.variation_id,
          String(line.quantity),
          String(line.unit_price),
          line.name,
          line.price_override ? 1 : 0
        );
        await db.runAsync(
          'UPDATE products SET qty_available = qty_available - ? WHERE variation_id = ? AND enable_stock = 1',
          line.quantity,
          line.variation_id
        );
      }
      await queue(db, clientUuid, 'sale.create', payload, cashier);
    });
    setCart([]);
    setDiscount({ type: 'fixed', amount: 0 });
    setPoints(0);
    setStage('register');
    setReceipt({
      clientUuid,
      deviceRef,
      total,
      subtotal,
      discount: discountTaken,
      transactionDate,
      customerName: customer.name,
      methodLabel: methodLabelForSale,
      lines,
      payments: settlement.payments,
      change: settlement.change,
      due: settlement.due,
      paid: settlement.paid,
      invoiceNo: null,
      serverId: null,
      syncState: 'pending',
    });
    await refreshLocal();
    await syncReceiptSale(clientUuid);
  }

  async function syncReceiptSale(clientUuid) {
    if (!clientUuid || syncingRef.current) {
      return;
    }
    syncingRef.current = true;
    setSyncing(true);
    setError('');
    try {
      await push();
      const db = await database();
      const sale = await db.getFirstAsync('SELECT * FROM sales WHERE client_uuid = ?', clientUuid);
      setReceipt((current) => {
        if (!current || current.clientUuid !== clientUuid) {
          return current;
        }
        return {
          ...current,
          invoiceNo: sale?.invoice_no || null,
          serverId: sale?.server_id || null,
          syncState: sale?.sync_state || 'pending',
        };
      });
      if (!sale || sale.sync_state !== 'synced') {
        setError(
          sale?.error ||
            (Number(sale?.contact_id) < 0
              ? 'This sale waits for its new customer to reach TeamPOS.'
              : 'TeamPOS did not accept this sale.')
        );
      } else if (locationId) {
        await pullLocation(locationId);
        await refreshLocal();
      }
    } catch (syncError) {
      setError(syncError.message);
    } finally {
      syncingRef.current = false;
      setSyncing(false);
    }
  }

  async function printDocument(sale) {
    if (printing) {
      return;
    }
    setPrinting(true);
    setError('');
    try {
      let layout = {};
      try {
        layout = JSON.parse((await metaGet('receipt_layout')) || '{}');
      } catch {
        layout = {};
      }
      if (!layout.display_name) {
        layout = {
          ...layout,
          display_name: [businessName, locationName].filter(Boolean).join(', '),
          invoice_heading: layout.invoice_heading || 'Invoice',
        };
      }
      await Print.printAsync({
        html: receiptHtml({ sale, layout, methods: paymentMethods }),
      });
    } catch (printError) {
      setError(printError.message || 'Could not open the print sheet.');
    } finally {
      setPrinting(false);
    }
  }

  async function printSavedReceipt() {
    await printDocument({
      invoiceNo: receipt?.invoiceNo,
      deviceRef: receipt?.deviceRef,
      customerName: receipt?.customerName,
      whenLabel: prettyDate(receipt?.transactionDate),
      total: receipt?.total,
      paid: receipt?.paid,
      due: receipt?.due,
      change: receipt?.change,
      lines: receipt?.lines || [],
      payments: receipt?.payments || [],
    });
  }

  async function onSync() {
    if (syncingRef.current) {
      return;
    }
    syncingRef.current = true;
    setError('');
    setSyncing(true);
    try {
      await push();
      if (locationId) {
        await pullLocation(locationId);
      }
      await refreshLocal();
    } catch (syncError) {
      setError(syncError.message);
    } finally {
      syncingRef.current = false;
      setSyncing(false);
    }
  }

  const wasOnline = useRef(null);
  useEffect(() => {
    if (wasOnline.current === false && online && locationId) {
      onSync();
    }
    wasOnline.current = online;
  }, [online, locationId]);

  const registerAway =
    registerIsOpen(register) && register.location_id != null && Number(register.location_id) !== Number(locationId);
  const registerOpen = registerIsOpen(register) && !registerAway;
  const registerShown = registerOpen || registerAway ? register : { open: false };

  const refreshRegister = useCallback(async () => {
    try {
      setRegister(await fetchRegister());
    } catch {
      // Offline or signed out: keep the last known state.
    }
  }, []);

  useEffect(() => {
    if (online && locationId && (tab === 'sell' || tab === 'account')) {
      refreshRegister();
    }
  }, [online, locationId, tab, refreshRegister]);

  useEffect(() => {
    if (online && locationId && tab === 'account') {
      rememberBranches().catch(() => {});
    }
  }, [online, locationId, tab]);

  useEffect(() => {
    if (!locationId) {
      return undefined;
    }
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        refreshRegister();
      }
    });
    return () => subscription.remove();
  }, [locationId, refreshRegister]);

  useEffect(() => {
    remindToCloseRegister(Boolean(locationId) && registerIsOpen(register));
  }, [locationId, register]);

  const lastBack = useRef(0);
  useEffect(() => {
    if (Platform.OS !== 'android') {
      return undefined;
    }
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (!locationId || booting || opening) {
        return false;
      }
      if (overlay) {
        setOverlay(null);
        setError('');
      } else if (saleView) {
        setSaleView(null);
        setError('');
      } else if (receipt) {
        setReceipt(null);
      } else if (stage === 'customers') {
        setStage('confirm');
      } else if (stage === 'confirm') {
        setError('');
        setStage('register');
      } else if (tab === 'customers' && customerView) {
        setCustomerView(null);
      } else if (tab !== 'home') {
        setTab('home');
      } else if (Date.now() - lastBack.current < 2000) {
        return false;
      } else {
        lastBack.current = Date.now();
        ToastAndroid.show('Press back again to close the app', ToastAndroid.SHORT);
      }
      return true;
    });
    return () => subscription.remove();
  }, [locationId, booting, opening, overlay, saleView, receipt, stage, tab, customerView]);

  const shownProducts = useMemo(
    () => visibleProducts(products, query, stockFilter),
    [products, query, stockFilter]
  );
  const stockCounts = useMemo(() => filterCounts(products, query), [products, query]);
  const qtyById = useMemo(() => {
    const map = {};
    for (const line of cart) {
      map[line.variation_id] = line.quantity;
    }
    return map;
  }, [cart]);
  const pendingCount = sales.filter((sale) => sale.sync_state !== 'synced').length;
  const history = useMemo(() => {
    const knownInvoices = new Set(sales.map((sale) => sale.invoice_no).filter(Boolean));
    const local = sales.map((sale) => ({
      key: sale.client_uuid,
      title: sale.customer_name || 'Walk-in',
      subtitle: sale.invoice_no || sale.device_ref,
      when: sale.transaction_date,
      whenLabel: prettyDate(sale.transaction_date),
      amount: sale.total,
      paid: sale.total_paid ?? sale.total,
      returned: Number(sale.total_returned || 0) + (returnsWaiting[sale.server_id]?.value || 0),
      serverTotal: sale.totals_differ ? sale.server_final_total : null,
      cashier: sale.cashier,
      status: sale.error
        ? 'attention'
        : sale.sync_state !== 'synced'
          ? 'pending'
          : paymentStatus(sale.total, sale.total_paid ?? sale.total),
      detail: sale.error || '',
      clientUuid: sale.client_uuid,
      serverId: sale.server_id,
      contactId: sale.contact_id,
      latitude: sale.latitude,
      longitude: sale.longitude,
      accuracy: sale.accuracy,
    }));
    const remote = serverSales
      .filter((sale) => !knownInvoices.has(sale.invoice_no))
      .map((sale) => ({
        key: `server-${sale.id}`,
        title: sale.customer_name || 'Sale',
        subtitle: sale.invoice_no || `Sale ${sale.id}`,
        when: sale.transaction_date,
        whenLabel: prettyDate(sale.transaction_date),
        amount: sale.final_total,
        paid: sale.total_paid ?? (sale.payment_status === 'paid' ? sale.final_total : 0),
        returned: Number(sale.total_returned || 0) + (returnsWaiting[sale.id]?.value || 0),
        status: sale.payment_status === 'partial' ? 'partial' : sale.payment_status === 'paid' ? 'paid' : 'due',
        detail: '',
        clientUuid: null,
        serverId: sale.id,
        contactId: sale.contact_id,
      }));
    return [...local, ...remote].sort((a, b) => String(b.when).localeCompare(String(a.when)));
  }, [sales, serverSales, returnsWaiting]);
  const todayKey = dayKey(new Date());
  const todaySales = history.filter((item) => dayKey(item.when) === todayKey);
  const todayTotal = todaySales.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const todayPaid = todaySales.reduce((sum, item) => sum + appliedPaid(item), 0);
  const todayDue = todaySales.reduce((sum, item) => sum + Math.max(0, Number(item.amount || 0) - appliedPaid(item)), 0);
  const stockCount = products.reduce((sum, product) => {
    const qty = Number(product.qty_available);
    return sum + (Number.isFinite(qty) && qty > 0 ? qty : 0);
  }, 0);
  const week = weekReview(history, new Date());

  async function openSale(item) {
    setError('');
    setSaleView({ ...item, lines: [], payments: [], loading: true });
    const db = await database();
    if (item.clientUuid) {
      const lines = await db.getAllAsync(
        'SELECT name, quantity, unit_price, variation_id FROM sale_lines WHERE client_uuid = ?',
        item.clientUuid
      );
      const payments = await db.getAllAsync(
        'SELECT method, amount, tendered FROM sale_payments WHERE client_uuid = ?',
        item.clientUuid
      );
      setSaleView((current) =>
        current && current.key === item.key ? { ...current, lines, payments, loading: !item.serverId } : current
      );
    }
    if (!item.serverId) {
      return;
    }
    const waiting = (await pendingReturns())[item.serverId] || { value: 0, lines: {} };
    const withWaiting = (lines) =>
      lines.map((line) =>
        waiting.lines[line.sell_line_id]
          ? { ...line, quantity_returned: Number(line.quantity_returned || 0) + waiting.lines[line.sell_line_id] }
          : line
      );
    try {
      const detail = await fetchSale(item.serverId);
      setSaleView((current) =>
        current && current.key === item.key
          ? {
              ...current,
              lines: withWaiting(detail.lines || []),
              payments: detail.payments || [],
              paid: detail.total_paid,
              returned: Number(detail.total_returned || 0) + waiting.value,
              discountAmount:
                detail.discount_type === 'percentage'
                  ? (Number(detail.discount_amount) / 100) *
                    (detail.lines || []).reduce((sum, line) => sum + Number(line.quantity) * Number(line.unit_price), 0)
                  : Number(detail.discount_amount || 0),
              pointsAmount: Number(detail.rp_redeemed_amount || 0),
              status:
                detail.payment_status === 'paid'
                  ? 'paid'
                  : detail.payment_status === 'partial'
                    ? 'partial'
                    : 'due',
              latitude: detail.latitude ?? current.latitude,
              longitude: detail.longitude ?? current.longitude,
              accuracy: detail.accuracy ?? current.accuracy,
              loading: false,
            }
          : current
      );
    } catch (loadError) {
      const lines = withWaiting(
        await db.getAllAsync('SELECT * FROM server_sale_lines WHERE sale_id = ?', item.serverId)
      );
      const payments = await db.getAllAsync('SELECT * FROM server_sale_payments WHERE sale_id = ?', item.serverId);
      setSaleView((current) =>
        current && current.key === item.key
          ? {
              ...current,
              lines: lines.some((line) => line.sell_line_id) || !current.lines?.length ? lines : current.lines,
              payments: current.payments?.length ? current.payments : payments,
              loading: false,
              detail: lines.length || current.lines?.length ? '' : 'Connect to load the items on this sale.',
            }
          : current
      );
      if (!lines.length) {
        setError(loadError.message);
      }
    }
  }

  async function recordPayment(amount, method) {
    if (!saleView) {
      return;
    }
    const due = Number(saleView.amount) - Number(saleView.returned || 0) - Number(saleView.paid || 0);
    if (!(amount > 0) || amount - due > 0.009) {
      setError('Enter an amount up to the balance due.');
      return;
    }
    const clientUuid = newClientUuid();
    const payload = {
      type: 'payment.add',
      client_uuid: clientUuid,
      sale_client_uuid: saleView.clientUuid || null,
      transaction_id: saleView.serverId || null,
      method,
      amount,
    };
    const db = await database();
    await queue(db, clientUuid, 'payment.add', payload, await metaGet('username'));
    if (saleView.clientUuid) {
      await db.runAsync(
        'UPDATE sales SET total_paid = COALESCE(total_paid, 0) + ? WHERE client_uuid = ?',
        amount,
        saleView.clientUuid
      );
      await db.runAsync(
        'INSERT INTO sale_payments (client_uuid, method, amount, tendered) VALUES (?, ?, ?, ?)',
        saleView.clientUuid,
        method,
        String(amount),
        String(amount)
      );
    }
    if (saleView.contactId) {
      await db.runAsync(
        'UPDATE customers SET amount_due = MAX(amount_due - ?, 0) WHERE id = ?',
        amount,
        saleView.contactId
      );
    }
    setSaleView((current) =>
      current
        ? {
            ...current,
            paid: Number(current.paid || 0) + amount,
            status: paymentStatus(current.amount, Number(current.paid || 0) + amount),
          }
        : current
    );
    await refreshLocal();
    if (online) {
      await onSync();
    }
  }

  async function payCustomer(customer, amount, method) {
    if (!(amount > 0)) {
      setError('Enter an amount greater than zero.');
      return;
    }
    const dueSales = history
      .filter((sale) => Number(sale.contactId) === Number(customer.id))
      .map((sale) => ({ ...sale, due: Number(sale.amount) - Number(sale.paid || 0) }))
      .filter((sale) => sale.due > 0.009)
      .sort((a, b) => String(a.when).localeCompare(String(b.when)));
    let left = amount;
    const db = await database();
    for (const sale of dueSales) {
      if (left <= 0.009) {
        break;
      }
      const slice = Math.min(left, sale.due);
      const clientUuid = newClientUuid();
      await queue(
        db,
        clientUuid,
        'payment.add',
        {
          type: 'payment.add',
          client_uuid: clientUuid,
          sale_client_uuid: sale.clientUuid || null,
          transaction_id: sale.serverId || null,
          method,
          amount: slice,
        },
        await metaGet('username')
      );
      if (sale.clientUuid) {
        await db.runAsync(
          'UPDATE sales SET total_paid = COALESCE(total_paid, 0) + ? WHERE client_uuid = ?',
          slice,
          sale.clientUuid
        );
        await db.runAsync(
          'INSERT INTO sale_payments (client_uuid, method, amount, tendered) VALUES (?, ?, ?, ?)',
          sale.clientUuid,
          method,
          String(slice),
          String(slice)
        );
      }
      if (sale.serverId) {
        await db.runAsync(
          'UPDATE server_sales SET total_paid = COALESCE(total_paid, 0) + ? WHERE id = ?',
          slice,
          sale.serverId
        );
      }
      left -= slice;
    }
    const dueNow = Number(customer.amount_due) || 0;
    const extra = Math.max(0, amount - dueNow);
    if (left > 0.009) {
      const advanceId = newClientUuid();
      await queue(
        db,
        advanceId,
        'payment.advance',
        {
          type: 'payment.advance',
          client_uuid: advanceId,
          contact_id: customer.id,
          location_id: locationId,
          method,
          amount: Math.round(left * 100) / 100,
        },
        await metaGet('username')
      );
    }
    await db.runAsync(
      'UPDATE customers SET amount_due = MAX(amount_due - ?, 0) WHERE id = ?',
      Math.min(amount, dueNow),
      customer.id
    );
    if (extra > 0.009) {
      await db.runAsync(
        'UPDATE customers SET advance = COALESCE(advance, 0) + ? WHERE id = ?',
        extra,
        customer.id
      );
    }
    await refreshLocal();
    if (online) {
      await onSync();
    }
  }

  async function onLogout() {
    await logout();
    await signOutLocally();
  }

  async function signOutLocally() {
    await metaDelete('location_id');
    setLocationId(null);
    setLocations([]);
    setPassword('');
    setCart([]);
    setDiscount({ type: 'fixed', amount: 0 });
    setPoints(0);
    setReceipt(null);
    setSaleView(null);
    setCustomerView(null);
    setOverlay(null);
    setStage('register');
    setTab('home');
  }

  async function runOnline(work) {
    if (!online) {
      setError('This needs a connection to TeamPOS.');
      return null;
    }
    setBusy(true);
    setError('');
    try {
      return await work();
    } catch (workError) {
      setError(workError.message);
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function submitReturn({ method, lines }) {
    const sale = overlay?.sale;
    if (!sale?.serverId) {
      setError('This sale has to reach TeamPOS before items can come back.');
      return;
    }
    const byLine = Object.fromEntries((sale.lines || []).map((line) => [line.sell_line_id, line]));
    const itemsTotal = (sale.lines || []).reduce((sum, line) => sum + Number(line.quantity) * Number(line.unit_price), 0);
    const share = itemsTotal > 0 ? Math.max(0, itemsTotal - Number(sale.discountAmount || 0)) / itemsTotal : 1;
    const gross = lines.reduce((sum, line) => sum + line.quantity * Number(byLine[line.sell_line_id]?.unit_price || 0), 0);
    const value = Math.round(gross * share * 100) / 100;
    const owed = Number(sale.paid || 0) - (Number(sale.amount || 0) - Number(sale.returned || 0) - value);
    const refund = Math.max(0, Math.min(value, owed));
    const stock = lines
      .map((line) => ({ variation_id: byLine[line.sell_line_id]?.variation_id, quantity: line.quantity }))
      .filter((item) => item.variation_id);
    setBusy(true);
    setError('');
    try {
      await saveReturnLocally({
        clientUuid: newClientUuid(),
        cashier: await metaGet('username'),
        payload: { transaction_id: sale.serverId, refund_method: method, lines },
        local: { value, refund, stock, location_id: locationId },
      });
    } catch (returnError) {
      setError(returnError.message);
      return;
    } finally {
      setBusy(false);
    }
    setOverlay(null);
    const later = online ? '' : ' It goes to TeamPOS when this phone is back online.';
    const notice =
      refund > 0.009
        ? `Return saved. Hand back ${money(refund)}.${later}`
        : `Return saved. It lowered the balance, so no cash goes back.${later}`;
    await refreshLocal();
    if (online) {
      await onSync();
    }
    await openSale({ ...sale, returned: Number(sale.returned || 0) + value, notice });
  }

  async function submitCustomer(fields) {
    const editing = overlay?.customer;
    const pick = overlay?.pick;
    const number = String(fields.mobile || '').replace(/\D/g, '');
    const clash = customers.find(
      (person) => person.id !== editing?.id && number && String(person.mobile || '').replace(/\D/g, '') === number
    );
    if (clash) {
      setError(`${clash.name || clash.business_name || 'Another customer'} already uses this number.`);
      return;
    }
    setBusy(true);
    setError('');
    let saved;
    try {
      saved = await saveCustomerLocally({
        fields,
        editing,
        clientUuid: newClientUuid(),
        cashier: await metaGet('username'),
      });
    } catch (customerError) {
      setError(customerError.message);
      return;
    } finally {
      setBusy(false);
    }
    setOverlay(null);
    await refreshLocal();
    if (pick) {
      setCustomerId(saved.id);
      setStage('confirm');
    } else {
      setCustomerView(saved.id);
    }
    if (online) {
      await onSync();
    }
  }

  async function switchBranch(id) {
    if (Number(id) === Number(locationId)) {
      return;
    }
    if (!online) {
      setError('Switching branch needs a connection to TeamPOS.');
      return;
    }
    await chooseLocation(id);
    await refreshRegister();
  }

  async function showDrawer() {
    setError('');
    setOverlay({ kind: 'drawer' });
    if (online) {
      try {
        setRegister(await fetchRegister());
      } catch (drawerError) {
        setError(drawerError.message);
      }
    }
  }

  async function startRegister(openingCash) {
    const summary = await runOnline(() => openRegister(locationId, openingCash));
    if (summary) {
      setRegister(summary);
      return;
    }
    await refreshRegister();
  }

  async function endRegister(closingCash, note) {
    const result = await runOnline(() => closeRegister(closingCash, note));
    if (result) {
      setRegister({ open: false });
    }
    return result;
  }

  if (booting || opening) {
    return <Splash title={opening ? 'Opening the register' : 'Cashier'} />;
  }

  if (!locationId) {
    return (
      <SignIn
        username={username}
        password={password}
        onUsername={setUsername}
        onPassword={setPassword}
        onSubmit={onLogin}
        locations={locations}
        onLocation={chooseLocation}
        error={error}
      />
    );
  }

  if (overlay?.kind === 'return') {
    return (
      <SaleReturn
        sale={overlay.sale}
        methods={paymentMethods}
        busy={busy}
        error={error}
        online={online}
        onBack={() => {
          setOverlay(null);
          setError('');
        }}
        onSubmit={submitReturn}
      />
    );
  }

  if (overlay?.kind === 'customer') {
    return (
      <CustomerForm
        customer={overlay.customer}
        busy={busy}
        error={error}
        online={online}
        onBack={() => {
          setOverlay(null);
          setError('');
        }}
        onSubmit={submitCustomer}
      />
    );
  }

  if (overlay?.kind === 'drawer') {
    return (
      <Drawer
        register={registerShown}
        methods={paymentMethods}
        canClose={settings.can_close_register !== false}
        busy={busy}
        error={error}
        online={online}
        locationName={registerAway ? register.location_name : locationName}
        onBack={() => {
          setOverlay(null);
          setError('');
        }}
        onOpen={startRegister}
        onClose={endRegister}
      />
    );
  }

  if (saleView) {
    return (
      <SaleView
        sale={saleView}
        methods={paymentMethods}
        syncing={syncing}
        printing={printing}
        error={error}
        onBack={() => {
          setSaleView(null);
          setError('');
        }}
        onPay={recordPayment}
        canPay={settings.can_take_payments !== false}
        canReturn={Boolean(settings.can_return)}
        onReturn={() => {
          setError('');
          setOverlay({ kind: 'return', sale: saleView });
        }}
        online={online}
        onPrint={() =>
          printDocument({
            invoiceNo: saleView.serverId ? saleView.subtitle : null,
            deviceRef: saleView.subtitle,
            customerName: saleView.title,
            whenLabel: saleView.whenLabel,
            total: saleView.amount,
            paid: saleView.paid,
            lines: saleView.lines || [],
            payments: saleView.payments || [],
          })
        }
      />
    );
  }

  if (receipt) {
    return (
      <Receipt
        receipt={receipt}
        locationName={locationName}
        syncing={syncing}
        printing={printing}
        error={error}
        onDone={() => setReceipt(null)}
        onSync={() => syncReceiptSale(receipt.clientUuid)}
        onPrint={printSavedReceipt}
        online={online}
      />
    );
  }

  if (stage === 'customers') {
    return (
      <Customers
        customers={customers}
        selectedId={customerId}
        onBack={() => setStage('confirm')}
        onAdd={settings.can_add_customer ? () => setOverlay({ kind: 'customer', pick: true }) : null}
        onSelect={(id) => {
          setCustomerId(id);
          setPoints(0);
          setStage('confirm');
        }}
      />
    );
  }

  if (stage === 'confirm') {
    return (
      <Checkout
        cart={cart}
        products={products}
        customer={selectedCustomer}
        methods={paymentMethods}
        tenders={tenders}
        onTenders={setTenders}
        discount={discount}
        onDiscount={setDiscount}
        points={points}
        onPoints={setPoints}
        settings={settings}
        saving={saving}
        error={error}
        onBack={() => {
          setError('');
          setStage('register');
        }}
        onCustomer={() => setStage('customers')}
        onQuantity={changeLine}
        onPrice={changePrice}
        onSave={finishSale}
        online={online}
      />
    );
  }

  return (
    <View style={styles.fill}>
      {tab === 'home' ? (
        <Home
          businessName={businessName}
          locationName={locationName}
          online={online}
          todayTotal={todayTotal}
          dueTotal={todayDue}
          stockCount={stockCount}
          paymentsTotal={todayPaid}
          week={week}
          pending={pendingCount}
          syncing={syncing}
          onSync={onSync}
          error={error}
        />
      ) : null}
      {tab === 'sell' && !registerOpen ? (
        <RegisterGate
          businessName={businessName}
          locationName={locationName}
          online={online}
          busy={busy}
          error={error}
          onOpen={startRegister}
          openElsewhere={registerAway ? register.location_name || 'another branch' : null}
          onCloseElsewhere={showDrawer}
        />
      ) : null}
      {tab === 'sell' && registerOpen ? (
        <Register
          businessName={businessName}
          locationName={locationName}
          online={online}
          query={query}
          onQuery={setQuery}
          filter={stockFilter}
          onFilter={setStockFilter}
          counts={stockCounts}
          products={shownProducts}
          quantities={qtyById}
          onAdd={addToCart}
          syncing={syncing}
          onSync={onSync}
          error={error}
        />
      ) : null}
      {tab === 'sales' ? (
        <Transactions
          items={history}
          week={week}
          query={saleQuery}
          onQuery={setSaleQuery}
          filter={saleFilter}
          onFilter={setSaleFilter}
          onOpen={openSale}
          online={online}
          error={error}
        />
      ) : null}
      {tab === 'customers' ? (
        <CustomerList
          customers={customers}
          sales={history}
          selected={customerView}
          onSelect={setCustomerView}
          onOpen={openSale}
          online={online}
          methods={paymentMethods}
          syncing={syncing}
          onPay={payCustomer}
          canPay={settings.can_take_payments !== false}
          onAdd={settings.can_add_customer ? () => setOverlay({ kind: 'customer' }) : null}
          onEdit={settings.can_edit_customer ? (person) => setOverlay({ kind: 'customer', customer: person }) : null}
          rewardsName={settings.rewards?.name}
          error={error}
        />
      ) : null}
      {tab === 'account' ? (
        <Account
          businessName={businessName}
          locationName={locationName}
          username={cashierName}
          deviceCode={deviceCode}
          shiftCount={todaySales.length}
          shiftTotal={todayTotal}
          pending={pendingCount}
          lastSync={lastSync}
          online={online}
          syncing={syncing}
          onSync={onSync}
          onLogout={onLogout}
          register={registerShown}
          registerAway={registerAway}
          onDrawer={showDrawer}
          branches={branches}
          locationId={locationId}
          onSwitchBranch={switchBranch}
          error={error}
        />
      ) : null}
      {tab === 'sell' && registerOpen && count > 0 ? (
        <CartBar
          count={count}
          total={total}
          onNext={() => {
            const choices = paymentMethods.length > 0 ? paymentMethods : [{ id: 'cash', label: 'Cash' }];
            const cashId = choices.some((method) => method.id === 'cash') ? 'cash' : choices[0].id;
            setTenders([{ method: cashId, received: total.toFixed(2) }]);
            setError('');
            setStage('confirm');
          }}
        />
      ) : null}
      <TabBar tab={tab} pending={pendingCount} onChange={setTab} />
    </View>
  );
}

function Splash({ title }) {
  return (
    <View style={styles.center}>
      <Image source={logo} style={styles.splashLogo} resizeMode="contain" accessibilityLabel="TeamPOS" />
      <ActivityIndicator color={colors.accent} style={styles.spinner} />
      <Text style={styles.splashText}>{title}</Text>
    </View>
  );
}

function SignIn({ username, password, onUsername, onPassword, onSubmit, locations, onLocation, error }) {
  const [showPassword, setShowPassword] = useState(false);
  const passwordRef = useRef(null);
  const ready = Boolean(username.trim() && password);
  const choosing = locations.length > 0;
  return (
    <KeyboardAvoidingView style={styles.fill} behavior="padding">
      <ScrollView
        contentContainerStyle={styles.signIn}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="interactive"
      >
        <Image source={logo} style={styles.signInLogo} resizeMode="contain" accessibilityLabel="TeamPOS" />
        <Text style={styles.signInTitle}>{choosing ? 'Choose a location' : 'Welcome back'}</Text>
        <Text style={styles.signInLede}>
          {choosing ? 'Pick the shop you are selling from today.' : 'Sign in with your TeamPOS account.'}
        </Text>
        <Banner message={error} />
        {!choosing ? (
          <View style={styles.signInForm}>
            <View style={styles.signInField}>
              <User color={colors.muted} size={18} strokeWidth={2} />
              <TextInput
                value={username}
                onChangeText={onUsername}
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="username"
                textContentType="username"
                returnKeyType="next"
                onSubmitEditing={() => passwordRef.current?.focus()}
                placeholder="Username"
                placeholderTextColor={colors.faint}
                style={styles.signInInput}
                accessibilityLabel="Username"
              />
            </View>
            <View style={styles.signInField}>
              <Lock color={colors.muted} size={18} strokeWidth={2} />
              <TextInput
                ref={passwordRef}
                value={password}
                onChangeText={onPassword}
                secureTextEntry={!showPassword}
                autoComplete="password"
                textContentType="password"
                returnKeyType="go"
                onSubmitEditing={() => (ready ? onSubmit() : null)}
                placeholder="Password"
                placeholderTextColor={colors.faint}
                style={styles.signInInput}
                accessibilityLabel="Password"
              />
              <Pressable
                onPress={() => setShowPassword((value) => !value)}
                hitSlop={10}
                accessibilityRole="button"
                accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
              >
                {showPassword ? (
                  <EyeOff color={colors.muted} size={18} strokeWidth={2} />
                ) : (
                  <Eye color={colors.muted} size={18} strokeWidth={2} />
                )}
              </Pressable>
            </View>
            <View style={styles.signInAction}>
              <PrimaryButton label="Sign in" onPress={onSubmit} disabled={!ready} />
            </View>
          </View>
        ) : (
          <View style={styles.signInForm}>
            {locations.map((location) => (
              <Pressable
                key={location.id}
                onPress={() => onLocation(location.id)}
                accessibilityRole="button"
                style={({ pressed }) => [styles.signInLocation, pressed && styles.pressed]}
              >
                <View style={styles.locationIcon}>
                  <MapPin color={colors.accent} size={18} strokeWidth={2} />
                </View>
                <View style={styles.fill}>
                  <Text style={styles.locationName}>{location.name}</Text>
                  <Text style={styles.locationHint}>Open this register</Text>
                </View>
                <ChevronRight color={colors.faint} size={20} strokeWidth={2} />
              </Pressable>
            ))}
          </View>
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function Home({
  businessName,
  locationName,
  online,
  todayTotal,
  dueTotal,
  stockCount,
  paymentsTotal,
  week,
  pending,
  syncing,
  onSync,
  error,
}) {
  const spin = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (!syncing) {
      spin.setValue(0);
      return undefined;
    }
    const loop = Animated.loop(
      Animated.timing(spin, { toValue: 1, duration: 700, easing: Easing.linear, useNativeDriver: true })
    );
    loop.start();
    return () => loop.stop();
  }, [spin, syncing]);
  const rotate = spin.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] });
  return (
    <View style={styles.fill}>
      <ScreenHeader title={businessName || 'Home'} subtitle={locationName} online={online} />
      <ScrollView style={styles.fill} contentContainerStyle={styles.homeScroll} showsVerticalScrollIndicator={false}>
        <Banner message={error} />
        <Pressable
          onPress={onSync}
          disabled={syncing}
          accessibilityRole="button"
          accessibilityLabel="Waiting to sync"
          style={({ pressed }) => [styles.syncRow, pressed && styles.pressed]}
        >
          <Animated.View style={{ transform: [{ rotate }] }}>
            <RefreshCw color={colors.ink} size={18} strokeWidth={2.25} />
          </Animated.View>
          <Text style={styles.syncRowLabel}>{syncing ? 'Syncing' : 'Waiting to sync'}</Text>
          <Text style={[styles.syncCount, pending > 0 && styles.syncCountOn]}>{pending}</Text>
        </Pressable>
        <View style={styles.homeRow}>
          <HomeCard icon={Banknote} tint={colors.accentSoft} color={colors.accent} label="Day's sale" value={money(todayTotal)} />
          <HomeCard icon={Clock} tint={colors.amberSoft} color={colors.amber} label="Due today" value={money(dueTotal)} />
        </View>
        <View style={styles.homeRow}>
          <HomeCard icon={Croissant} tint={colors.greenSoft} color={colors.green} label="Loaves left" value={formatCount(stockCount)} />
          <HomeCard icon={Wallet} tint={colors.accentSoft} color={colors.accent} label="Paid today" value={money(paymentsTotal)} />
        </View>
        <WeekChart week={week} />
      </ScrollView>
    </View>
  );
}

function HomeCard({ icon: Icon, tint, color, label, value }) {
  return (
    <View style={styles.homeCard}>
      <View style={[styles.homeIcon, { backgroundColor: tint }]}>
        <Icon color={color} size={18} strokeWidth={2.25} />
      </View>
      <Text style={styles.homeCardLabel}>{label}</Text>
      <Text style={styles.homeCardValue} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
    </View>
  );
}

function WeekChart({ week }) {
  const peak = week.days.reduce((highest, day) => Math.max(highest, day.total), 0);
  const salesLabel = week.count === 1 ? '1 sale' : `${week.count} sales`;
  return (
    <View style={styles.chartCard}>
      <View style={styles.chartHead}>
        <View>
          <Text style={styles.chartTitle}>Week in review</Text>
          <Text style={styles.chartCaption}>{week.range}</Text>
        </View>
        <View style={styles.chartTotals}>
          <Text style={styles.chartTotal}>{money(week.total)}</Text>
          <Text style={styles.chartCaption}>{salesLabel}</Text>
        </View>
      </View>
      <View style={styles.chartBars}>
        {week.days.map((day) => {
          const height = day.total > 0 && peak > 0 ? Math.max(8, Math.round((day.total / peak) * 88)) : 0;
          return (
            <View key={day.key} style={styles.chartCol} accessibilityLabel={`${day.name} ${money(day.total)}`}>
              <View style={[styles.chartTrack, day.future && styles.chartTrackFuture]}>
                {height > 0 ? (
                  <View style={[styles.chartBar, day.today ? styles.chartBarToday : null, { height }]} />
                ) : null}
              </View>
              <Text style={[styles.chartDay, day.today && styles.chartDayToday]}>{day.short}</Text>
            </View>
          );
        })}
      </View>
      <View style={styles.chartFoot}>
        <View style={styles.chartStat}>
          <Text style={styles.chartStatLabel}>Best day</Text>
          <Text style={styles.chartStatValue}>{week.best ? `${week.best.name} · ${money(week.best.total)}` : 'No sales yet'}</Text>
        </View>
        <View style={[styles.chartStat, styles.chartStatEnd]}>
          <Text style={styles.chartStatLabel}>Daily average</Text>
          <Text style={styles.chartStatValue}>{money(week.average)}</Text>
        </View>
      </View>
    </View>
  );
}

function outboxProblem(problem) {
  switch (problem.type) {
    case 'customer.create':
    case 'customer.update':
      return `TeamPOS did not save ${problem.name || 'a customer'}: ${problem.message} Edit the customer to fix it.`;
    case 'return.create':
      return `TeamPOS did not take a return: ${problem.message}`;
    case 'payment.add':
    case 'payment.advance':
      return `TeamPOS did not take a payment: ${problem.message}`;
    default:
      return `TeamPOS did not take a change from this phone: ${problem.message}`;
  }
}

function appliedPaid(item) {
  const amount = Math.max(0, Number(item.amount) || 0);
  const paid = Math.max(0, Number(item.paid) || 0);
  return Math.min(paid, amount);
}

const weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function filterCounts(products, query) {
  return {
    all: visibleProducts(products, query, 'all').length,
    stock: visibleProducts(products, query, 'stock').length,
    low: visibleProducts(products, query, 'low').length,
  };
}

function previewSales() {
  const now = new Date();
  const at = (daysAgo, hour, minute) =>
    localDateTime(new Date(now.getFullYear(), now.getMonth(), now.getDate() - daysAgo, hour, minute));
  const sample = [
    { key: '1', title: 'Walk-in', subtitle: 'C-A1B2C3-000041', when: at(0, 9, 42), amount: 45, paid: 45, status: 'pending' },
    {
      key: '2',
      title: 'Ama Mensah',
      subtitle: 'C0142',
      when: at(0, 8, 10),
      amount: 51,
      paid: 20,
      status: 'partial',
      serverId: 1,
      cashier: 'Cashiz',
      discountAmount: 2,
      latitude: '5.60370',
      longitude: '-0.18700',
      accuracy: '8',
      lines: [
        { sell_line_id: 1, name: 'Butter croissant', quantity: '4', quantity_returned: '1', unit_price: '8.5' },
        { sell_line_id: 2, name: 'Sourdough loaf', quantity: '1', quantity_returned: '0', unit_price: '19' },
      ],
      payments: [{ method: 'cash', amount: '20' }],
      returned: 0,
    },
    { key: '3', title: 'Bolt Food', subtitle: 'C0139', when: at(1, 16, 5), amount: 120, paid: 120, status: 'paid' },
    { key: '4', title: 'Kofi Boateng', subtitle: 'C0133', when: at(2, 11, 30), amount: 64, paid: 0, status: 'due' },
    { key: '5', title: 'Walk-in', subtitle: 'C0130', when: at(2, 10, 2), amount: 17, paid: 17, status: 'paid' },
  ];
  return sample.map((item) => ({ ...item, whenLabel: prettyDate(item.when) }));
}

function previewWeekSales() {
  const now = new Date();
  const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7));
  const amounts = [120, 86, 0, 142, 64, 178, 95];
  return amounts.map((amount, index) => ({
    when: new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + index, 10),
    amount,
  }));
}

function weekReview(sales, now) {
  const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7));
  const todayKey = dayKey(now);
  const days = weekdayNames.map((name, index) => {
    const date = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + index);
    const key = dayKey(date);
    return { key, name, short: name.slice(0, 3), today: key === todayKey, future: key > todayKey, total: 0, count: 0 };
  });
  const byKey = new Map(days.map((day) => [day.key, day]));
  for (const sale of sales) {
    const day = byKey.get(dayKey(sale.when));
    if (day) {
      day.total += Number(sale.amount || 0);
      day.count += 1;
    }
  }
  const total = days.reduce((sum, day) => sum + day.total, 0);
  const count = days.reduce((sum, day) => sum + day.count, 0);
  const elapsed = days.filter((day) => !day.future).length;
  const best = days.reduce((top, day) => (day.total > 0 && (!top || day.total > top.total) ? day : top), null);
  const sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 6);
  const range =
    monday.getMonth() === sunday.getMonth()
      ? `${monday.getDate()}–${sunday.getDate()} ${monthNames[sunday.getMonth()]}`
      : `${monday.getDate()} ${monthNames[monday.getMonth()]} – ${sunday.getDate()} ${monthNames[sunday.getMonth()]}`;
  return { days, total, count, best, average: elapsed > 0 ? total / elapsed : 0, range };
}

function formatCount(value) {
  const rounded = Math.round(Number(value) * 100) / 100;
  if (!Number.isFinite(rounded)) {
    return '0';
  }
  return Number.isInteger(rounded) ? String(rounded) : String(rounded);
}

function Register({
  businessName,
  locationName,
  online,
  query,
  onQuery,
  filter,
  onFilter,
  counts,
  products,
  quantities,
  onAdd,
  syncing,
  onSync,
  error,
}) {
  return (
    <View style={styles.fill}>
      <ScreenHeader
        title={businessName || locationName || 'Register'}
        subtitle={businessName ? locationName : ''}
        online={online}
        action={
          <Pressable onPress={onSync} disabled={syncing} hitSlop={8} accessibilityRole="button">
            <Text style={styles.syncLink}>{syncing ? 'Syncing' : 'Sync'}</Text>
          </Pressable>
        }
      />
      <Banner message={error} />
      <SearchField value={query} onChangeText={onQuery} placeholder="Search products" />
      <StockFilter value={filter} onChange={onFilter} counts={counts} />
      <FlatList
        style={styles.fill}
        data={products}
        keyExtractor={(item) => String(item.variation_id)}
        numColumns={2}
        columnWrapperStyle={styles.gridRow}
        contentContainerStyle={styles.grid}
        extraData={quantities}
        keyboardShouldPersistTaps="handled"
        ListEmptyComponent={
          <Text style={styles.empty}>
            {query ? 'No products match that search.' : 'No products on this device yet. Sync the register.'}
          </Text>
        }
        renderItem={({ item }) => (
          <ProductCard
            product={item}
            quantity={quantities[item.variation_id] || 0}
            onAdd={onAdd}
          />
        )}
      />
    </View>
  );
}

function Customers({ customers, selectedId, onBack, onSelect, onAdd }) {
  const [query, setQuery] = useState('');
  const rows = customers.filter((customer) => {
    const needle = query.trim().toLowerCase();
    if (!needle) {
      return true;
    }
    return `${customer.name} ${customer.business_name || ''} ${customer.mobile || ''}`.toLowerCase().includes(needle);
  });
  return (
    <SwipeBack onBack={onBack}>
      <ScreenHeader
        title="Customer"
        onBack={onBack}
        action={
          onAdd ? (
            <Pressable onPress={onAdd} hitSlop={8} accessibilityRole="button" accessibilityLabel="Add customer">
              <UserPlus color={colors.accent} size={22} strokeWidth={2.25} />
            </Pressable>
          ) : null
        }
      />
      <SearchField value={query} onChangeText={setQuery} placeholder="Search customers" />
      <FlatList
        style={styles.listGap}
        data={rows}
        keyExtractor={(item) => String(item.id)}
        keyboardShouldPersistTaps="handled"
        ListEmptyComponent={<Text style={styles.empty}>No customers on this device yet.</Text>}
        renderItem={({ item }) => {
          const selected = item.id === selectedId;
          return (
            <Pressable
              onPress={() => onSelect(item.id)}
              accessibilityRole="button"
              style={({ pressed }) => [styles.location, selected && styles.locationOn, pressed && styles.pressed]}
            >
              <Text style={styles.locationName}>{customerHeading(item).title}</Text>
              <Text style={styles.locationHint}>{customerHint(item)}</Text>
            </Pressable>
          );
        }}
      />
    </SwipeBack>
  );
}

function Account({
  businessName,
  locationName,
  username,
  deviceCode,
  shiftCount = 0,
  shiftTotal = 0,
  pending,
  lastSync,
  online = true,
  syncing,
  onSync,
  onLogout = () => {},
  register,
  registerAway = false,
  onDrawer,
  branches = [],
  locationId = null,
  onSwitchBranch = () => {},
  error,
}) {
  const [leaving, setLeaving] = useState(false);
  const [picking, setPicking] = useState(false);
  const canSwitch = branches.length > 1;
  const spin = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (!syncing) {
      spin.setValue(0);
      return undefined;
    }
    const loop = Animated.loop(
      Animated.timing(spin, { toValue: 1, duration: 800, easing: Easing.linear, useNativeDriver: true })
    );
    loop.start();
    return () => loop.stop();
  }, [spin, syncing]);
  const rotate = spin.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] });
  const name = username || 'Cashier';
  const initials = name
    .split(/[\s._-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
  const salesLabel = shiftCount === 1 ? 'sale' : 'sales';
  let syncTitle = 'All caught up';
  let syncHint = lastSync ? `Last synced ${prettyDate(lastSync)}` : 'Sync once to download products and customers.';
  let syncTone = styles.syncOk;
  let SyncIcon = CircleCheck;
  if (syncing) {
    syncTitle = 'Syncing';
    syncHint = 'Sending sales and pulling the latest stock.';
    syncTone = styles.syncBusy;
    SyncIcon = RefreshCw;
  } else if (!online) {
    syncTitle = pending > 0 ? `${pending} waiting · offline` : 'Offline';
    syncHint = 'Sales keep saving on this phone. They go up once you are back online.';
    syncTone = styles.syncOff;
    SyncIcon = CloudOff;
  } else if (pending > 0) {
    syncTitle = `${pending} waiting to sync`;
    syncTone = styles.syncWait;
    SyncIcon = RefreshCw;
  }
  const version = Constants.expoConfig?.version || '1.0.0';
  return (
    <ScrollView style={styles.fill} contentContainerStyle={styles.accountScroll} showsVerticalScrollIndicator={false}>
      <Banner message={error} />
      <View style={styles.hero}>
        <View style={[styles.heroOrb, styles.heroOrbOne]} />
        <View style={[styles.heroOrb, styles.heroOrbTwo]} />
        <View style={styles.heroTop}>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{initials || 'C'}</Text>
          </View>
          <View style={[styles.heroStatus, online ? styles.heroStatusOn : styles.heroStatusOff]}>
            <View style={[styles.heroDot, { backgroundColor: online ? '#4ADE80' : '#F87171' }]} />
            <Text style={styles.heroStatusText}>{online ? 'Online' : 'Offline'}</Text>
          </View>
        </View>
        <Text style={styles.heroName} numberOfLines={1}>
          {name}
        </Text>
        <Text style={styles.heroRole} numberOfLines={1}>
          Cashier{businessName ? ` · ${businessName}` : ''}
        </Text>
        <View style={styles.heroShop}>
          <MapPin color="#BFDBFE" size={14} strokeWidth={2.25} />
          <Text style={styles.heroShopText} numberOfLines={1}>
            {locationName || 'No shop open'}
          </Text>
        </View>
        <View style={styles.heroStats}>
          <View style={styles.heroStat}>
            <Text style={styles.heroStatValue}>{shiftCount}</Text>
            <Text style={styles.heroStatLabel}>{salesLabel} today</Text>
          </View>
          <View style={styles.heroDivider} />
          <View style={styles.heroStat}>
            <Text style={styles.heroStatValue} numberOfLines={1} adjustsFontSizeToFit>
              {money(shiftTotal)}
            </Text>
            <Text style={styles.heroStatLabel}>sold today</Text>
          </View>
        </View>
      </View>

      <Pressable
        onPress={onSync}
        disabled={syncing}
        accessibilityRole="button"
        accessibilityLabel="Sync now"
        style={({ pressed }) => [styles.syncCard, pressed && styles.pressed]}
      >
        <View style={[styles.syncBadge, syncTone]}>
          <Animated.View style={syncing ? { transform: [{ rotate }] } : null}>
            <SyncIcon color={colors.white} size={20} strokeWidth={2.25} />
          </Animated.View>
        </View>
        <View style={styles.fill}>
          <Text style={styles.syncTitle}>{syncTitle}</Text>
          <Text style={styles.syncHint}>{syncHint}</Text>
        </View>
        <Text style={styles.syncAction}>{syncing ? '' : 'Sync'}</Text>
      </Pressable>

      {onDrawer ? (
        <Pressable
          onPress={onDrawer}
          accessibilityRole="button"
          accessibilityLabel="Cash register"
          style={({ pressed }) => [styles.syncCard, styles.drawerCard, pressed && styles.pressed]}
        >
          <View style={[styles.syncBadge, register?.open ? styles.syncOk : styles.syncOff]}>
            {register?.open ? (
              <LockOpen color={colors.white} size={20} strokeWidth={2.25} />
            ) : (
              <Vault color={colors.white} size={20} strokeWidth={2.25} />
            )}
          </View>
          <View style={styles.fill}>
            <Text style={styles.syncTitle}>{register?.open ? 'Register open' : 'Register closed'}</Text>
            <Text style={styles.syncHint}>
              {!register?.open
                ? 'Open it with the float before the first sale.'
                : registerAway
                  ? `Open at ${register.location_name || 'another branch'} · close it to sell here`
                  : `${money(register.expected_cash)} cash expected · since ${prettyDate(register.opened_at)}`}
            </Text>
          </View>
          <Text style={styles.syncAction}>{register?.open ? 'Close' : 'Open'}</Text>
        </Pressable>
      ) : null}

      {canSwitch ? (
        <Pressable
          onPress={() => setPicking(true)}
          accessibilityRole="button"
          accessibilityLabel="Switch branch"
          style={({ pressed }) => [styles.syncCard, styles.drawerCard, pressed && styles.pressed]}
        >
          <View style={[styles.syncBadge, styles.branchBadge]}>
            <ArrowLeftRight color={colors.white} size={20} strokeWidth={2.25} />
          </View>
          <View style={styles.fill}>
            <Text style={styles.syncTitle} numberOfLines={1}>
              {locationName || 'Choose a branch'}
            </Text>
            <Text style={styles.syncHint}>
              {online ? `You work at ${branches.length} branches` : 'Connect to switch branch'}
            </Text>
          </View>
          <Text style={styles.syncAction}>Switch</Text>
        </Pressable>
      ) : null}

      <Text style={styles.groupLabel}>Details</Text>
      <View style={styles.group}>
        <DetailRow icon={Store} label="Business" value={businessName || '—'} />
        <DetailRow icon={MapPin} label="Shop" value={locationName || '—'} />
        <DetailRow icon={User} label="Signed in as" value={name} />
        <DetailRow icon={Smartphone} label="Device" value={deviceCode || '—'} hint="Starts every sale number from this phone" />
        <DetailRow icon={Info} label="App version" value={version} last />
      </View>

      <Pressable
        onPress={() => setLeaving(true)}
        accessibilityRole="button"
        style={({ pressed }) => [styles.signOut, pressed && styles.pressed]}
      >
        <LogOut color={colors.red} size={18} strokeWidth={2.25} />
        <Text style={styles.signOutText}>Sign out</Text>
      </Pressable>
      <Text style={styles.accountFoot}>TeamPOS Cashier</Text>

      <Modal visible={picking} transparent animationType="fade" onRequestClose={() => setPicking(false)}>
        <Pressable style={styles.scrim} onPress={() => setPicking(false)}>
          <Pressable style={styles.dialog} onPress={() => {}}>
            <Text style={styles.dialogTitle}>Switch branch</Text>
            <Text style={styles.dialogBody}>
              {online
                ? 'Products, stock and sales change to the branch you pick. Anything waiting to sync still goes to its own branch.'
                : 'Connect to TeamPOS to switch branch.'}
            </Text>
            <View style={styles.branchList}>
              {branches.map((branch, index) => {
                const current = Number(branch.id) === Number(locationId);
                return (
                  <Pressable
                    key={branch.id}
                    disabled={current || !online}
                    onPress={() => {
                      setPicking(false);
                      onSwitchBranch(branch.id);
                    }}
                    accessibilityRole="button"
                    accessibilityState={{ selected: current, disabled: !online }}
                    style={({ pressed }) => [
                      styles.branchRow,
                      index < branches.length - 1 && styles.branchRule,
                      pressed && styles.pressed,
                    ]}
                  >
                    <View style={[styles.locationIcon, current && styles.branchIconOn]}>
                      <MapPin color={current ? colors.white : colors.accent} size={18} strokeWidth={2} />
                    </View>
                    <View style={styles.fill}>
                      <Text style={[styles.locationName, !online && !current && styles.branchOff]}>{branch.name}</Text>
                      <Text style={styles.locationHint}>{current ? 'Selling here now' : 'Tap to switch'}</Text>
                    </View>
                    {current ? <Check color={colors.accent} size={20} strokeWidth={2.5} /> : null}
                  </Pressable>
                );
              })}
            </View>
            <QuietButton label="Cancel" onPress={() => setPicking(false)} />
          </Pressable>
        </Pressable>
      </Modal>

      <Modal visible={leaving} transparent animationType="fade" onRequestClose={() => setLeaving(false)}>
        <View style={styles.scrim}>
          <View style={styles.dialog}>
            <Text style={styles.dialogTitle}>Sign out?</Text>
            <Text style={styles.dialogBody}>
              {pending > 0
                ? `${pending} ${pending === 1 ? 'sale has' : 'sales have'} not synced yet. ${pending === 1 ? 'It stays' : 'They stay'} on this phone and sync the next time ${name} signs in here.`
                : 'Everything on this phone has synced.'}
            </Text>
            <View style={styles.dialogActions}>
              <View style={styles.fill}>
                <QuietButton label="Stay" onPress={() => setLeaving(false)} />
              </View>
              <View style={styles.fill}>
                <Pressable
                  onPress={() => {
                    setLeaving(false);
                    onLogout();
                  }}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.dangerButton, pressed && styles.pressed]}
                >
                  <Text style={styles.dangerText}>Sign out</Text>
                </Pressable>
              </View>
            </View>
          </View>
        </View>
      </Modal>
    </ScrollView>
  );
}

function DetailRow({ icon: Icon, label, value, hint, last }) {
  return (
    <View style={[styles.detailRow, !last && styles.detailRule]}>
      <View style={styles.detailIcon}>
        <Icon color={colors.accent} size={17} strokeWidth={2.25} />
      </View>
      <View style={styles.fill}>
        <Text style={styles.detailLabel}>{label}</Text>
        {hint ? <Text style={styles.detailHint}>{hint}</Text> : null}
      </View>
      <Text style={styles.detailValue} numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}

function CustomerList({
  customers,
  sales,
  selected,
  onSelect,
  onOpen,
  online,
  methods = [],
  syncing,
  onPay,
  canPay = true,
  onAdd,
  onEdit,
  rewardsName,
  error,
}) {
  const [query, setQuery] = useState('');
  const [paying, setPaying] = useState(false);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState(methods[0]?.id || 'cash');
  const rows = customers.filter((customer) => {
    const needle = query.trim().toLowerCase();
    if (!needle) {
      return true;
    }
    return `${customer.name} ${customer.business_name || ''} ${customer.mobile || ''}`.toLowerCase().includes(needle);
  });
  const person = customers.find((customer) => customer.id === selected) || null;
  const recent = person
    ? sales.filter((sale) => Number(sale.contactId) === Number(person.id)).slice(0, 12)
    : [];
  const choices = methods.length > 0 ? methods : [{ id: 'cash', label: 'Cash' }];
  if (person) {
    const heading = customerHeading(person);
    const due = Number(person.amount_due) || 0;
    const limit = person.credit_limit;
    let credit = 'Credit allowed';
    if (limit === 0 || limit === '0' || limit === '0.0000') {
      credit = 'Pays in full';
    } else if (limit != null && limit !== '') {
      credit = `Credit limit ${money(limit)}`;
    }
    const closeCustomer = () => {
      setPaying(false);
      onSelect(null);
    };
    return (
      <SwipeBack onBack={closeCustomer}>
        <ScreenHeader
          title={heading.title}
          subtitle={heading.subtitle || person.mobile || 'Customer'}
          onBack={closeCustomer}
          online={online}
          action={
            onEdit && online && !Number(person.is_default) ? (
              <Pressable onPress={() => onEdit(person)} hitSlop={8} accessibilityRole="button" accessibilityLabel="Edit customer">
                <UserPen color={colors.accent} size={22} strokeWidth={2.25} />
              </Pressable>
            ) : null
          }
        />
        <Banner message={error} />
        <View style={styles.balanceCard}>
          <Text style={styles.totalLabel}>Balance due</Text>
          <Text style={styles.totalValue}>{money(due)}</Text>
          {due > 0.009 ? (
            <Text style={styles.fieldHint}>
              This shop {money(person.amount_due_here)} · Other shops {money(Math.max(0, due - Number(person.amount_due_here || 0)))}
            </Text>
          ) : null}
          <Text style={styles.fieldHint}>{credit}</Text>
          {Number(person.advance) > 0.009 ? (
            <Text style={styles.fieldHint}>Advance {money(person.advance)}</Text>
          ) : null}
          {Number(person.reward_points) > 0 ? (
            <Text style={styles.fieldHint}>
              {person.reward_points} {rewardsName || 'points'}
            </Text>
          ) : null}
        </View>
        {!canPay ? null : paying ? (
          <View style={styles.payBlock}>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chipRow}>
              {choices.map((item) => (
                <Chip key={item.id} label={item.label} selected={method === item.id} onPress={() => setMethod(item.id)} />
              ))}
            </ScrollView>
            <TextInput
              value={amount}
              onChangeText={(value) => setAmount(value.replace(/[^0-9.]/g, ''))}
              keyboardType="decimal-pad"
              placeholder="Amount"
              placeholderTextColor={colors.faint}
              style={styles.input}
            />
            {due <= 0.009 ? (
              <Text style={styles.fieldHint}>This stays on the customer as an advance.</Text>
            ) : (
              <Text style={styles.fieldHint}>Anything above the balance is kept as an advance.</Text>
            )}
            <PrimaryButton
              label={syncing ? 'Saving' : 'Save payment'}
              disabled={syncing || !(Number(amount) > 0)}
              onPress={() => onPay?.(person, Number(amount), method)}
            />
          </View>
        ) : (
          <View style={styles.payAction}>
            <PrimaryButton
              label="Add payment"
              onPress={() => {
                setAmount(due > 0.009 ? due.toFixed(2) : '');
                setPaying(true);
              }}
            />
          </View>
        )}
        <Text style={styles.section}>Recent sales</Text>
        <FlatList
          data={recent}
          keyExtractor={(item) => item.key}
          contentContainerStyle={styles.list}
          ListEmptyComponent={<Text style={styles.empty}>No sales for this customer on this phone yet.</Text>}
          renderItem={({ item }) => <SaleCard item={item} onPress={onOpen} />}
        />
      </SwipeBack>
    );
  }
  return (
    <View style={styles.fill}>
      <ScreenHeader
        title="Customers"
        subtitle="Search by name or business"
        online={online}
        action={
          onAdd && online ? (
            <Pressable onPress={onAdd} hitSlop={8} accessibilityRole="button" accessibilityLabel="Add customer">
              <UserPlus color={colors.accent} size={22} strokeWidth={2.25} />
            </Pressable>
          ) : null
        }
      />
      <SearchField value={query} onChangeText={setQuery} placeholder="Search customers" />
      <FlatList
        style={styles.listGap}
        data={rows}
        keyExtractor={(item) => String(item.id)}
        keyboardShouldPersistTaps="handled"
        ListEmptyComponent={<Text style={styles.empty}>No customers on this device yet.</Text>}
        renderItem={({ item }) => {
          const heading = customerHeading(item);
          return (
            <Pressable
              onPress={() => onSelect(item.id)}
              accessibilityRole="button"
              style={({ pressed }) => [styles.location, pressed && styles.pressed]}
            >
              <Text style={styles.locationName}>{heading.title}</Text>
              <Text style={styles.locationHint}>
                {[
                  customerHint(item),
                  Number(item.amount_due) > 0.009 ? `Due ${money(item.amount_due)}` : '',
                  Number(item.advance) > 0.009 ? `Advance ${money(item.advance)}` : '',
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </Text>
            </Pressable>
          );
        }}
      />
    </View>
  );
}

function useOnline() {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    let alive = true;
    async function check() {
      try {
        const state = await Network.getNetworkStateAsync();
        if (alive) {
          setOnline(Boolean(state.isConnected) && state.isInternetReachable !== false);
        }
      } catch {
        if (alive) {
          setOnline(false);
        }
      }
    }
    check();
    const timer = setInterval(check, 8000);
    return () => {
      alive = false;
      clearInterval(timer);
    };
  }, []);
  return online;
}

function Receipt({ receipt, locationName, syncing, printing, error, onDone, onSync, onPrint, online }) {
  const synced = receipt.syncState === 'synced' && receipt.invoiceNo;
  const status = error && !synced ? 'attention' : synced ? 'synced' : 'pending';
  let note = 'This reference stays on the device until TeamPOS assigns an invoice number.';
  if (syncing && !synced) {
    note = 'Sending this sale to TeamPOS.';
  } else if (synced) {
    note = `Invoice ${receipt.invoiceNo} is on TeamPOS. Print uses the ${locationName || 'location'} receipt layout saved on this phone.`;
  } else if (error) {
    note = 'The sale is still on this device. Sync again when the connection is back.';
  }

  return (
    <SwipeBack onBack={onDone}>
      <ScrollView style={styles.fill} contentContainerStyle={styles.receipt}>
        <View style={styles.doneMark}>
          <View style={styles.check} />
        </View>
        <View style={styles.presenceRow}>
          <View style={[styles.presence, online ? styles.presenceOn : styles.presenceOff]} />
          <Text style={styles.fieldHint}>{online ? 'Online' : 'Offline'}</Text>
        </View>
        <Text style={styles.brand}>Sale saved</Text>
        <Text style={styles.receiptRef}>{synced ? receipt.invoiceNo : receipt.deviceRef}</Text>
        {synced ? <Text style={styles.fieldHint}>{receipt.deviceRef}</Text> : null}
        <Text style={styles.lede}>
          {receipt.customerName} · {receipt.methodLabel}
        </Text>
        <Text style={styles.receiptTotal}>{money(receipt.total)}</Text>
        <Text style={styles.fieldHint}>{prettyDate(receipt.transactionDate)}</Text>
        <Banner message={error} />
        {receipt.lines.map((line) => (
          <View key={line.variation_id} style={styles.receiptLine}>
            <Text style={styles.lineName}>
              {line.name} × {line.quantity}
            </Text>
            <Text style={styles.lineName}>{money(line.quantity * line.unit_price)}</Text>
          </View>
        ))}
        <StatusPill status={status} />
        <Text style={styles.note}>{note}</Text>
      </ScrollView>
      <PrimaryButton
        label={printing ? 'Opening print' : 'Print receipt'}
        onPress={onPrint}
        disabled={printing}
      />
      <View style={styles.actions}>
        {synced ? null : (
          <View style={styles.action}>
            <QuietButton label={syncing ? 'Syncing' : 'Sync now'} onPress={onSync} disabled={syncing} />
          </View>
        )}
        <View style={styles.action}>
          <PrimaryButton label="New sale" onPress={onDone} />
        </View>
      </View>
    </SwipeBack>
  );
}

function webPreviewName() {
  if (Platform.OS !== 'web' || typeof window === 'undefined') {
    return null;
  }
  return new URLSearchParams(window.location.search).get('preview');
}

const PREVIEW_SETTINGS = {
  can_override_price: true,
  can_discount: true,
  can_sell_on_credit: true,
  rewards: { name: 'Bread points', amount_per_point: '0.1', min_redeem_points: null, max_redeem_points: null, min_order_total: '1' },
};

const SAMPLE_PRODUCTS = [
  { product_id: 1, variation_id: 1, name: 'Sourdough loaf', variation_name: 'DUMMY', sku: 'SD', sell_price: '18', qty_available: '12' },
  { product_id: 2, variation_id: 2, name: 'Butter croissant', variation_name: 'DUMMY', sku: 'CR', sell_price: '8.5', qty_available: '3' },
  { product_id: 3, variation_id: 3, name: 'Cinnamon roll', variation_name: 'Large', sku: 'CN', sell_price: '12', qty_available: '0' },
  { product_id: 4, variation_id: 4, name: 'Beef pie', variation_name: 'DUMMY', sku: 'BP', sell_price: '15', qty_available: '6' },
];

function WebPreview({ name }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState('stock');
  const [cart, setCart] = useState([
    { product_id: 2, variation_id: 2, name: 'Butter croissant', variation_name: '', quantity: 2, unit_price: 8.5, qty_available: 3 },
  ]);
  const [stage, setStage] = useState(name === 'confirm' || name === 'confirm-walkin' ? 'confirm' : 'register');
  const [tab, setTab] = useState(
    name === 'sales'
      ? 'sales'
      : name === 'account'
        ? 'account'
        : name === 'customers'
          ? 'customers'
          : name === 'register'
            ? 'sell'
            : 'home'
  );
  const [method, setMethod] = useState('cash');
  const [tenders, setTenders] = useState([{ method: 'cash', received: '17.00' }]);
  const [customerPick, setCustomerPick] = useState(null);
  const [openedSale, setOpenedSale] = useState(name === 'sale' ? previewSales()[1] : null);
  const [previewSaleFilter, setPreviewSaleFilter] = useState('all');
  const [previewDiscount, setPreviewDiscount] = useState({ type: 'fixed', amount: 0 });
  const [previewPoints, setPreviewPoints] = useState(0);
  const [receipt, setReceipt] = useState(name === 'receipt' ? {
    clientUuid: 'preview',
    deviceRef: 'C-A1B2C3-000041',
    total: 17,
    transactionDate: '2026-02-04 14:42:00',
    customerName: 'Walk-in',
    methodLabel: 'Cash',
    lines: [{ variation_id: 2, name: 'Butter croissant', quantity: 2, unit_price: 8.5 }],
    invoiceNo: '2026/0142',
    serverId: 1,
    syncState: 'synced',
  } : null);
  const products = visibleProducts(SAMPLE_PRODUCTS, query, filter);
  const qtyById = Object.fromEntries(cart.map((line) => [line.variation_id, line.quantity]));
  if (name === 'splash') {
    return <Splash title="Cashier" />;
  }
  if (name === 'gate') {
    return (
      <View style={styles.fill}>
        <RegisterGate businessName="BreadCity Bakery" locationName="Trek" online busy={false} error="" onOpen={() => {}} />
        <TabBar tab="sell" pending={0} onChange={() => {}} />
      </View>
    );
  }
  if (name === 'drawer' || name === 'drawer-closed') {
    return (
      <Drawer
        register={
          name === 'drawer'
            ? {
                open: true,
                opened_at: '2026-02-04 07:58:00',
                opening_cash: '50',
                expected_cash: '214.5',
                total_refunds: '8.5',
                by_method: [
                  { method: 'cash', total: '164.5' },
                  { method: 'custom_pay_1', total: '42' },
                ],
              }
            : { open: false }
        }
        methods={[{ id: 'cash', label: 'Cash' }, { id: 'custom_pay_1', label: 'MTN MoMo' }]}
        canClose
        busy={false}
        error=""
        online
        locationName="Trek"
        onBack={() => {}}
        onOpen={() => {}}
        onClose={async () => null}
      />
    );
  }
  if (name === 'return') {
    return (
      <SaleReturn
        sale={{
          subtitle: 'C0142',
          title: 'Ama Mensah',
          serverId: 1,
          lines: [
            { sell_line_id: 1, name: 'Butter croissant', quantity: '4', quantity_returned: '1', unit_price: '8.5' },
            { sell_line_id: 2, name: 'Sourdough loaf', quantity: '1', quantity_returned: '0', unit_price: '18' },
          ],
        }}
        methods={[{ id: 'cash', label: 'Cash' }, { id: 'custom_pay_1', label: 'MTN MoMo' }]}
        busy={false}
        error=""
        online
        onBack={() => {}}
        onSubmit={() => {}}
      />
    );
  }
  if (name === 'customer-new') {
    return <CustomerForm busy={false} error="" online onBack={() => {}} onSubmit={() => {}} />;
  }
  if (name === 'signin') {
    return (
      <SignIn
        username={username}
        password={password}
        onUsername={setUsername}
        onPassword={setPassword}
        onSubmit={() => {}}
        locations={[]}
        onLocation={() => {}}
        error=""
      />
    );
  }
  if (receipt) {
    return (
      <Receipt
        receipt={receipt}
        locationName="Trek"
        syncing={false}
        printing={false}
        error=""
        onDone={() => setReceipt(null)}
        onSync={() => {}}
        onPrint={() => {}}
        online
      />
    );
  }
  if (openedSale) {
    return (
      <SaleView
        sale={{
          lines: [{ name: 'Butter croissant', quantity: 2, unit_price: 8.5 }],
          payments: [],
          ...openedSale,
        }}
        methods={[{ id: 'cash', label: 'Cash' }, { id: 'custom_pay_1', label: 'MTN MoMo' }]}
        canReturn
        syncing={false}
        printing={false}
        error=""
        online
        onBack={() => setOpenedSale(null)}
        onPay={() => {}}
        onPrint={() => {}}
      />
    );
  }
  if (stage === 'customers') {
    return (
      <Customers
        customers={[{ id: 1, name: 'Walk-in', mobile: '', is_default: 1 }, { id: 2, name: 'Ama Mensah', mobile: '0244000000', is_default: 0 }]}
        selectedId={1}
        onBack={() => setStage('confirm')}
        onSelect={() => setStage('confirm')}
      />
    );
  }
  if (stage === 'confirm') {
    return (
      <Checkout
        cart={cart}
        products={SAMPLE_PRODUCTS}
        customer={
          name === 'confirm-walkin'
            ? { id: 1, name: 'Walk-in', is_default: 1, credit_limit: null, amount_due: '0' }
            : { id: 2, name: 'Ama Mensah', is_default: 0, credit_limit: null, amount_due: '0', reward_points: 40 }
        }
        methods={[{ id: 'cash', label: 'Cash' }, { id: 'custom_pay_1', label: 'MTN MoMo' }]}
        tenders={tenders}
        onTenders={setTenders}
        discount={previewDiscount}
        onDiscount={setPreviewDiscount}
        points={previewPoints}
        onPoints={setPreviewPoints}
        settings={PREVIEW_SETTINGS}
        saving={false}
        error=""
        online
        onBack={() => setStage('register')}
        onCustomer={() => setStage('customers')}
        onPrice={(id, price) =>
          setCart((current) =>
            current.map((line) =>
              line.variation_id === id
                ? price == null
                  ? { ...line, unit_price: line.list_price ?? line.unit_price, price_override: false }
                  : { ...line, list_price: line.list_price ?? line.unit_price, unit_price: price, price_override: true }
                : line
            )
          )
        }
        onQuantity={(id, quantity) => setCart((current) => withQuantity(current, id, quantity))}
        onSave={() => setReceipt({
          clientUuid: 'preview',
          deviceRef: 'C-A1B2C3-000041',
          total: cartTotal(cart),
          transactionDate: localDateTime(new Date()),
          customerName: 'Walk-in',
          methodLabel: method === 'card' ? 'Card' : 'Cash',
          lines: cart,
          invoiceNo: null,
          serverId: null,
          syncState: 'pending',
        })}
      />
    );
  }
  return (
    <View style={styles.fill}>
      {tab === 'home' ? (
        <Home
          businessName="BreadCity Bakery"
          locationName="Trek"
          online
          todayTotal={45}
          dueTotal={28}
          stockCount={21}
          paymentsTotal={17}
          week={weekReview(previewWeekSales(), new Date())}
          pending={3}
          syncing={false}
          onSync={() => {}}
          error=""
        />
      ) : null}
      {tab === 'sell' ? (
        <Register
          businessName="BreadCity Bakery"
          locationName="Trek"
          online
          query={query}
          onQuery={setQuery}
          filter={filter}
          onFilter={setFilter}
          counts={filterCounts(SAMPLE_PRODUCTS, query)}
          products={products}
          quantities={qtyById}
          onAdd={(product) => setCart((current) => addProduct(current, product))}
          syncing={false}
          onSync={() => {}}
          error=""
        />
      ) : null}
      {tab === 'sales' ? (
        <Transactions
          items={previewSales()}
          week={weekReview(previewSales(), new Date())}
          query=""
          onQuery={() => {}}
          filter={previewSaleFilter}
          onFilter={setPreviewSaleFilter}
          onOpen={setOpenedSale}
          error=""
        />
      ) : null}
      {tab === 'customers' ? (
        <CustomerList
          online
          customers={[
            { id: 1, name: 'Walk-in', mobile: '', is_default: 1, credit_limit: '0', amount_due: '0' },
            { id: 2, name: 'Ama Mensah', business_name: 'Mensah Catering', mobile: '0244000000', is_default: 0, credit_limit: null, amount_due: '28' },
            { id: 3, name: '', business_name: 'Bolt Food', mobile: '', is_default: 0, credit_limit: null, amount_due: '0' },
          ]}
          sales={[{
            key: '2',
            title: 'Ama Mensah',
            subtitle: 'C0008',
            whenLabel: '4 Feb 2026 · 1:10 PM',
            amount: 28,
            paid: 0,
            status: 'due',
            contactId: 2,
            lines: [{ name: 'Beef pie', quantity: 1, unit_price: 28 }],
            payments: [],
          }]}
          selected={customerPick}
          onSelect={setCustomerPick}
          onOpen={() => {}}
          methods={[{ id: 'cash', label: 'Cash' }, { id: 'custom_pay_1', label: 'MTN MoMo' }]}
          onPay={() => {}}
          error=""
        />
      ) : null}
      {tab === 'account' ? (
        <Account
          businessName="BreadCity Bakery"
          locationName="Trek"
          username="Cashiz"
          deviceCode="C-A1B2C3"
          shiftCount={6}
          shiftTotal={214.5}
          pending={1}
          lastSync="2026-02-04 14:00:00"
          online
          syncing={false}
          onSync={() => {}}
          onLogout={() => {}}
          register={{ open: true, expected_cash: '214.5', opened_at: '2026-02-04 07:58:00' }}
          onDrawer={() => {}}
          branches={[
            { id: 5, name: 'Trek' },
            { id: 6, name: 'Madina' },
            { id: 7, name: 'Spintex' },
          ]}
          locationId={5}
          error=""
        />
      ) : null}
      {tab === 'sell' && cartCount(cart) > 0 ? (
        <CartBar
          count={cartCount(cart)}
          total={cartTotal(cart)}
          onNext={() => {
            const amount = cartTotal(cart);
            setTenders([{ method: 'cash', received: amount.toFixed(2) }]);
            setStage('confirm');
          }}
        />
      ) : null}
      <TabBar tab={tab} pending={1} onChange={setTab} />
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

function readJson(value, fallback) {
  try {
    return value ? JSON.parse(value) : fallback;
  } catch {
    return fallback;
  }
}

async function queue(db, clientUuid, type, payload, cashier) {
  await db.runAsync(
    `INSERT INTO outbox (client_uuid, type, payload, state, cashier, created_at) VALUES (?, ?, ?, 'pending', ?, ?)`,
    clientUuid,
    type,
    JSON.stringify(payload),
    cashier || null,
    localDateTime(new Date())
  );
}

function newClientUuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  frame: { flex: 1, width: '100%', alignSelf: 'center', paddingHorizontal: 16, paddingTop: 8 },
  fill: { flex: 1 },
  scrim: {
    flex: 1,
    backgroundColor: 'rgba(28, 25, 23, 0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
  },
  dialog: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: colors.card,
    borderRadius: 22,
    padding: 20,
  },
  dialogTitle: { fontSize: 22, fontWeight: '700', color: colors.ink, marginBottom: 8 },
  dialogTotal: { fontSize: 28, fontWeight: '700', color: colors.ink, marginTop: 4 },
  dialogActions: { flexDirection: 'row', gap: 10, marginTop: 18 },
  dialogBody: { color: colors.muted, fontSize: 15, lineHeight: 22 },
  dangerButton: {
    minHeight: 52,
    borderRadius: 18,
    backgroundColor: colors.red,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dangerText: { color: colors.white, fontSize: 16, fontWeight: '700' },
  accountScroll: { paddingTop: 8, paddingBottom: 24 },
  hero: {
    backgroundColor: '#0B2A6F',
    borderRadius: 28,
    padding: 20,
    overflow: 'hidden',
    marginBottom: 12,
  },
  heroOrb: { position: 'absolute', borderRadius: 999 },
  heroOrbOne: { width: 220, height: 220, right: -70, top: -90, backgroundColor: colors.accent, opacity: 0.55 },
  heroOrbTwo: { width: 160, height: 160, left: -60, bottom: -80, backgroundColor: '#00A3FF', opacity: 0.25 },
  heroTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  avatar: {
    width: 56,
    height: 56,
    borderRadius: 20,
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.28)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: colors.white, fontSize: 20, fontWeight: '700', letterSpacing: 0.5 },
  heroStatus: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 6,
  },
  heroStatusOn: { backgroundColor: 'rgba(74,222,128,0.16)' },
  heroStatusOff: { backgroundColor: 'rgba(248,113,113,0.2)' },
  heroDot: { width: 8, height: 8, borderRadius: 4 },
  heroStatusText: { color: colors.white, fontSize: 12, fontWeight: '700' },
  heroName: { color: colors.white, fontSize: 28, fontWeight: '700', letterSpacing: -0.5, marginTop: 18 },
  heroRole: { color: '#BFDBFE', fontSize: 14, fontWeight: '600', marginTop: 2 },
  heroShop: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
    marginTop: 12,
    backgroundColor: 'rgba(255,255,255,0.1)',
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 6,
  },
  heroShopText: { color: colors.white, fontSize: 13, fontWeight: '600' },
  heroStats: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 20,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 18,
    paddingVertical: 14,
    paddingHorizontal: 16,
  },
  heroStat: { flex: 1 },
  heroStatValue: { color: colors.white, fontSize: 22, fontWeight: '700' },
  heroStatLabel: { color: '#BFDBFE', fontSize: 12, fontWeight: '600', marginTop: 2 },
  heroDivider: { width: 1, alignSelf: 'stretch', backgroundColor: 'rgba(255,255,255,0.16)', marginHorizontal: 16 },
  syncCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    backgroundColor: colors.card,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 16,
  },
  syncBadge: { width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center' },
  syncOk: { backgroundColor: colors.green },
  syncWait: { backgroundColor: colors.red },
  syncBusy: { backgroundColor: colors.accent },
  syncOff: { backgroundColor: colors.muted },
  syncTitle: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  syncHint: { color: colors.muted, fontSize: 13, lineHeight: 18, marginTop: 2 },
  syncAction: { color: colors.accent, fontSize: 15, fontWeight: '700' },
  groupLabel: {
    color: colors.muted,
    fontSize: 12,
    fontWeight: '700',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
    marginTop: 22,
    marginBottom: 8,
    marginLeft: 4,
  },
  group: {
    backgroundColor: colors.card,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: 14,
  },
  detailRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14 },
  detailRule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  detailIcon: {
    width: 32,
    height: 32,
    borderRadius: 10,
    backgroundColor: colors.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  detailLabel: { color: colors.ink, fontSize: 15, fontWeight: '600' },
  detailHint: { color: colors.muted, fontSize: 12, marginTop: 2 },
  detailValue: { color: colors.muted, fontSize: 15, fontWeight: '600', maxWidth: '45%', textAlign: 'right' },
  signOut: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 54,
    borderRadius: 18,
    backgroundColor: colors.redSoft,
    marginTop: 22,
  },
  signOutText: { color: colors.red, fontSize: 16, fontWeight: '700' },
  drawerCard: { marginTop: 12 },
  branchBadge: { backgroundColor: colors.accent },
  branchList: { marginTop: 14, marginBottom: 14 },
  branchRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  branchRule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  branchIconOn: { backgroundColor: colors.accent },
  branchOff: { color: colors.faint },
  notice: {
    color: colors.green,
    backgroundColor: colors.greenSoft,
    borderRadius: 14,
    padding: 12,
    fontSize: 14,
    fontWeight: '600',
    marginBottom: 10,
  },
  accountFoot: { color: colors.faint, fontSize: 12, textAlign: 'center', marginTop: 14 },
  backEdge: { position: 'absolute', left: -16, top: 72, bottom: 0, width: 28, zIndex: 20 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  mark: {
    width: 56,
    height: 56,
    borderRadius: 18,
    backgroundColor: colors.accent,
    marginBottom: 16,
  },
  brand: { fontSize: 32, fontWeight: '700', color: colors.ink, letterSpacing: -0.5 },
  lede: { marginTop: 8, marginBottom: 20, color: colors.muted, fontSize: 16, lineHeight: 22 },
  spinner: { marginTop: 20 },
  signIn: { flexGrow: 1, justifyContent: 'center', paddingVertical: 32 },
  splashLogo: { width: 200, height: 122 },
  splashText: { marginTop: 12, color: colors.muted, fontSize: 14, fontWeight: '600' },
  signInLogo: { width: 168, height: 102, alignSelf: 'center', marginBottom: 32 },
  signInTitle: { color: colors.ink, fontSize: 26, fontWeight: '700', letterSpacing: -0.4, textAlign: 'center' },
  signInLede: { color: colors.muted, fontSize: 15, lineHeight: 21, textAlign: 'center', marginTop: 6, marginBottom: 24 },
  signInForm: { gap: 12 },
  signInAction: { marginTop: 4 },
  signInField: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: colors.white,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: colors.line,
    minHeight: 54,
    paddingHorizontal: 16,
  },
  signInInput: { flex: 1, fontSize: 16, color: colors.ink, paddingVertical: 14 },
  signInLocation: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: colors.card,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 14,
  },
  locationIcon: {
    width: 36,
    height: 36,
    borderRadius: 12,
    backgroundColor: colors.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  form: { gap: 8 },
  label: { color: colors.muted, fontSize: 13, fontWeight: '600', marginBottom: 6, marginTop: 8 },
  input: {
    backgroundColor: colors.white,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: colors.line,
    minHeight: 52,
    paddingHorizontal: 14,
    fontSize: 16,
    color: colors.ink,
    marginBottom: 8,
  },
  section: { fontSize: 16, fontWeight: '700', color: colors.ink, marginTop: 8, marginBottom: 10 },
  location: {
    backgroundColor: colors.card,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 16,
    marginBottom: 10,
  },
  locationOn: { borderColor: colors.accent, backgroundColor: colors.accentSoft },
  locationName: { fontSize: 16, fontWeight: '700', color: colors.ink },
  locationHint: { marginTop: 4, color: colors.muted, fontSize: 13 },
  pressed: { opacity: 0.72 },
  syncLink: { color: colors.accent, fontWeight: '700', fontSize: 15 },
  chips: { maxHeight: 52, marginTop: 12 },
  chipsInline: { flexDirection: 'row', marginTop: 12, marginBottom: 4 },
  chipRow: { paddingRight: 8 },
  grid: { paddingTop: 12, paddingBottom: 12 },
  gridRow: { gap: 10, marginBottom: 10 },
  empty: { color: colors.muted, fontSize: 15, lineHeight: 22, marginTop: 24 },
  confirm: { paddingBottom: 16 },
  pair: { flexDirection: 'row', gap: 10 },
  field: {
    flex: 1,
    backgroundColor: colors.white,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 14,
    marginBottom: 10,
  },
  fieldRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  fieldValue: { color: colors.ink, fontSize: 15, fontWeight: '700', flex: 1 },
  change: { color: colors.accent, fontSize: 14, fontWeight: '700' },
  fieldHint: { color: colors.muted, fontSize: 13, marginTop: 4 },
  payBlock: { marginTop: 8, marginBottom: 8 },
  paySummary: { marginTop: 8, marginBottom: 8 },
  accountName: { fontSize: 22, fontWeight: '700', color: colors.ink },
  presenceRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 8 },
  presence: { width: 10, height: 10, borderRadius: 5 },
  presenceOn: { backgroundColor: colors.green },
  presenceOff: { backgroundColor: colors.red },
  logoutGap: { height: 12 },
  blockHead: { marginTop: 8 },
  line: {
    flexDirection: 'row',
    backgroundColor: colors.card,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 14,
    marginBottom: 10,
    gap: 12,
  },
  lineBody: { flex: 1 },
  lineName: { color: colors.ink, fontSize: 15, fontWeight: '700' },
  lineSide: { alignItems: 'flex-end', gap: 8 },
  lineTotal: { color: colors.ink, fontWeight: '700' },
  warn: { color: colors.amber, fontSize: 12, marginTop: 6, lineHeight: 16 },
  syncRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: colors.card,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: 16,
    minHeight: 56,
    marginBottom: 10,
  },
  syncRowLabel: { flex: 1, color: colors.ink, fontSize: 15, fontWeight: '600' },
  syncCount: { color: colors.muted, fontSize: 18, fontWeight: '700' },
  syncCountOn: { color: colors.red },
  homeRow: { flexDirection: 'row', gap: 10, marginBottom: 10 },
  homeCard: {
    flex: 1,
    backgroundColor: colors.card,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 16,
    minHeight: 112,
    justifyContent: 'space-between',
  },
  homeScroll: { paddingBottom: 12 },
  homeIcon: {
    width: 32,
    height: 32,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
  },
  homeCardLabel: { color: colors.muted, fontSize: 13, fontWeight: '600' },
  homeCardValue: { color: colors.ink, fontSize: 24, fontWeight: '700', marginTop: 6 },
  chartCard: {
    backgroundColor: colors.card,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 16,
    marginBottom: 10,
  },
  chartHead: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 18 },
  chartTitle: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  chartCaption: { color: colors.muted, fontSize: 13, fontWeight: '600', marginTop: 2 },
  chartTotals: { alignItems: 'flex-end' },
  chartTotal: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  chartBars: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', gap: 6 },
  chartCol: { flex: 1, alignItems: 'center' },
  chartTrack: {
    height: 88,
    width: 22,
    borderRadius: 11,
    backgroundColor: colors.chip,
    justifyContent: 'flex-end',
    overflow: 'hidden',
  },
  chartTrackFuture: { opacity: 0.45 },
  chartBar: { width: '100%', borderRadius: 11, backgroundColor: '#7CBBF7' },
  chartBarToday: { backgroundColor: colors.accent },
  chartDay: { color: colors.muted, fontSize: 11, fontWeight: '600', marginTop: 8 },
  chartDayToday: { color: colors.accent, fontWeight: '700' },
  chartFoot: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 16,
    paddingTop: 14,
    borderTopWidth: 1,
    borderTopColor: colors.line,
  },
  chartStat: { flex: 1 },
  chartStatEnd: { alignItems: 'flex-end' },
  chartStatLabel: { color: colors.muted, fontSize: 12, fontWeight: '600' },
  chartStatValue: { color: colors.ink, fontSize: 14, fontWeight: '700', marginTop: 4 },
  balanceCard: {
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
    padding: 16,
    marginBottom: 12,
  },
  payAction: { marginBottom: 8 },
  totalCard: {
    marginTop: 6,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
    padding: 16,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  totalLabel: { color: colors.muted, fontSize: 14, fontWeight: '600' },
  totalValue: { color: colors.ink, fontSize: 22, fontWeight: '700' },
  actions: { flexDirection: 'row', gap: 10, paddingTop: 8, paddingBottom: 8 },
  action: { flex: 1 },
  listGap: { marginTop: 12 },
  list: { paddingTop: 8, paddingBottom: 12 },
  saleDetail: { color: colors.red, fontSize: 12, marginTop: -4, marginBottom: 10, marginLeft: 4 },
  stat: {
    backgroundColor: colors.card,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 18,
    marginBottom: 16,
  },
  statValue: { fontSize: 40, fontWeight: '700', color: colors.ink, marginTop: 4 },
  note: { color: colors.muted, fontSize: 14, lineHeight: 21, marginTop: 16 },
  receipt: { alignItems: 'center', paddingTop: 24, paddingBottom: 20 },
  doneMark: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: colors.green,
    marginBottom: 16,
    alignItems: 'center',
    justifyContent: 'center',
  },
  check: {
    width: 14,
    height: 24,
    marginTop: -4,
    borderRightWidth: 3,
    borderBottomWidth: 3,
    borderColor: colors.white,
    transform: [{ rotate: '40deg' }],
  },
  receiptRef: { marginTop: 12, fontSize: 22, fontWeight: '700', color: colors.ink },
  receiptTotal: { marginTop: 8, fontSize: 36, fontWeight: '700', color: colors.ink },
  receiptLine: {
    width: '100%',
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: colors.line,
  },
});
