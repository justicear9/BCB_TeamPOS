import { useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { BellRing, Lock } from 'lucide-react-native';
import { Banner, PrimaryButton, ScreenHeader, colors } from '../ui';
import { Group, GroupLabel, Hero, KeyboardScreen, MoneyField, Row, kit } from '../kit';

/**
 * Selling needs an open register. Shown on the Register tab until the cashier
 * opens one here or in TeamPOS.
 */
export default function RegisterGate({ businessName, locationName, online, busy, error, onOpen }) {
  const [amount, setAmount] = useState('');
  return (
    <View style={styles.fill}>
      <ScreenHeader title={businessName || 'Register'} subtitle={locationName} online={online} />
      <KeyboardScreen>
        <ScrollView
          style={styles.fill}
          contentContainerStyle={kit.scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
          showsVerticalScrollIndicator={false}
        >
          <Banner message={error || (!online ? 'Connect to open the register. Selling starts once it is open.' : '')} />
          <Hero>
            <View style={styles.lock}>
              <Lock color={colors.white} size={20} strokeWidth={2.25} />
            </View>
            <Text style={[kit.heroLabel, styles.gap]}>Register closed</Text>
            <Text style={styles.title}>Open the register to start selling</Text>
            <Text style={kit.heroHint}>Count the cash in the drawer and enter it as the float.</Text>
          </Hero>
          <MoneyField label="Float in the drawer" value={amount} onChange={setAmount} />
          <GroupLabel>How the day works</GroupLabel>
          <Group>
            <Row
              icon={BellRing}
              label="Reminder at 9 PM"
              hint="If the register is still open, this phone reminds you to count and close it."
            />
            <Row
              icon={Lock}
              tint={colors.amberSoft}
              color={colors.amber}
              label="Close it when you finish"
              hint="Count the drawer and close the register here or in TeamPOS. It stays open until then."
              last
            />
          </Group>
        </ScrollView>
        <View style={[kit.bar, styles.bar]}>
          <PrimaryButton
            label={busy ? 'Opening' : 'Open register'}
            disabled={busy || !online}
            onPress={() => onOpen(Number(amount) || 0)}
          />
        </View>
      </KeyboardScreen>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  gap: { marginTop: 16 },
  bar: { marginBottom: 10 },
  lock: {
    width: 44,
    height: 44,
    borderRadius: 14,
    backgroundColor: 'rgba(255,255,255,0.14)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { color: colors.white, fontSize: 26, fontWeight: '800', letterSpacing: -0.5, marginTop: 6 },
});
