import { memo, useEffect, useRef, useState } from 'react';
import {
  CircleFadingArrowUp,
  CirclePlus,
  Contact,
  House,
  LayoutGrid,
  PackageCheck,
  TriangleAlert,
  User,
} from 'lucide-react-native';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring } from 'react-native-reanimated';
import { money, stockLabel, tracksStock, variationLabel } from './format';

export const colors = {
  bg: '#F3EEE7',
  card: '#FFFCF8',
  ink: '#1C1917',
  muted: '#7A726B',
  faint: '#A79E96',
  line: '#E6DDD3',
  accent: '#0078F0',
  accentSoft: '#E7F3FE',
  green: '#147A4E',
  greenSoft: '#E3F6EC',
  amber: '#92400E',
  amberSoft: '#FEF3C7',
  red: '#B42318',
  redSoft: '#FEE4E2',
  white: '#FFFFFF',
  chip: '#EFE8DF',
  tile: ['#F6D7C3', '#F3E1B8', '#DCEAD9', '#F6D4D0', '#E4DFF4', '#D7E6F4'],
};

const TILE_INK = '#3F342C';

export function initials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (parts.length === 0) {
    return '•';
  }
  if (parts.length === 1) {
    return parts[0].slice(0, 2).toUpperCase();
  }
  return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
}

export function tileColor(name) {
  const text = String(name || '');
  let hash = 0;
  for (let i = 0; i < text.length; i += 1) {
    hash = (hash + text.charCodeAt(i) * (i + 1)) % colors.tile.length;
  }
  return colors.tile[hash];
}

export function Banner({ message }) {
  if (!message) {
    return null;
  }
  return (
    <View style={styles.banner}>
      <Text style={styles.bannerText}>{message}</Text>
    </View>
  );
}

export function SearchField({ value, onChangeText, placeholder }) {
  return (
    <View style={styles.search}>
      <View style={styles.searchMark} />
      <TextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.faint}
        style={styles.searchInput}
        autoCapitalize="none"
        autoCorrect={false}
        clearButtonMode="while-editing"
      />
    </View>
  );
}

export function Chip({ label, selected, onPress }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.chip,
        selected && styles.chipOn,
        pressed && styles.pressed,
      ]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextOn]}>{label}</Text>
    </Pressable>
  );
}

const stockOptions = [
  { id: 'all', label: 'All', icon: LayoutGrid },
  { id: 'stock', label: 'In stock', icon: PackageCheck },
  { id: 'low', label: 'Low', icon: TriangleAlert },
];

export function StockFilter({ value, onChange, counts }) {
  return <Segments options={stockOptions} value={value} onChange={onChange} counts={counts} warn="low" />;
}

export function Segments({ options, value, onChange, counts, warn }) {
  const [width, setWidth] = useState(0);
  const index = Math.max(0, options.findIndex((option) => option.id === value));
  const segment = width > 0 ? (width - 8) / options.length : 0;
  const offset = useSharedValue(0);
  const placed = useRef(false);
  useEffect(() => {
    if (segment <= 0) {
      return;
    }
    if (!placed.current) {
      offset.value = index * segment;
      placed.current = true;
      return;
    }
    offset.value = withSpring(index * segment, navSpring);
  }, [index, segment, offset]);
  const thumbStyle = useAnimatedStyle(() => ({
    width: segment,
    transform: [{ translateX: offset.value }],
  }));
  return (
    <View
      style={styles.segments}
      accessibilityRole="tablist"
      onLayout={(event) => setWidth(event.nativeEvent.layout.width)}
    >
      {segment > 0 ? <Animated.View style={[styles.segmentThumb, thumbStyle]} /> : null}
      {options.map((option) => {
        const selected = option.id === value;
        const Icon = option.icon;
        const tone = selected ? (option.id === warn ? colors.amber : colors.accent) : colors.muted;
        const count = counts ? counts[option.id] ?? 0 : null;
        return (
          <Pressable
            key={option.id}
            onPress={() => onChange(option.id)}
            accessibilityRole="tab"
            accessibilityLabel={count === null ? option.label : `${option.label}, ${count}`}
            accessibilityState={{ selected }}
            style={styles.segment}
          >
            {Icon ? <Icon color={tone} size={15} strokeWidth={2.25} /> : null}
            <Text style={[styles.segmentText, selected && { color: colors.ink }]} numberOfLines={1}>
              {option.label}
            </Text>
            {count === null ? null : <Text style={[styles.segmentCount, { color: tone }]}>{count}</Text>}
          </Pressable>
        );
      })}
    </View>
  );
}

