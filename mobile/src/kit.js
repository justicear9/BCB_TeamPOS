import { useRef } from 'react';
import {
  Animated,
  KeyboardAvoidingView,
  Modal,
  PanResponder,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { colors } from './ui';

export function SwipeBack({ onBack, children }) {
  const translate = useRef(new Animated.Value(0)).current;
  const backRef = useRef(onBack);
  backRef.current = onBack;
  const responder = useRef(
    PanResponder.create({
      onStartShouldSetPanResponder: () => true,
      onMoveShouldSetPanResponder: (_, gesture) =>
        gesture.dx > 8 && Math.abs(gesture.dx) > Math.abs(gesture.dy),
      onPanResponderMove: (_, gesture) => {
        translate.setValue(Math.max(0, gesture.dx));
      },
      onPanResponderRelease: (_, gesture) => {
        if (gesture.dx > 90 || gesture.vx > 0.7) {
          Animated.timing(translate, { toValue: 420, duration: 160, useNativeDriver: true }).start(() => {
            translate.setValue(0);
            backRef.current?.();
          });
          return;
        }
        Animated.spring(translate, { toValue: 0, useNativeDriver: true, bounciness: 0 }).start();
      },
      onPanResponderTerminate: () => {
        Animated.spring(translate, { toValue: 0, useNativeDriver: true, bounciness: 0 }).start();
      },
    })
  ).current;

  return (
    <Animated.View style={[styles.fill, { transform: [{ translateX: translate }] }]}>
      {children}
      <View style={styles.backEdge} {...responder.panHandlers} />
    </Animated.View>
  );
}

export function KeyboardScreen({ children }) {
  return (
    <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      {children}
    </KeyboardAvoidingView>
  );
}

export function Hero({ children, tone = 'navy', style }) {
  return (
    <View style={[styles.hero, tone === 'green' && styles.heroGreen, style]}>
      <View style={[styles.orb, styles.orbOne, tone === 'green' && styles.orbGreen]} />
      <View style={[styles.orb, styles.orbTwo]} />
      {children}
    </View>
  );
}

export function GroupLabel({ children, action }) {
  return (
    <View style={styles.groupHead}>
      <Text style={styles.groupLabel}>{children}</Text>
      {action || null}
    </View>
  );
}

export function Group({ children, style }) {
  return <View style={[styles.group, style]}>{children}</View>;
}

export function Row({ icon: Icon, tint, color, label, hint, value, valueTone, onPress, last, disabled, right }) {
  const body = (
    <>
      {Icon ? (
        <View style={[styles.rowIcon, tint && { backgroundColor: tint }]}>
          <Icon color={color || colors.accent} size={17} strokeWidth={2.25} />
        </View>
      ) : null}
      <View style={styles.fill}>
        <Text style={styles.rowLabel}>{label}</Text>
        {hint ? <Text style={styles.rowHint}>{hint}</Text> : null}
      </View>
      {right || (
        <Text style={[styles.rowValue, valueTone && { color: valueTone }]} numberOfLines={1}>
          {value}
        </Text>
      )}
    </>
  );
  if (!onPress) {
    return <View style={[styles.row, !last && styles.rule]}>{body}</View>;
  }
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => [styles.row, !last && styles.rule, disabled && styles.dim, pressed && styles.pressed]}
    >
      {body}
    </Pressable>
  );
}

export function Sheet({ visible, title, subtitle, onClose, children }) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <Pressable style={styles.scrim} onPress={onClose} accessibilityLabel="Close">
          <Pressable style={styles.sheet} onPress={() => {}}>
            <ScrollView keyboardShouldPersistTaps="handled" bounces={false}>
              <Text style={styles.sheetTitle}>{title}</Text>
              {subtitle ? <Text style={styles.sheetSub}>{subtitle}</Text> : null}
              {children}
            </ScrollView>
          </Pressable>
        </Pressable>
      </KeyboardAvoidingView>
    </Modal>
  );
}

