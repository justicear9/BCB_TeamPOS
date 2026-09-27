import { useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import {
  BadgePercent,
  Banknote,
  Clock,
  Gift,
  Hash,
  MapPin,
  RotateCcw,
  User,
  UserRound,
  Wallet,
} from 'lucide-react-native';
import { money, variationLabel } from '../format';
import { Banner, PrimaryButton, QuietButton, ScreenHeader, colors } from '../ui';
import { ChoiceRow, Group, GroupLabel, Hero, KeyboardScreen, MoneyField, Pill, Row, SwipeBack, kit } from '../kit';

function placeText(sale) {
  const lat = Number(sale?.latitude);
  const lng = Number(sale?.longitude);
  if (sale?.latitude == null || sale.latitude === '' || !Number.isFinite(lat) || !Number.isFinite(lng)) {
    return 'Not captured';
  }
  const accuracy = Number(sale.accuracy);
  const within = Number.isFinite(accuracy) && sale.accuracy !== '' ? ` · ±${Math.round(accuracy)} m` : '';
  return `${lat.toFixed(4)}, ${lng.toFixed(4)}${within}`;
}

function heroState(sale, due) {
  if (sale.status === 'attention') {
    return { tone: 'navy', label: 'Needs attention', pill: ['Not synced', 'red'] };
  }
  if (sale.status === 'pending') {
    return { tone: 'navy', label: 'Waiting to sync', pill: ['On this phone', 'accent'] };
  }
  if (due > 0.009) {
    return { tone: 'navy', label: Number(sale.paid) > 0.009 ? 'Part paid' : 'Not paid', pill: [`${money(due)} owing`, 'amber'] };
  }
  return { tone: 'green', label: 'Paid in full', pill: ['Paid', 'green'] };
}

export default function SaleView({
  sale,
  methods,
  syncing,
  printing,
  error,
  onBack,
  onPay,
  onPrint,
  online,
  canPay = true,
  canReturn = false,
  onReturn,
}) {
  const returned = Number(sale.returned || 0);
  const due = Math.max(0, Number(sale.amount) - returned - Number(sale.paid || 0));
  const choices = methods.length > 0 ? methods : [{ id: 'cash', label: 'Cash' }];
  const [amount, setAmount] = useState(due > 0.009 ? due.toFixed(2) : '');
  const [method, setMethod] = useState(choices[0].id);
  const labelFor = (id) => choices.find((item) => item.id === id)?.label || id;
  const lines = sale.lines || [];
  const payments = sale.payments || [];
  const itemCount = lines.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
  const itemsTotal = lines.reduce((sum, line) => sum + Number(line.quantity) * Number(line.unit_price), 0);
  const discount = Number(sale.discountAmount || 0);
  const pointsOff = Number(sale.pointsAmount || 0);
  const returnable =
    canReturn && sale.serverId && lines.some((line) => line.sell_line_id && Number(line.quantity) - Number(line.quantity_returned || 0) > 0);
  const state = heroState(sale, due);

  useEffect(() => {
    setAmount(due > 0.009 ? due.toFixed(2) : '');
  }, [due]);

  return (
    <SwipeBack onBack={onBack}>
      <ScreenHeader title={sale.subtitle || 'Sale'} subtitle={sale.whenLabel} onBack={onBack} online={online} />
      <KeyboardScreen>
        <ScrollView
          style={styles.fill}
          contentContainerStyle={kit.scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
          showsVerticalScrollIndicator={false}
        >
          <Banner message={error || sale.detail} />
          {sale.notice ? <Text style={styles.notice}>{sale.notice}</Text> : null}
          <Hero tone={state.tone}>
            <View style={styles.heroTop}>
              <Text style={kit.heroLabel}>{state.label}</Text>
              <Pill label={state.pill[0]} tone={state.pill[1]} />
            </View>
            <Text style={kit.heroValue} numberOfLines={1} adjustsFontSizeToFit>
              {money(sale.amount)}
            </Text>
            <View style={kit.heroChip}>
              <User color="#BFDBFE" size={15} strokeWidth={2.25} />
              <Text style={kit.heroChipText} numberOfLines={1}>
                {sale.title}
              </Text>
            </View>
            <View style={kit.heroStats}>
              <View style={kit.heroStat}>
                <Text style={kit.heroStatValue} numberOfLines={1} adjustsFontSizeToFit>
                  {money(sale.paid || 0)}
                </Text>
                <Text style={kit.heroStatLabel}>paid</Text>
              </View>
              <View style={kit.heroDivider} />
              <View style={kit.heroStat}>
                <Text style={[kit.heroStatValue, due > 0.009 && styles.amber]} numberOfLines={1} adjustsFontSizeToFit>
                  {money(due)}
                </Text>
                <Text style={kit.heroStatLabel}>balance</Text>
              </View>
              {returned > 0.009 ? (
                <>
                  <View style={kit.heroDivider} />
                  <View style={kit.heroStat}>
                    <Text style={kit.heroStatValue} numberOfLines={1} adjustsFontSizeToFit>
                      {money(returned)}
                    </Text>
                    <Text style={kit.heroStatLabel}>returned</Text>
                  </View>
                </>
              ) : null}
            </View>
          </Hero>
          {sale.serverTotal != null ? (
            <Text style={kit.warn}>TeamPOS recorded {money(sale.serverTotal)} for this sale.</Text>
          ) : null}

          <GroupLabel>{itemCount > 0 ? `Items · ${itemCount}` : 'Items'}</GroupLabel>
          <Group>
            {sale.loading && lines.length === 0 ? (
              <View style={styles.loading}>
                <ActivityIndicator color={colors.accent} />
              </View>
            ) : null}
            {!sale.loading && lines.length === 0 ? (
              <Text style={styles.empty}>Connect to load the items on this sale.</Text>
            ) : null}
            {lines.map((line, index) => {
              const qty = Number(line.quantity);
              const back = Number(line.quantity_returned || 0);
              const variation = variationLabel(line.variation_name);
              return (
                <View key={`${line.sell_line_id || line.variation_id || line.name}-${index}`} style={[styles.line, styles.rule]}>
                  <View style={styles.qty}>
                    <Text style={styles.qtyText}>{qty % 1 === 0 ? qty : qty.toFixed(2)}×</Text>
                  </View>
                  <View style={styles.fill}>
                    <Text style={styles.lineName} numberOfLines={2}>
                      {line.name || 'Item'}
                    </Text>
                    <Text style={styles.lineHint}>
                      {[variation, `${money(line.unit_price)} each`].filter(Boolean).join(' · ')}
                    </Text>
                    {back > 0 ? (
                      <View style={styles.linePill}>
                        <Pill label={`${back} returned`} tone="red" />
                      </View>
                    ) : null}
                  </View>
                  <Text style={styles.lineTotal}>{money(qty * Number(line.unit_price))}</Text>
                </View>
              );
            })}
            {lines.length > 0 ? (
              <>
                {discount > 0.009 || pointsOff > 0.009 ? (
                  <Row label="Items" value={money(itemsTotal)} />
                ) : null}
                {discount > 0.009 ? (
                  <Row icon={BadgePercent} tint={colors.greenSoft} color={colors.green} label="Discount" value={`−${money(discount)}`} valueTone={colors.green} />
                ) : null}
                {pointsOff > 0.009 ? (
                  <Row icon={Gift} tint={colors.greenSoft} color={colors.green} label="Points" value={`−${money(pointsOff)}`} valueTone={colors.green} />
                ) : null}
                <View style={styles.totalRow}>
                  <Text style={styles.totalLabel}>Total</Text>
                  <Text style={styles.totalValue}>{money(sale.amount)}</Text>
                </View>
              </>
            ) : null}
          </Group>

          <GroupLabel>Payments</GroupLabel>
          <Group>
            {payments.length === 0 ? <Row icon={Wallet} tint={colors.chip} color={colors.muted} label="No payments yet" last /> : null}
            {payments.map((payment, index) => (
              <Row
                key={`${payment.method}-${index}`}
                icon={payment.is_return ? RotateCcw : payment.method === 'cash' ? Banknote : Wallet}
                tint={payment.is_return ? colors.chip : colors.greenSoft}
                color={payment.is_return ? colors.muted : colors.green}
                label={payment.is_return ? 'Change given' : labelFor(payment.method)}
                value={`${payment.is_return ? '−' : ''}${money(payment.amount)}`}
                valueTone={payment.is_return ? undefined : colors.ink}
                last={index === payments.length - 1}
              />
            ))}
          </Group>

          {due > 0.009 && canPay ? (
            <>
              <GroupLabel>Take a payment</GroupLabel>
              <ChoiceRow
                choices={choices.map((item) => ({ id: item.id, label: item.label, icon: item.id === 'cash' ? Banknote : Wallet }))}
                value={method}
                onChange={setMethod}
              />
              <MoneyField label="Amount received" value={amount} onChange={setAmount} />
              {Number(amount) - due > 0.009 ? <Text style={kit.warn}>The balance is {money(due)}.</Text> : null}
              {!sale.serverId ? (
                <Text style={kit.note}>
                  {sale.status === 'attention'
                    ? 'This payment is added to the sale and the sale is sent to TeamPOS again.'
                    : 'This sale has not reached TeamPOS yet. The payment goes with it.'}
                </Text>
              ) : null}
              <View style={styles.payButton}>
                <PrimaryButton
                  label={syncing ? 'Saving' : `Save ${money(Number(amount) || 0)} payment`}
                  disabled={syncing || !(Number(amount) > 0) || Number(amount) - due > 0.009}
                  onPress={() => onPay(Number(amount), method)}
                />
              </View>
            </>
          ) : null}

          <GroupLabel>Details</GroupLabel>
          <Group>
            <Row icon={Hash} label="Sale number" value={sale.subtitle || '—'} />
            <Row icon={Clock} label="Rung up" value={sale.whenLabel || '—'} />
            {sale.cashier ? <Row icon={UserRound} label="Cashier" value={sale.cashier} /> : null}
            <Row icon={MapPin} label="Place" value={placeText(sale)} last />
          </Group>
        </ScrollView>
        <View style={[kit.actions, styles.bar]}>
          {returnable ? (
            <View style={kit.action}>
              <QuietButton label="Return items" onPress={onReturn} />
            </View>
          ) : null}
          <View style={kit.action}>
            <PrimaryButton label={printing ? 'Opening print' : 'Print receipt'} onPress={onPrint} disabled={printing} />
          </View>
        </View>
      </KeyboardScreen>
    </SwipeBack>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  amber: { color: '#FCD34D' },
  heroTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  notice: {
    color: colors.green,
    backgroundColor: colors.greenSoft,
    borderRadius: 16,
    padding: 12,
    fontSize: 14,
    fontWeight: '600',
    marginBottom: 10,
    overflow: 'hidden',
  },
  loading: { paddingVertical: 20 },
  empty: { color: colors.muted, fontSize: 14, paddingVertical: 16 },
  line: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  rule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  qty: {
    minWidth: 40,
    height: 40,
    paddingHorizontal: 6,
    borderRadius: 12,
    backgroundColor: colors.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  qtyText: { color: colors.accent, fontSize: 14, fontWeight: '800' },
  lineName: { color: colors.ink, fontSize: 15, fontWeight: '700' },
  lineHint: { color: colors.muted, fontSize: 13, fontWeight: '600', marginTop: 2 },
  linePill: { marginTop: 6 },
  lineTotal: { color: colors.ink, fontSize: 15, fontWeight: '700' },
  totalRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', paddingVertical: 14 },
  totalLabel: { color: colors.ink, fontSize: 16, fontWeight: '800' },
  totalValue: { color: colors.ink, fontSize: 20, fontWeight: '800' },
  payButton: { marginTop: 12 },
  bar: { marginTop: 0, paddingTop: 10, paddingBottom: 4 },
});
