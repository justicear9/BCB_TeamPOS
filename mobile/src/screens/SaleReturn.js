import { useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { Banknote, Wallet } from 'lucide-react-native';
import { money, variationLabel } from '../format';
import { Banner, PrimaryButton, ScreenHeader, Stepper, colors } from '../ui';
import { ChoiceRow, Group, GroupLabel, Hero, SwipeBack, kit } from '../kit';

export default function SaleReturn({ sale, methods, busy, error, online, onBack, onSubmit }) {
  const lines = (sale.lines || []).filter((line) => line.sell_line_id);
  const [counts, setCounts] = useState({});
  const choices = methods.length > 0 ? methods : [{ id: 'cash', label: 'Cash' }];
  const [method, setMethod] = useState(choices.some((item) => item.id === 'cash') ? 'cash' : choices[0].id);

  const chosen = lines
    .map((line) => ({ line, quantity: counts[line.sell_line_id] || 0 }))
    .filter((row) => row.quantity > 0);
  const value = chosen.reduce((sum, row) => sum + row.quantity * Number(row.line.unit_price), 0);
  const itemCount = chosen.reduce((sum, row) => sum + row.quantity, 0);

  return (
    <SwipeBack onBack={onBack}>
      <ScreenHeader title="Return items" subtitle={sale.subtitle} onBack={onBack} online={online} />
      <ScrollView style={styles.fill} contentContainerStyle={kit.scroll} showsVerticalScrollIndicator={false}>
        <Banner message={error} />
        <Hero>
          <Text style={kit.heroLabel}>Coming back</Text>
          <Text style={kit.heroValue} numberOfLines={1} adjustsFontSizeToFit>
            {money(value)}
          </Text>
          <Text style={kit.heroHint}>
            {itemCount} {itemCount === 1 ? 'item' : 'items'} · {sale.title}
          </Text>
        </Hero>

        <GroupLabel>What is coming back</GroupLabel>
        <Group>
          {lines.length === 0 ? (
            <Text style={styles.empty}>Open this sale once while online to load its items.</Text>
          ) : (
            lines.map((line, index) => {
              const left = Math.max(0, Number(line.quantity) - Number(line.quantity_returned || 0));
              const variation = variationLabel(line.variation_name);
              return (
                <View key={line.sell_line_id} style={[styles.item, index < lines.length - 1 && styles.rule]}>
                  <View style={styles.fill}>
                    <Text style={styles.itemName}>{line.name}</Text>
                    <Text style={styles.itemHint}>
                      {[variation, `${money(line.unit_price)} each`, `${Number(line.quantity)} sold`].filter(Boolean).join(' · ')}
                    </Text>
                    {Number(line.quantity_returned) > 0 ? (
                      <Text style={styles.itemHint}>{Number(line.quantity_returned)} already returned</Text>
                    ) : null}
                  </View>
                  {left > 0 ? (
                    <Stepper
                      quantity={counts[line.sell_line_id] || 0}
                      max={left}
                      onChange={(quantity) =>
                        setCounts((current) => ({ ...current, [line.sell_line_id]: Math.max(0, Math.min(quantity, left)) }))
                      }
                    />
                  ) : (
                    <Text style={styles.itemHint}>All returned</Text>
                  )}
                </View>
              );
            })
          )}
        </Group>

        <GroupLabel>Refund with</GroupLabel>
        <ChoiceRow
          choices={choices.map((item) => ({ id: item.id, label: item.label, icon: item.id === 'cash' ? Banknote : Wallet }))}
          value={method}
          onChange={setMethod}
        />
        <Text style={kit.note}>
          Stock goes back on the shelf. If the sale still has a balance, the return lowers it first and only the rest is
          handed back. Any sale discount is shared out.
          {online ? '' : ' You are offline: the return saves on this phone and goes to TeamPOS when you reconnect.'}
        </Text>
      </ScrollView>
      <View style={kit.bar}>
        <PrimaryButton
          label={busy ? 'Saving return' : itemCount ? `Return ${money(value)}` : 'Choose items'}
          disabled={busy || itemCount === 0}
          onPress={() =>
            onSubmit({
              method,
              lines: chosen.map((row) => ({ sell_line_id: row.line.sell_line_id, quantity: row.quantity })),
            })
          }
        />
      </View>
    </SwipeBack>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  item: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14 },
  rule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  itemName: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  itemHint: { color: colors.muted, fontSize: 13, fontWeight: '600', marginTop: 2 },
  empty: { color: colors.muted, fontSize: 14, paddingVertical: 16 },
});