export function PrimaryButton({ label, onPress, disabled }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.primary,
        disabled && styles.disabled,
        pressed && !disabled && styles.pressed,
      ]}
    >
      <Text style={styles.primaryText}>{label}</Text>
    </Pressable>
  );
}

export function QuietButton({ label, onPress, disabled }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.quiet,
        disabled && styles.disabled,
        pressed && !disabled && styles.pressed,
      ]}
    >
      <Text style={styles.quietText}>{label}</Text>
    </Pressable>
  );
}

export function BackButton({ onPress }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel="Back"
      hitSlop={8}
      style={({ pressed }) => [styles.back, pressed && styles.pressed]}
    >
      <View style={styles.chevron} />
    </Pressable>
  );
}

export function ScreenHeader({ title, subtitle, onBack, action, online }) {
  return (
    <View style={styles.header}>
      <View style={styles.headerSide}>{onBack ? <BackButton onPress={onBack} /> : null}</View>
      <View style={styles.headerCenter}>
        <View style={styles.headerTitleRow}>
          {typeof online === 'boolean' ? (
            <View style={[styles.presence, online ? styles.presenceOn : styles.presenceOff]} />
          ) : null}
          <Text style={styles.headerTitle} numberOfLines={1}>
            {title}
          </Text>
        </View>
        {subtitle ? (
          <Text style={styles.headerSub} numberOfLines={1}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      <View style={[styles.headerSide, styles.headerAction]}>{action}</View>
    </View>
  );
}

function Plus() {
  return (
    <View style={styles.plus}>
      <View style={styles.plusBar} />
      <View style={[styles.plusBar, styles.plusBarY]} />
    </View>
  );
}

export const ProductCard = memo(function ProductCard({ product, quantity, onAdd }) {
  const variation = variationLabel(product.variation_name);
  const onHand = tracksStock(product) ? Number(product.qty_available) : Infinity;
  const stock = stockLabel(product.qty_available, product);
  const stockStyle = onHand <= 0 ? styles.stockOut : onHand <= 5 ? styles.stockLow : styles.stock;

  return (
    <Pressable
      onPress={() => onAdd(product)}
      accessibilityRole="button"
      accessibilityLabel={`Add ${product.name}`}
      accessibilityState={{ disabled: onHand <= 0 }}
      disabled={onHand <= 0}
      style={({ pressed }) => [styles.product, onHand <= 0 && styles.productOff, pressed && onHand > 0 && styles.pressed]}
    >
      <View style={[styles.tile, { backgroundColor: tileColor(product.name) }]}>
        <Text style={styles.monogram}>{initials(product.name)}</Text>
        <View style={styles.addBadge}>
          {onHand <= 0 ? null : quantity > 0 ? <Text style={styles.addCount}>{quantity}</Text> : <Plus />}
        </View>
      </View>
      <Text style={styles.productName} numberOfLines={2}>
        {product.name}
      </Text>
      {variation ? (
        <Text style={styles.variation} numberOfLines={1}>
          {variation}
        </Text>
      ) : null}
      <Text style={styles.price}>{money(product.sell_price)}</Text>
      <Text style={stockStyle}>{stock}</Text>
    </Pressable>
  );
});

export function CartBar({ count, total, onNext }) {
  const label = count === 1 ? '1 item' : `${count} items`;
  return (
    <View style={styles.cartBar}>
      <View>
        <Text style={styles.cartCount}>{label}</Text>
        <Text style={styles.cartTotal}>{money(total)}</Text>
      </View>
      <Pressable
        onPress={onNext}
        accessibilityRole="button"
        style={({ pressed }) => [styles.next, pressed && styles.pressed]}
      >
        <Text style={styles.nextText}>Next</Text>
      </Pressable>
    </View>
  );
}

const navItems = [
  { id: 'home', label: 'Home', icon: House },
  { id: 'sales', label: 'Sales', icon: CircleFadingArrowUp },
  { id: 'sell', label: 'Register', icon: CirclePlus },
  { id: 'customers', label: 'Customers', icon: Contact },
  { id: 'account', label: 'Account', icon: User },
];

const navSpring = { stiffness: 500, damping: 30 };
const glowSize = 52;

export function TabBar({ tab, pending, onChange }) {
  const activeIndex = Math.max(0, navItems.findIndex((item) => item.id === tab));
  const centers = useRef([]);
  const placed = useRef(false);
  const glowX = useSharedValue(0);
  const glowScale = useSharedValue(1);
  const glowOpacity = useSharedValue(0);
  const moveGlow = (index, animate) => {
    const slot = centers.current[index];
    if (!slot) {
      return;
    }
    const next = slot.x + slot.width / 2 - glowSize / 2;
    const scale = navItems[index].id === 'sell' ? 1.22 : 1;
    if (!animate || !placed.current) {
      glowX.value = next;
      glowScale.value = scale;
      glowOpacity.value = 1;
      placed.current = true;
      return;
    }
    glowX.value = withSpring(next, navSpring);
    glowScale.value = withSpring(scale, navSpring);
  };
  useEffect(() => {
    moveGlow(activeIndex, true);
  }, [activeIndex]);
  const glowStyle = useAnimatedStyle(() => ({
    opacity: glowOpacity.value,
    transform: [{ translateX: glowX.value }, { scale: glowScale.value }],
  }));
  return (
    <View style={styles.tabs} accessibilityRole="tablist">
      <View style={styles.tabRow}>
        <Animated.View style={[styles.glow, glowStyle, styles.glowHit]} />
        {navItems.map((item, index) => (
          <NavTab
            key={item.id}
            item={item}
            active={tab === item.id}
            pending={item.id === 'sales' ? pending : 0}
            onPress={() => onChange(item.id)}
            onLayout={(event) => {
              const { x, width } = event.nativeEvent.layout;
              centers.current[index] = { x, width };
              if (index === activeIndex) {
                moveGlow(index, false);
              }
            }}
          />
        ))}
      </View>
    </View>
  );
}

function NavTab({ item, active, pending, onPress, onLayout }) {
  const center = item.id === 'sell';
  const resting = center ? (active ? 1.08 : 1) : active ? 1.4 : 1;
  const emphasis = useSharedValue(resting);
  const pressed = useSharedValue(1);
  useEffect(() => {
    emphasis.value = withSpring(resting, navSpring);
  }, [emphasis, resting]);
  const scaleStyle = useAnimatedStyle(() => ({
    transform: [{ scale: emphasis.value * pressed.value }],
  }));
  const Icon = item.icon;
  return (
    <Pressable
      onLayout={onLayout}
      onPress={onPress}
      onPressIn={() => {
        pressed.value = withSpring(0.92, { stiffness: 420, damping: 18 });
      }}
      onPressOut={() => {
        pressed.value = withSpring(1, navSpring);
      }}
      accessibilityRole="tab"
      accessibilityLabel={item.label}
      accessibilityState={{ selected: active }}
      style={center ? styles.tabSlotCenter : styles.tabSlot}
    >
      <Animated.View style={scaleStyle}>
        <View style={center ? styles.tabGlyphCenter : styles.tabIconWrap}>
          <Icon
            color={center ? colors.white : active ? colors.accent : colors.muted}
            size={center ? 28 : 22}
            strokeWidth={2}
          />
          {pending > 0 ? (
            <View style={styles.badge}>
              <Text style={styles.badgeText}>{pending > 9 ? '9+' : pending}</Text>
            </View>
          ) : null}
        </View>
      </Animated.View>
    </Pressable>
  );
}

export function StatusPill({ status }) {
  const map = {
    pending: ['Waiting', styles.pillWait, styles.pillWaitText],
    attention: ['Check', styles.pillBad, styles.pillBadText],
    synced: ['Synced', styles.pillOk, styles.pillOkText],
    partial: ['Part paid', styles.pillWait, styles.pillWaitText],
    due: ['Due', styles.pillWait, styles.pillWaitText],
    paid: ['Paid', styles.pillOk, styles.pillOkText],
  };
  const [label, box, text] = map[status] || map.pending;
  return (
    <View style={[styles.pill, box]}>
      <Text style={[styles.pillText, text]}>{label}</Text>
    </View>
  );
}

export const SaleCard = memo(function SaleCard({ item, onPress }) {
  return (
    <Pressable
      onPress={onPress ? () => onPress(item) : undefined}
      accessibilityRole={onPress ? 'button' : undefined}
      style={({ pressed }) => [styles.sale, pressed && onPress && styles.pressed]}
    >
      <View style={styles.saleBody}>
        <Text style={styles.saleRef} numberOfLines={1}>
          {item.subtitle}
        </Text>
        <Text style={styles.saleTitle} numberOfLines={1}>
          {item.title}
        </Text>
        <Text style={styles.saleWhen}>{item.whenLabel}</Text>
      </View>
      <View style={styles.saleSide}>
        <Text style={styles.saleAmount}>{money(item.amount)}</Text>
        <StatusPill status={item.status} />
      </View>
    </Pressable>
  );
});

export function Stepper({ quantity, onChange, max }) {
  const atMax = max != null && quantity >= max;
  return (
    <View style={styles.stepper}>
      <Pressable
        onPress={() => onChange(quantity - 1)}
        accessibilityRole="button"
        accessibilityLabel="Decrease quantity"
        hitSlop={6}
        style={({ pressed }) => [styles.step, pressed && styles.pressed]}
      >
        <Text style={styles.stepText}>−</Text>
      </Pressable>
      <Text style={styles.stepQty}>{quantity}</Text>
      <Pressable
        onPress={() => {
          if (!atMax) {
            onChange(quantity + 1);
          }
        }}
        disabled={atMax}
        accessibilityRole="button"
        accessibilityLabel="Increase quantity"
        hitSlop={6}
        style={({ pressed }) => [styles.step, styles.stepOn, pressed && styles.pressed]}
      >
        <Text style={[styles.stepText, styles.stepTextOn]}>+</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: {
    backgroundColor: colors.redSoft,
    borderRadius: 14,
    paddingHorizontal: 14,
    paddingVertical: 12,
    marginBottom: 12,
  },
  bannerText: { color: colors.red, fontSize: 14, lineHeight: 20 },
  search: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.white,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: 14,
    minHeight: 52,
  },
  searchMark: {
    width: 14,
    height: 14,
    borderRadius: 7,
    borderWidth: 2,
    borderColor: colors.faint,
    marginRight: 10,
  },
  searchInput: { flex: 1, fontSize: 16, color: colors.ink, paddingVertical: 12 },
  chip: {
    backgroundColor: colors.chip,
    borderRadius: 999,
    paddingHorizontal: 16,
    paddingVertical: 10,
    marginRight: 8,
  },
  chipOn: { backgroundColor: colors.accent },
  segments: {
    flexDirection: 'row',
    backgroundColor: colors.chip,
    borderRadius: 16,
    padding: 4,
    marginTop: 12,
    marginBottom: 4,
  },
  segmentThumb: {
    position: 'absolute',
    top: 4,
    bottom: 4,
    left: 4,
    borderRadius: 12,
    backgroundColor: colors.white,
    shadowColor: '#1C1917',
    shadowOpacity: 0.08,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
  segment: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    minHeight: 40,
    paddingHorizontal: 6,
  },
  segmentText: { color: colors.muted, fontSize: 14, fontWeight: '600', flexShrink: 1 },
  segmentCount: { fontSize: 12, fontWeight: '700' },
  chipText: { color: colors.ink, fontSize: 14, fontWeight: '600' },
  chipTextOn: { color: colors.white },
  primary: {
    backgroundColor: colors.accent,
    borderRadius: 18,
    minHeight: 54,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 18,
  },
  primaryText: { color: colors.white, fontSize: 16, fontWeight: '700' },
  quiet: {
    backgroundColor: colors.white,
    borderRadius: 18,
    minHeight: 54,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 18,
    borderWidth: 1,
    borderColor: colors.line,
  },
  quietText: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.72 },
  back: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: colors.line,
  },
  chevron: {
    width: 10,
    height: 10,
    borderLeftWidth: 2,
    borderBottomWidth: 2,
    borderColor: colors.ink,
    transform: [{ rotate: '45deg' }],
    marginLeft: 4,
  },
  header: { flexDirection: 'row', alignItems: 'center', marginBottom: 16 },
  headerSide: { width: 72, minHeight: 44, justifyContent: 'center' },
  headerAction: { alignItems: 'flex-end' },
  headerCenter: { flex: 1, alignItems: 'center' },
  headerTitleRow: { flexDirection: 'row', alignItems: 'center', gap: 8, maxWidth: '100%' },
  headerTitle: { fontSize: 18, fontWeight: '700', color: colors.ink, flexShrink: 1 },
  presence: { width: 10, height: 10, borderRadius: 5 },
  presenceOn: { backgroundColor: colors.green },
  presenceOff: { backgroundColor: colors.red },
  headerSub: { marginTop: 2, fontSize: 13, color: colors.muted },
  productOff: { opacity: 0.45 },
  product: {
    flex: 1,
    maxWidth: '50%',
    backgroundColor: colors.card,
    borderRadius: 22,
    padding: 10,
    borderWidth: 1,
    borderColor: colors.line,
  },
  tile: {
    height: 104,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 10,
  },
  monogram: { fontSize: 28, fontWeight: '700', color: TILE_INK, letterSpacing: 0.5 },
  addBadge: {
    position: 'absolute',
    right: 8,
    bottom: 8,
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: colors.accent,
    alignItems: 'center',
    justifyContent: 'center',
  },
  addCount: { color: colors.white, fontWeight: '700', fontSize: 15 },
  plus: { width: 14, height: 14 },
  plusBar: {
    position: 'absolute',
    left: 0,
    top: 6,
    width: 14,
    height: 2,
    borderRadius: 1,
    backgroundColor: colors.white,
  },
  plusBarY: { left: 6, top: 0, width: 2, height: 14 },
  productName: { fontSize: 15, fontWeight: '700', color: colors.ink, minHeight: 40 },
  variation: { color: colors.muted, fontSize: 12, marginTop: 2 },
  price: { marginTop: 6, fontSize: 16, fontWeight: '700', color: colors.ink },
  stock: { marginTop: 2, fontSize: 12, color: colors.muted },
  stockLow: { marginTop: 2, fontSize: 12, color: colors.amber, fontWeight: '600' },
  stockOut: { marginTop: 2, fontSize: 12, color: colors.red, fontWeight: '600' },
  cartBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.white,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: colors.line,
    paddingLeft: 18,
    paddingRight: 8,
    paddingVertical: 8,
    marginBottom: 10,
  },
  cartCount: { color: colors.muted, fontSize: 13 },
  cartTotal: { color: colors.ink, fontSize: 20, fontWeight: '700', marginTop: 2 },
  next: {
    backgroundColor: colors.accent,
    borderRadius: 16,
    minWidth: 108,
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 18,
  },
  nextText: { color: colors.white, fontWeight: '700', fontSize: 16 },
  tabs: {
    backgroundColor: colors.white,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.line,
    shadowColor: '#1C1917',
    shadowOpacity: 0.08,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 4,
  },
  tabRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 14,
    paddingVertical: 8,
    minHeight: 72,
  },
  glow: {
    position: 'absolute',
    top: 10,
    left: 0,
    width: glowSize,
    height: glowSize,
    borderRadius: glowSize / 2,
    backgroundColor: 'rgba(0, 120, 240, 0.16)',
  },
  glowHit: { pointerEvents: 'none' },
  tabSlot: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  tabSlotCenter: { width: 58, height: 58, alignItems: 'center', justifyContent: 'center' },
  tabIconWrap: { width: 28, height: 28, alignItems: 'center', justifyContent: 'center' },
  tabGlyphCenter: {
    width: 52,
    height: 52,
    borderRadius: 26,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.accent,
  },
  badge: {
    position: 'absolute',
    top: -2,
    right: -6,
    minWidth: 16,
    height: 16,
    borderRadius: 8,
    backgroundColor: colors.red,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
  },
  badgeText: { color: colors.white, fontSize: 9, fontWeight: '700' },
  markHome: {
    width: 14,
    height: 14,
    borderRadius: 3,
    borderWidth: 2,
    borderColor: colors.muted,
  },
  markOn: { borderColor: colors.white },
  markSales: { width: 16, height: 14, justifyContent: 'space-between' },
  markLine: { height: 2, borderRadius: 1 },
  markAccount: { alignItems: 'center' },
  markPeople: { width: 14, height: 14, borderRadius: 7, borderWidth: 2 },
  markHead: { width: 8, height: 8, borderRadius: 4, borderWidth: 2 },
  markBody: {
    width: 14,
    height: 7,
    borderTopLeftRadius: 7,
    borderTopRightRadius: 7,
    borderWidth: 2,
    borderBottomWidth: 0,
    marginTop: 2,
  },
  sale: {
    flexDirection: 'row',
    backgroundColor: colors.card,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: colors.line,
    padding: 16,
    marginBottom: 10,
  },
  saleBody: { flex: 1, paddingRight: 12 },
  saleRef: { color: colors.muted, fontSize: 12, fontWeight: '600' },
  saleTitle: { color: colors.ink, fontSize: 16, fontWeight: '700', marginTop: 4 },
  saleWhen: { color: colors.muted, fontSize: 13, marginTop: 4 },
  saleSide: { alignItems: 'flex-end', justifyContent: 'space-between' },
  saleAmount: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4, marginTop: 8 },
  pillText: { fontSize: 12, fontWeight: '700' },
  pillOk: { backgroundColor: colors.greenSoft },
  pillOkText: { color: colors.green },
  pillWait: { backgroundColor: colors.amberSoft },
  pillWaitText: { color: colors.amber },
  pillBad: { backgroundColor: colors.redSoft },
  pillBadText: { color: colors.red },
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.chip,
    borderRadius: 999,
    padding: 4,
  },
  step: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.white,
  },
  stepOn: { backgroundColor: colors.accent },
  stepText: { fontSize: 20, color: colors.ink, fontWeight: '600' },
  stepTextOn: { color: colors.white },
  stepQty: { minWidth: 28, textAlign: 'center', fontWeight: '700', color: colors.ink },
});
