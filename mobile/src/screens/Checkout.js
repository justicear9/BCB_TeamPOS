import { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { BadgePercent, Banknote, ChevronRight, Gift, HandCoins, Pencil, User, Wallet } from 'lucide-react-native';
import {
  creditAllowed,
  customerHeading,
  money,
  saleTotals,
  settlePayments,
  tracksStock,
  variationLabel,
} from '../format';
import { Banner, PrimaryButton, QuietButton, ScreenHeader, Segments, Stepper, colors } from '../ui';
import { ChoiceRow, Group, GroupLabel, Hero, KeyboardScreen, MoneyField, Pill, Row, Sheet, SwipeBack, kit } from '../kit';

const discountKinds = [
  { id: 'fixed', label: 'Amount' },
  { id: 'percentage', label: 'Percent' },
];

export function pointsLimit(customer, rewards, subtotal) {
  if (!rewards || !customer || Number(customer.is_default) === 1) {
    return 0;
  }
  let max = Number(customer.reward_points) || 0;
  if (rewards.max_redeem_points != null) {
    max = Math.min(max, Number(rewards.max_redeem_points));
  }
  if (rewards.min_redeem_points != null && max < Number(rewards.min_redeem_points)) {
    return 0;
  }
  if (Number(rewards.min_order_total) > 0 && subtotal < Number(rewards.min_order_total)) {
    return 0;
  }
  const perPoint = Number(rewards.amount_per_point) || 0;
  if (perPoint > 0) {
    max = Math.min(max, Math.floor(subtotal / perPoint));
  }
  return Math.max(0, max);
}

export default function Checkout({
  cart,
  products,
  customer,
  methods,
  tenders,
  onTenders,
  discount,
  onDiscount,
  points,
  onPoints,
  settings,
  saving,
  error,
  onBack,
  onCustomer,
  onQuantity,
  onPrice,
  onSave,
  online,
}) {
  const [sure, setSure] = useState(false);
  const [editing, setEditing] = useState(null);
  const [draft, setDraft] = useState('');
  const [draftKind, setDraftKind] = useState('fixed');

  const rewards = settings?.rewards || null;
  const totals = saleTotals(cart, discount, points, rewards);
  const total = totals.total;
  const choices = methods.length > 0 ? methods : [{ id: 'cash', label: 'Cash' }];
  const canCredit = settings?.can_sell_on_credit !== false;
  const selected = tenders[0]?.method || choices[0].id;
  const settlement = settlePayments(total, tenders);
  const credit = canCredit
    ? creditAllowed(customer, settlement.due)
    : settlement.due > 0.009
      ? { ok: false, reason: 'Your account cannot sell on credit.' }
      : { ok: true, reason: '' };
  const creditSale = creditAllowed(customer, total);
  const blocked = Boolean(settlement.error) || !credit.ok || cart.length === 0;
  const heading = customer ? customerHeading(customer) : { title: 'Choose a customer', subtitle: '' };
  const count = cart.reduce((sum, line) => sum + line.quantity, 0);
  const maxPoints = pointsLimit(customer, rewards, totals.subtotal - totals.discountAmount);
  const methodLabel = choices.find((method) => method.id === selected)?.label || 'Cash';

  useEffect(() => {
    if (selected === 'credit' && !creditSale.ok) {
      const cash = choices.find((method) => method.id === 'cash') || choices[0];
      onTenders([{ method: cash.id, received: total.toFixed(2) }]);
    }
  }, [selected, creditSale.ok]);

  function chooseMethod(id) {
    onTenders(id === 'credit' ? [{ method: 'credit', received: '' }] : [{ method: id, received: total.toFixed(2) }]);
  }

  function setReceived(value) {
    onTenders([{ method: selected, received: value }]);
  }

  function openPrice(line) {
    setDraft(String(line.unit_price));
    setEditing({ kind: 'price', line });
  }

  function openDiscount() {
    setDraft(Number(discount?.amount) > 0 ? String(discount.amount) : '');
    setDraftKind(discount?.type || 'fixed');
    setEditing({ kind: 'discount' });
  }

  function openPoints() {
    setDraft(Number(points) > 0 ? String(points) : '');
    setEditing({ kind: 'points' });
  }

  function syncTender(nextTotal) {
    if (selected !== 'credit') {
      onTenders([{ method: selected, received: nextTotal.toFixed(2) }]);
    }
  }

  const draftNumber = Number(draft) || 0;
  let draftProblem = '';
  if (editing?.kind === 'discount') {
    if (draftKind === 'percentage' && draftNumber > 100) {
      draftProblem = 'A discount cannot be more than 100%.';
    } else if (draftKind === 'fixed' && draftNumber > totals.subtotal) {
      draftProblem = 'The discount is more than the items.';
    }
  } else if (editing?.kind === 'points' && draftNumber > maxPoints) {
    draftProblem = `Up to ${maxPoints} ${rewards?.name || 'points'} can be used on this sale.`;
  } else if (editing?.kind === 'price' && !(draftNumber > 0)) {
    draftProblem = 'Enter a price greater than zero.';
  }

  let payLine = `${methodLabel} ${money(settlement.paid)}`;
  if (selected === 'credit') {
    payLine = `On credit ${money(total)}`;
  } else if (settlement.due > 0.009) {
    payLine = `${methodLabel} ${money(settlement.paid)} · On credit ${money(settlement.due)}`;
  } else if (settlement.change > 0.009) {
    payLine = `${methodLabel} ${money(tenders[0]?.received)} · Change ${money(settlement.change)}`;
  }

  return (
    <SwipeBack onBack={onBack}>
      <ScreenHeader
        title="Checkout"
        subtitle={`${count} ${count === 1 ? 'item' : 'items'}`}
        onBack={onBack}
        online={online}
      />
      <KeyboardScreen>
        <ScrollView
          style={styles.fill}
          contentContainerStyle={kit.scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
          showsVerticalScrollIndicator={false}
        >
          <Banner message={error} />
          <Hero>
            <Text style={kit.heroLabel}>Amount to collect</Text>
            <Text style={kit.heroValue} numberOfLines={1} adjustsFontSizeToFit>
              {money(total)}
            </Text>
            {totals.discountAmount > 0 || totals.pointsAmount > 0 ? (
              <Text style={kit.heroHint}>
                {[
                  `Items ${money(totals.subtotal)}`,
                  totals.discountAmount > 0 ? `Discount −${money(totals.discountAmount)}` : '',
                  totals.pointsAmount > 0 ? `Points −${money(totals.pointsAmount)}` : '',
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </Text>
            ) : null}
            <Pressable
              onPress={onCustomer}
              accessibilityRole="button"
              accessibilityLabel="Change customer"
              style={({ pressed }) => [kit.heroChip, pressed && styles.pressed]}
            >
              <User color="#BFDBFE" size={15} strokeWidth={2.25} />
              <Text style={kit.heroChipText} numberOfLines={1}>
                {heading.title}
                {customer?.is_default && !/walk-?in/i.test(heading.title) ? ' · Walk-in' : ''}
              </Text>
              <ChevronRight color="#BFDBFE" size={15} strokeWidth={2.25} />
            </Pressable>
            <View style={kit.heroStats}>
              <View style={kit.heroStat}>
                <Text style={kit.heroStatValue} numberOfLines={1} adjustsFontSizeToFit>
                  {money(settlement.paid)}
                </Text>
                <Text style={kit.heroStatLabel}>paid now</Text>
              </View>
              <View style={kit.heroDivider} />
              <View style={kit.heroStat}>
                <Text
                  style={[kit.heroStatValue, settlement.due > 0.009 && styles.heroAmber]}
                  numberOfLines={1}
                  adjustsFontSizeToFit
                >
                  {money(settlement.due > 0.009 ? settlement.due : settlement.change)}
                </Text>
                <Text style={kit.heroStatLabel}>{settlement.due > 0.009 ? 'on credit' : 'change'}</Text>
              </View>
            </View>
          </Hero>

          <GroupLabel>Items</GroupLabel>
          <Group>
            {cart.map((line, index) => {
              const live = products.find((product) => product.variation_id === line.variation_id);
              const tracked = tracksStock(live || line);
              const onHand = tracked ? Number(live?.qty_available ?? line.qty_available) : null;
              const variation = variationLabel(line.variation_name);
              const changed = Boolean(line.price_override);
              return (
                <View key={line.variation_id} style={[styles.item, index < cart.length - 1 && styles.rule]}>
                  <View style={styles.fill}>
                    <Text style={styles.itemName} numberOfLines={2}>
                      {line.name}
                    </Text>
                    {variation ? <Text style={styles.itemHint}>{variation}</Text> : null}
                    <Pressable
                      onPress={settings?.can_override_price ? () => openPrice(line) : undefined}
                      disabled={!settings?.can_override_price}
                      accessibilityRole={settings?.can_override_price ? 'button' : undefined}
                      accessibilityLabel={`Price of ${line.name}`}
                      style={styles.priceRow}
                      hitSlop={6}
                    >
                      <Text style={[styles.itemHint, changed && styles.changed]}>{money(line.unit_price)} each</Text>
                      {settings?.can_override_price ? <Pencil color={colors.accent} size={13} strokeWidth={2.25} /> : null}
                      {changed ? <Pill label="Price changed" tone="amber" /> : null}
                    </Pressable>
                    {tracked && line.quantity > onHand ? <Text style={styles.warnSmall}>Only {onHand} on hand.</Text> : null}
                  </View>
                  <View style={styles.itemSide}>
                    <Text style={styles.itemTotal}>{money(line.quantity * line.unit_price)}</Text>
                    <Stepper
                      quantity={line.quantity}
                      max={tracked ? onHand : undefined}
                      onChange={(quantity) => onQuantity(line.variation_id, quantity)}
                    />
                  </View>
                </View>
              );
            })}
          </Group>

          {settings?.can_discount !== false || maxPoints > 0 || Number(points) > 0 ? (
            <>
              <GroupLabel>Adjustments</GroupLabel>
              <Group>
                {settings?.can_discount !== false ? (
                  <Row
                    icon={BadgePercent}
                    label="Discount"
                    hint={Number(discount?.amount) > 0 && discount.type === 'percentage' ? `${discount.amount}% off the items` : null}
                    value={totals.discountAmount > 0 ? `−${money(totals.discountAmount)}` : 'None'}
                    valueTone={totals.discountAmount > 0 ? colors.green : undefined}
                    onPress={openDiscount}
                    last={!(maxPoints > 0 || Number(points) > 0)}
                  />
                ) : null}
                {maxPoints > 0 || Number(points) > 0 ? (
                  <Row
                    icon={Gift}
                    tint={colors.greenSoft}
                    color={colors.green}
                    label={`Use ${rewards?.name || 'points'}`}
                    hint={`${customer?.reward_points || 0} available`}
                    value={Number(points) > 0 ? `${points} · −${money(totals.pointsAmount)}` : 'None'}
                    valueTone={Number(points) > 0 ? colors.green : undefined}
                    onPress={openPoints}
                    last
                  />
                ) : null}
              </Group>
            </>
          ) : null}

          <GroupLabel>Payment</GroupLabel>
          <ChoiceRow
            choices={[
              ...choices.map((method) => ({ id: method.id, label: method.label, icon: method.id === 'cash' ? Banknote : Wallet })),
              ...(canCredit ? [{ id: 'credit', label: 'Credit', icon: HandCoins, disabled: !creditSale.ok }] : []),
            ]}
            value={selected}
            onChange={chooseMethod}
          />
          {selected === 'credit' ? (
            <Text style={kit.note}>The full amount stays on the customer’s account.</Text>
          ) : (
            <>
              <MoneyField label="Amount received" value={tenders[0]?.received || ''} onChange={setReceived} />
            </>
          )}
          {canCredit && !creditSale.ok && selected !== 'credit' && settlement.due <= 0.009 ? (
            <Text style={kit.note}>Credit is off: {creditSale.reason.charAt(0).toLowerCase() + creditSale.reason.slice(1)}</Text>
          ) : null}
          {credit.reason ? <Text style={kit.note}>{credit.reason}</Text> : null}
          {settlement.error ? <Text style={kit.warn}>{settlement.error}</Text> : null}
        </ScrollView>
        <View style={kit.bar}>
          <PrimaryButton
            label={saving ? 'Saving' : `Charge ${money(total)}`}
            onPress={() => setSure(true)}
            disabled={saving || blocked}
          />
        </View>
      </KeyboardScreen>

      <Sheet visible={sure} title="Save this sale?" onClose={() => setSure(false)}>
        <Text style={styles.confirmWho}>{heading.title}</Text>
        <Text style={styles.confirmTotal}>{money(total)}</Text>
        <Text style={kit.note}>{payLine}</Text>
        <View style={kit.actions}>
          <View style={kit.action}>
            <QuietButton label="Not yet" onPress={() => setSure(false)} disabled={saving} />
          </View>
          <View style={kit.action}>
            <PrimaryButton
              label={saving ? 'Saving' : 'Save sale'}
              onPress={() => {
                setSure(false);
                onSave();
              }}
              disabled={saving}
            />
          </View>
        </View>
      </Sheet>

      <Sheet
        visible={editing?.kind === 'price'}
        title="Change price"
        subtitle={editing?.line ? `${editing.line.name} usually sells for ${money(editing.line.list_price ?? editing.line.unit_price)}.` : ''}
        onClose={() => setEditing(null)}
      >
        <MoneyField label="Price each" value={draft} onChange={setDraft} autoFocus />
        {draftProblem ? <Text style={kit.warn}>{draftProblem}</Text> : null}
        <View style={kit.actions}>
          <View style={kit.action}>
            <QuietButton
              label="Usual price"
              onPress={() => {
                onPrice(editing.line.variation_id, null);
                setEditing(null);
              }}
            />
          </View>
          <View style={kit.action}>
            <PrimaryButton
              label="Use price"
              disabled={Boolean(draftProblem)}
              onPress={() => {
                onPrice(editing.line.variation_id, draftNumber);
                setEditing(null);
              }}
            />
          </View>
        </View>
      </Sheet>

      <Sheet visible={editing?.kind === 'discount'} title="Discount" subtitle="Taken off the items total." onClose={() => setEditing(null)}>
        <View style={styles.sheetGap}>
          <Segments options={discountKinds} value={draftKind} onChange={setDraftKind} />
        </View>
        <MoneyField
          label={draftKind === 'percentage' ? 'Percent off' : 'Amount off'}
          value={draft}
          onChange={setDraft}
          prefix={draftKind === 'percentage' ? '' : 'GH₵'}
          suffix={draftKind === 'percentage' ? '%' : ''}
          autoFocus
        />
        {draftProblem ? <Text style={kit.warn}>{draftProblem}</Text> : null}
        <View style={kit.actions}>
          <View style={kit.action}>
            <QuietButton
              label="Remove"
              onPress={() => {
                onDiscount({ type: 'fixed', amount: 0 });
                syncTender(saleTotals(cart, null, points, rewards).total);
                setEditing(null);
              }}
            />
          </View>
          <View style={kit.action}>
            <PrimaryButton
              label="Apply"
              disabled={Boolean(draftProblem) || !(draftNumber > 0)}
              onPress={() => {
                const next = { type: draftKind, amount: draftNumber };
                onDiscount(next);
                syncTender(saleTotals(cart, next, points, rewards).total);
                setEditing(null);
              }}
            />
          </View>
        </View>
      </Sheet>

      <Sheet
        visible={editing?.kind === 'points'}
        title={`Use ${rewards?.name || 'points'}`}
        subtitle={`Each point takes ${money(rewards?.amount_per_point || 0)} off. Up to ${maxPoints} on this sale.`}
        onClose={() => setEditing(null)}
      >
        <MoneyField label="Points to use" value={draft} onChange={setDraft} prefix="" suffix="pts" autoFocus />
        {draftProblem ? <Text style={kit.warn}>{draftProblem}</Text> : null}
        <View style={kit.actions}>
          <View style={kit.action}>
            <QuietButton
              label="None"
              onPress={() => {
                onPoints(0);
                syncTender(saleTotals(cart, discount, 0, rewards).total);
                setEditing(null);
              }}
            />
          </View>
          <View style={kit.action}>
            <PrimaryButton
              label="Use points"
              disabled={Boolean(draftProblem) || !(draftNumber > 0)}
              onPress={() => {
                const next = Math.floor(draftNumber);
                onPoints(next);
                syncTender(saleTotals(cart, discount, next, rewards).total);
                setEditing(null);
              }}
            />
          </View>
        </View>
      </Sheet>
    </SwipeBack>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  pressed: { opacity: 0.72 },
  heroAmber: { color: '#FCD34D' },
  item: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14 },
  rule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  itemName: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  itemHint: { color: colors.muted, fontSize: 13, fontWeight: '600' },
  priceRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 4, flexWrap: 'wrap' },
  changed: { color: colors.amber },
  warnSmall: { color: colors.red, fontSize: 12, fontWeight: '600', marginTop: 4 },
  itemSide: { alignItems: 'flex-end', gap: 8 },
  itemTotal: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  confirmWho: { color: colors.ink, fontSize: 16, fontWeight: '600', marginTop: 12 },
  confirmTotal: { color: colors.ink, fontSize: 34, fontWeight: '800', letterSpacing: -0.5, marginTop: 4 },
  sheetGap: { marginTop: 16 },
});