export function MoneyField({ value, onChange, placeholder = '0.00', prefix = 'GH₵', autoFocus, suffix, label }) {
  return (
    <View>
      {label ? <Text style={styles.fieldLabel}>{label}</Text> : null}
      <View style={styles.money}>
        {prefix ? <Text style={styles.moneyPrefix}>{prefix}</Text> : null}
        <TextInput
          value={value}
          onChangeText={(text) => onChange(text.replace(/[^0-9.]/g, ''))}
          keyboardType="decimal-pad"
          placeholder={placeholder}
          placeholderTextColor={colors.faint}
          autoFocus={autoFocus}
          style={styles.moneyInput}
          accessibilityLabel={label || placeholder}
        />
        {suffix ? <Text style={styles.moneyPrefix}>{suffix}</Text> : null}
      </View>
    </View>
  );
}

export function TextField({ label, value, onChange, placeholder, keyboardType, autoCapitalize = 'sentences', last }) {
  return (
    <View style={[styles.textRow, !last && styles.rule]}>
      <Text style={styles.textLabel}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={colors.faint}
        keyboardType={keyboardType}
        autoCapitalize={autoCapitalize}
        autoCorrect={false}
        style={styles.textInput}
        accessibilityLabel={label}
      />
    </View>
  );
}

export function Pill({ label, tone = 'accent' }) {
  const palette = {
    accent: [colors.accentSoft, colors.accent],
    green: [colors.greenSoft, colors.green],
    amber: [colors.amberSoft, colors.amber],
    red: [colors.redSoft, colors.red],
    muted: [colors.chip, colors.muted],
  }[tone];
  return (
    <View style={[styles.pill, { backgroundColor: palette[0] }]}>
      <Text style={[styles.pillText, { color: palette[1] }]}>{label}</Text>
    </View>
  );
}

