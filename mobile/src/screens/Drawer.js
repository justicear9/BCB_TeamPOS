import { useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Banknote, Clock, RotateCcw, Wallet } from 'lucide-react-native';
import { money, prettyDate } from '../format';
import { Banner, PrimaryButton, ScreenHeader, colors } from '../ui';
import { Group, GroupLabel, Hero, KeyboardScreen, MoneyField, Row, SwipeBack, kit } from '../kit';

/**
 * The cashier's TeamPOS cash register: open it with the float in the drawer,
 * close it by counting the cash at the end of the shift.
 */
export default function Drawer({ register, methods, canClose, busy, error, online, locationName, onBack, onOpen, onClose }) {
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');
  const [closed, setClosed] = useState(null);
  const open = Boolean(register?.open);
  const expected = Number(register?.expected_cash || 0);
  const counted = Number(amount);
  const difference = amount === '' ? null : Math.round((counted - expected) * 100) / 100;
  const labelFor = (id) => methods.find((method) => method.id === id)?.label || id;

  if (closed) {
    const diff = Number(closed.difference || 0);
    return (
      <SwipeBack onBack={onBack}>
        <ScreenHeader title="Register closed" onBack={onBack} online={online} />
        <ScrollView style={styles.fill} contentContainerStyle={kit.scroll}>
          <Hero tone={Math.abs(diff) < 0.01 ? 'green' : 'navy'}>
            <Text style={kit.heroLabel}>Counted</Text>
            <Text style={kit.heroValue}>{money(closed.closing_cash)}</Text>
            <Text style={kit.heroHint}>
              {Math.abs(diff) < 0.01 ? 'The drawer matches.' : diff > 0 ? `${money(diff)} over` : `${money(-diff)} short`}
            </Text>
          </Hero>
          <GroupLabel>Shift</GroupLabel>
          <Group>
            <Row icon={Clock} label="Opened" value={prettyDate(closed.opened_at)} />
            <Row icon={Banknote} label="Expected cash" value={money(closed.expected_cash)} />
            <Row icon={Wallet} label="Sales" value={money(closed.total_sales)} last />
          </Group>
        </ScrollView>
        <View style={kit.bar}>
          <PrimaryButton label="Done" onPress={onBack} />
        </View>
      </SwipeBack>
    );
  }

  return (
    <SwipeBack onBack={onBack}>
      <ScreenHeader title="Cash register" subtitle={locationName} onBack={onBack} online={online} />
      <KeyboardScreen>
        <ScrollView
          style={styles.fill}
          contentContainerStyle={kit.scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
        >
          <Banner message={error || (!online ? 'Opening or closing the register needs a connection.' : '')} />
          <Hero tone={open ? 'green' : 'navy'}>
            <Text style={kit.heroLabel}>{open ? 'Open' : 'Closed'}</Text>
            <Text style={kit.heroValue} numberOfLines={1} adjustsFontSizeToFit>
              {open ? money(expected) : 'No shift'}
            </Text>
            <Text style={kit.heroHint}>
              {open ? `Cash expected in the drawer · since ${prettyDate(register.opened_at)}` : 'Count the float and open the register to start.'}
            </Text>
          </Hero>

          {open ? (
            <>
              <GroupLabel>This shift</GroupLabel>
              <Group>
                <Row icon={Banknote} label="Opening float" value={money(register.opening_cash)} />
                {(register.by_method || []).map((row) => (
                  <Row key={row.method} icon={row.method === 'cash' ? Banknote : Wallet} label={labelFor(row.method)} value={money(row.total)} />
                ))}
                <Row icon={RotateCcw} tint={colors.redSoft} color={colors.red} label="Refunds" value={money(register.total_refunds)} last />
              </Group>
              {canClose ? (
                <>
                  <MoneyField label="Cash counted in the drawer" value={amount} onChange={setAmount} />
                  {difference !== null ? (
                    <Text style={[styles.diff, Math.abs(difference) < 0.01 ? styles.ok : styles.off]}>
                      {Math.abs(difference) < 0.01
                        ? 'Matches what the register expects.'
                        : difference > 0
                          ? `${money(difference)} more than expected.`
                          : `${money(-difference)} less than expected.`}
                    </Text>
                  ) : null}
                  <Text style={styles.noteLabel}>Note (optional)</Text>
                  <TextInput
                    value={note}
                    onChangeText={setNote}
                    placeholder="Anything the manager should know"
                    placeholderTextColor={colors.faint}
                    style={styles.note}
                    multiline
                  />
                </>
              ) : (
                <Text style={kit.note}>Ask a manager to close this register.</Text>
              )}
            </>
          ) : (
            <MoneyField label="Float in the drawer" value={amount} onChange={setAmount} placeholder="0.00" />
          )}
          <Text style={kit.note}>
            Selling needs an open register. It shows in TeamPOS too, so a manager can close it there. If it is still open
            at 9 PM, this phone reminds you to close it.
          </Text>
        </ScrollView>
        <View style={kit.bar}>
          {open ? (
            canClose ? (
              <PrimaryButton
                label={busy ? 'Closing' : 'Close register'}
                disabled={busy || !online || amount === ''}
                onPress={async () => {
                  const result = await onClose(counted, note);
                  if (result) {
                    setClosed(result);
                  }
                }}
              />
            ) : null
          ) : (
            <PrimaryButton
              label={busy ? 'Opening' : 'Open register'}
              disabled={busy || !online}
              onPress={() => onOpen(Number(amount) || 0)}
            />
          )}
        </View>
      </KeyboardScreen>
    </SwipeBack>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  diff: { fontSize: 14, fontWeight: '700', marginTop: 10 },
  ok: { color: colors.green },
  off: { color: colors.amber },
  noteLabel: { color: colors.muted, fontSize: 12, fontWeight: '700', letterSpacing: 0.6, textTransform: 'uppercase', marginTop: 16, marginBottom: 8 },
  note: {
    minHeight: 80,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: 18,
    padding: 14,
    color: colors.ink,
    fontSize: 15,
    textAlignVertical: 'top',
  },
});
