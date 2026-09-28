import { useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { Banner, PrimaryButton, ScreenHeader } from '../ui';
import { Group, GroupLabel, KeyboardScreen, SwipeBack, TextField, kit } from '../kit';

export default function CustomerForm({ customer, busy, error, online, onBack, onSubmit }) {
  const [name, setName] = useState(customer?.name || '');
  const [business, setBusiness] = useState(customer?.business_name || '');
  const [mobile, setMobile] = useState(customer?.mobile || '');
  const [email, setEmail] = useState(customer?.email || '');
  const [address, setAddress] = useState(customer?.address || '');
  const editing = Boolean(customer);
  const phoneOk = mobile.replace(/[^0-9]/g, '').length >= 7;
  const emailOk = !email.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
  const ready = name.trim().length > 0 && phoneOk && emailOk;

  return (
    <SwipeBack onBack={onBack}>
      <ScreenHeader title={editing ? 'Edit customer' : 'New customer'} onBack={onBack} online={online} />
      <KeyboardScreen>
        <ScrollView
          style={styles.fill}
          contentContainerStyle={kit.scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
        >
          <Banner message={error} />
          <GroupLabel>Who</GroupLabel>
          <Group>
            <TextField label="Name" value={name} onChange={setName} placeholder="Ama Mensah" autoCapitalize="words" />
            <TextField
              label="Business (optional)"
              value={business}
              onChange={setBusiness}
              placeholder="Mensah Catering"
              autoCapitalize="words"
              last
            />
          </Group>
          <GroupLabel>Contact</GroupLabel>
          <Group>
            <TextField label="Phone" value={mobile} onChange={setMobile} placeholder="024 000 0000" keyboardType="phone-pad" />
            <TextField
              label="Email (optional)"
              value={email}
              onChange={setEmail}
              placeholder="ama@example.com"
              keyboardType="email-address"
              autoCapitalize="none"
            />
            <TextField label="Address (optional)" value={address} onChange={setAddress} placeholder="Street or area" last />
          </Group>
          {!phoneOk && mobile ? <Text style={kit.warn}>Enter a full phone number.</Text> : null}
          {!emailOk ? <Text style={kit.warn}>That email does not look right.</Text> : null}
          {!editing ? (
            <Text style={kit.note}>
              New customers pay in full. A manager can allow credit for them in TeamPOS.
            </Text>
          ) : null}
        </ScrollView>
        <View style={kit.bar}>
          <PrimaryButton
            label={busy ? 'Saving' : editing ? 'Save changes' : 'Add customer'}
            disabled={busy || !ready}
            onPress={() =>
              onSubmit({
                name: name.trim(),
                business_name: business.trim() || null,
                mobile: mobile.trim(),
                email: email.trim() || null,
                address: address.trim() || null,
              })
            }
          />
        </View>
      </KeyboardScreen>
    </SwipeBack>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
});