export function ChoiceRow({ choices, value, onChange }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.choices}>
      {choices.map((choice) => {
        const selected = choice.id === value;
        const Icon = choice.icon;
        return (
          <Pressable
            key={choice.id}
            onPress={() => onChange(choice.id)}
            disabled={choice.disabled}
            accessibilityRole="button"
            accessibilityState={{ selected, disabled: Boolean(choice.disabled) }}
            style={({ pressed }) => [
              styles.choice,
              selected && styles.choiceOn,
              choice.disabled && styles.choiceOff,
              pressed && styles.pressed,
            ]}
          >
            {Icon ? <Icon color={selected ? colors.white : choice.disabled ? colors.faint : colors.ink} size={16} strokeWidth={2.25} /> : null}
            <Text style={[styles.choiceText, selected && styles.choiceTextOn, choice.disabled && styles.choiceTextOff]}>
              {choice.label}
            </Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

export const kit = StyleSheet.create({
  heroLabel: { color: '#BFDBFE', fontSize: 13, fontWeight: '700', letterSpacing: 0.6, textTransform: 'uppercase' },
  heroValue: { color: colors.white, fontSize: 40, fontWeight: '800', letterSpacing: -1, marginTop: 4 },
  heroHint: { color: '#BFDBFE', fontSize: 14, fontWeight: '600', marginTop: 4 },
  heroChip: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
    marginTop: 14,
    backgroundColor: 'rgba(255,255,255,0.12)',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 7,
  },
  heroChipText: { color: colors.white, fontSize: 14, fontWeight: '700', flexShrink: 1 },
  heroStats: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 18,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderRadius: 18,
    paddingVertical: 12,
    paddingHorizontal: 14,
  },
  heroStat: { flex: 1 },
  heroStatValue: { color: colors.white, fontSize: 18, fontWeight: '700' },
  heroStatLabel: { color: '#BFDBFE', fontSize: 12, fontWeight: '600', marginTop: 2 },
  heroDivider: { width: 1, alignSelf: 'stretch', backgroundColor: 'rgba(255,255,255,0.16)', marginHorizontal: 12 },
  scroll: { paddingTop: 8, paddingBottom: 28 },
  actions: { flexDirection: 'row', gap: 10, marginTop: 16 },
  action: { flex: 1 },
  note: { color: colors.muted, fontSize: 13, lineHeight: 19, marginTop: 10 },
  warn: { color: colors.red, fontSize: 13, fontWeight: '600', marginTop: 10 },
  bar: { paddingTop: 10, paddingBottom: 4 },
});

const styles = StyleSheet.create({
  fill: { flex: 1 },
  pressed: { opacity: 0.72 },
  dim: { opacity: 0.45 },
  backEdge: { position: 'absolute', left: -16, top: 0, bottom: 0, width: 24 },
  hero: { backgroundColor: '#0B2A6F', borderRadius: 28, padding: 20, overflow: 'hidden', marginBottom: 4 },
  heroGreen: { backgroundColor: '#0B4F37' },
  orb: { position: 'absolute', borderRadius: 999 },
  orbOne: { width: 220, height: 220, right: -70, top: -90, backgroundColor: colors.accent, opacity: 0.55 },
  orbGreen: { backgroundColor: '#22C55E', opacity: 0.35 },
  orbTwo: { width: 160, height: 160, left: -60, bottom: -80, backgroundColor: '#00A3FF', opacity: 0.25 },
  groupHead: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: 20,
    marginBottom: 8,
    marginHorizontal: 4,
  },
  groupLabel: { color: colors.muted, fontSize: 12, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  group: { backgroundColor: colors.card, borderRadius: 22, borderWidth: 1, borderColor: colors.line, paddingHorizontal: 14 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14, minHeight: 56 },
  rule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  rowIcon: {
    width: 32,
    height: 32,
    borderRadius: 10,
    backgroundColor: colors.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  rowLabel: { color: colors.ink, fontSize: 15, fontWeight: '600' },
  rowHint: { color: colors.muted, fontSize: 12, marginTop: 2, lineHeight: 16 },
  rowValue: { color: colors.muted, fontSize: 15, fontWeight: '700', maxWidth: '50%', textAlign: 'right' },
  scrim: {
    flex: 1,
    backgroundColor: 'rgba(28, 25, 23, 0.45)',
    justifyContent: 'center',
    padding: 20,
  },
  sheet: { backgroundColor: colors.card, borderRadius: 26, padding: 20, maxHeight: '88%', width: '100%', maxWidth: 440, alignSelf: 'center' },
  sheetTitle: { color: colors.ink, fontSize: 22, fontWeight: '700' },
  sheetSub: { color: colors.muted, fontSize: 14, lineHeight: 20, marginTop: 4 },
  fieldLabel: { color: colors.muted, fontSize: 12, fontWeight: '700', letterSpacing: 0.6, textTransform: 'uppercase', marginTop: 16, marginBottom: 8 },
  money: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: 18,
    paddingHorizontal: 16,
    minHeight: 60,
  },
  moneyPrefix: { color: colors.muted, fontSize: 20, fontWeight: '700' },
  moneyInput: { flex: 1, color: colors.ink, fontSize: 26, fontWeight: '700', paddingVertical: 10 },
  textRow: { paddingVertical: 10 },
  textLabel: { color: colors.muted, fontSize: 12, fontWeight: '700', letterSpacing: 0.4 },
  textInput: { color: colors.ink, fontSize: 17, fontWeight: '600', paddingVertical: 6 },
  pill: { borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4, alignSelf: 'flex-start' },
  pillText: { fontSize: 12, fontWeight: '700' },
  choices: { gap: 8, paddingVertical: 2 },
  choice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    minHeight: 46,
    paddingHorizontal: 16,
    borderRadius: 16,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.line,
  },
  choiceOn: { backgroundColor: colors.ink, borderColor: colors.ink },
  choiceText: { color: colors.ink, fontSize: 15, fontWeight: '700' },
  choiceTextOn: { color: colors.white },
  choiceOff: { backgroundColor: colors.chip, borderColor: colors.chip },
  choiceTextOff: { color: colors.faint },
});
