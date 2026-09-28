import { useMemo } from 'react';
import { Pressable, SectionList, StyleSheet, Text, View } from 'react-native';
import { CalendarDays, CircleAlert, Clock, Sun, X } from 'lucide-react-native';
import { dayKey, money } from '../format';
import { Banner, ScreenHeader, SearchField, Segments, StatusPill, colors, initials } from '../ui';
import { Hero, kit } from '../kit';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const BAR_HEIGHT = 56;

const filters = [
  { id: 'all', label: 'This week', icon: CalendarDays },
  { id: 'today', label: 'Today', icon: Sun },
  { id: 'due', label: 'Owing', icon: Clock },
];

export function owing(item) {
  return Math.max(0, Number(item.amount || 0) - Number(item.returned || 0) - Number(item.paid || 0));
}

function waiting(item) {
  return item.status === 'pending' || item.status === 'attention';
}

/** `filter` is all, today, due, or a YYYY-MM-DD day picked on the week strip. */
export function matchesFilter(item, filter, today) {
  if (filter === 'today') {
    return dayKey(item.when) === today;
  }
  if (filter === 'due') {
    return !waiting(item) && owing(item) > 0.009;
  }
  if (/^\d{4}-\d{2}-\d{2}$/.test(filter)) {
    return dayKey(item.when) === filter;
  }
  return true;
}

function dayTitle(key, today) {
  if (key === today) {
    return 'Today';
  }
  const date = new Date(`${key}T12:00:00`);
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  if (key === dayKey(yesterday)) {
    return 'Yesterday';
  }
  return `${DAYS[date.getDay()]} ${date.getDate()} ${MONTHS[date.getMonth()]}`;
}

function timeOf(value) {
  const date = new Date(String(value || '').replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  const hour = date.getHours() % 12 || 12;
  return `${hour}:${String(date.getMinutes()).padStart(2, '0')} ${date.getHours() < 12 ? 'AM' : 'PM'}`;
}

export default function Transactions({ items, week, query, onQuery, filter, onFilter, onOpen, online, error }) {
  const today = dayKey(new Date());
  const weekKeys = useMemo(() => new Set(week.days.map((day) => day.key)), [week]);
  const thisWeek = useMemo(() => items.filter((item) => weekKeys.has(dayKey(item.when))), [items, weekKeys]);

  const searched = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) {
      return thisWeek;
    }
    return thisWeek.filter((item) => `${item.title} ${item.subtitle} ${item.amount}`.toLowerCase().includes(needle));
  }, [thisWeek, query]);

  const counts = useMemo(() => {
    const result = {};
    for (const option of filters) {
      result[option.id] = searched.filter((item) => matchesFilter(item, option.id, today)).length;
    }
    return result;
  }, [searched, today]);

  const sections = useMemo(() => {
    const groups = new Map();
    for (const item of searched) {
      if (!matchesFilter(item, filter, today)) {
        continue;
      }
      const key = dayKey(item.when);
      if (!groups.has(key)) {
        groups.set(key, { key, title: dayTitle(key, today), total: 0, data: [] });
      }
      const group = groups.get(key);
      group.total += Number(item.amount || 0);
      group.data.push(item);
    }
    return [...groups.values()];
  }, [searched, filter, today]);

  const todayTotal = thisWeek.filter((item) => dayKey(item.when) === today).reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const owingTotal = thisWeek.filter((item) => matchesFilter(item, 'due', today)).reduce((sum, item) => sum + owing(item), 0);
  const peak = Math.max(...week.days.map((day) => day.total), 1);
  const pickedDay = week.days.find((day) => day.key === filter);

  const header = (
    <>
      <Banner message={error} />
      <Hero style={styles.hero}>
        <Text style={kit.heroLabel}>This week</Text>
        <Text style={kit.heroValue} numberOfLines={1} adjustsFontSizeToFit>
          {money(week.total)}
        </Text>
        <Text style={kit.heroHint}>
          {week.count} {week.count === 1 ? 'sale' : 'sales'} · {week.range}
        </Text>
        <View style={styles.bars}>
          {week.days.map((day) => {
            const picked = filter === day.key;
            const height = day.total > 0 ? Math.max(6, (day.total / peak) * BAR_HEIGHT) : 4;
            return (
              <Pressable
                key={day.key}
                disabled={day.future}
                onPress={() => onFilter(picked ? 'all' : day.key)}
                accessibilityRole="button"
                accessibilityState={{ selected: picked, disabled: day.future }}
                accessibilityLabel={`${day.name}, ${money(day.total)}`}
                style={({ pressed }) => [styles.barCell, picked && styles.barCellOn, pressed && styles.pressed]}
              >
                <View style={styles.barTrack}>
                  <View
                    style={[
                      styles.bar,
                      { height },
                      day.today && styles.barToday,
                      day.future && styles.barFuture,
                      picked && styles.barPicked,
                    ]}
                  />
                </View>
                <Text style={[styles.barLabel, (day.today || picked) && styles.barLabelOn, day.future && styles.barLabelFuture]}>
                  {day.short.slice(0, 1)}
                </Text>
              </Pressable>
            );
          })}
        </View>
        <View style={kit.heroStats}>
          <View style={kit.heroStat}>
            <Text style={kit.heroStatValue} numberOfLines={1} adjustsFontSizeToFit>
              {money(todayTotal)}
            </Text>
            <Text style={kit.heroStatLabel}>today</Text>
          </View>
          <View style={kit.heroDivider} />
          <View style={kit.heroStat}>
            <Text style={[kit.heroStatValue, owingTotal > 0.009 && styles.amber]} numberOfLines={1} adjustsFontSizeToFit>
              {money(owingTotal)}
            </Text>
            <Text style={kit.heroStatLabel}>owing this week</Text>
          </View>
        </View>
      </Hero>
      <SearchField value={query} onChangeText={onQuery} placeholder="Search customer, invoice or amount" />
      {pickedDay ? (
        <Pressable
          onPress={() => onFilter('all')}
          accessibilityRole="button"
          accessibilityLabel="Show the whole week"
          style={({ pressed }) => [styles.picked, pressed && styles.pressed]}
        >
          <CalendarDays color={colors.accent} size={16} strokeWidth={2.25} />
          <Text style={styles.pickedText}>
            {pickedDay.name} · {money(pickedDay.total)}
          </Text>
          <X color={colors.accent} size={16} strokeWidth={2.25} />
        </Pressable>
      ) : (
        <Segments options={filters} value={filter} onChange={onFilter} counts={counts} warn="due" />
      )}
    </>
  );

  return (
    <View style={styles.fill}>
      <ScreenHeader title="Transactions" subtitle="Sales at this shop this week" online={online} />
      <SectionList
        style={styles.fill}
        sections={sections}
        keyExtractor={(item) => item.key}
        ListHeaderComponent={header}
        contentContainerStyle={styles.list}
        stickySectionHeadersEnabled={false}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyTitle}>{query ? 'Nothing matches that search' : 'No sales here yet'}</Text>
            <Text style={styles.emptyHint}>
              {filter === 'due'
                ? 'No one owes money on this week’s sales.'
                : pickedDay || filter === 'today'
                  ? 'No sales on this day.'
                  : 'Sales from this shop this week show up here.'}
            </Text>
          </View>
        }
        renderSectionHeader={({ section }) => (
          <View style={styles.dayHead}>
            <Text style={styles.dayTitle}>{section.title}</Text>
            <Text style={styles.dayTotal}>
              {section.data.length} · {money(section.total)}
            </Text>
          </View>
        )}
        renderItem={({ item, index, section }) => (
          <TransactionRow item={item} first={index === 0} last={index === section.data.length - 1} onOpen={onOpen} />
        )}
      />
    </View>
  );
}

function TransactionRow({ item, first, last, onOpen }) {
  const due = owing(item);
  const tone =
    item.status === 'attention'
      ? [colors.redSoft, colors.red]
      : item.status === 'pending'
        ? [colors.accentSoft, colors.accent]
        : due > 0.009
          ? [colors.amberSoft, colors.amber]
          : [colors.greenSoft, colors.green];
  return (
    <Pressable
      onPress={() => onOpen(item)}
      accessibilityRole="button"
      accessibilityLabel={`${item.title}, ${money(item.amount)}`}
      style={({ pressed }) => [
        styles.row,
        first && styles.rowFirst,
        last && styles.rowLast,
        !last && styles.rowRule,
        pressed && styles.pressed,
      ]}
    >
      <View style={[styles.avatar, { backgroundColor: tone[0] }]}>
        {item.status === 'attention' ? (
          <CircleAlert color={tone[1]} size={18} strokeWidth={2.25} />
        ) : (
          <Text style={[styles.avatarText, { color: tone[1] }]}>{initials(item.title)}</Text>
        )}
      </View>
      <View style={styles.fill}>
        <Text style={styles.rowTitle} numberOfLines={1}>
          {item.title}
        </Text>
        <Text style={styles.rowHint} numberOfLines={1}>
          {[item.subtitle, timeOf(item.when)].filter(Boolean).join(' · ')}
        </Text>
        {item.detail ? (
          <Text style={styles.rowError} numberOfLines={2}>
            {item.detail}
          </Text>
        ) : null}
      </View>
      <View style={styles.rowSide}>
        <Text style={styles.rowAmount}>{money(item.amount)}</Text>
        {due > 0.009 && !waiting(item) ? (
          <Text style={styles.rowDue}>{money(due)} owing</Text>
        ) : (
          <StatusPill status={item.status} />
        )}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  pressed: { opacity: 0.72 },
  hero: { marginBottom: 12 },
  amber: { color: '#FCD34D' },
  bars: { flexDirection: 'row', gap: 6, marginTop: 16 },
  barCell: { flex: 1, alignItems: 'center', paddingVertical: 6, borderRadius: 12 },
  barCellOn: { backgroundColor: 'rgba(255,255,255,0.14)' },
  barTrack: { height: BAR_HEIGHT, justifyContent: 'flex-end' },
  bar: { width: 14, borderRadius: 7, backgroundColor: 'rgba(191,219,254,0.55)' },
  barToday: { backgroundColor: '#60A5FA' },
  barPicked: { backgroundColor: colors.white },
  barFuture: { backgroundColor: 'rgba(255,255,255,0.12)' },
  barLabel: { color: '#BFDBFE', fontSize: 12, fontWeight: '700', marginTop: 6 },
  barLabelOn: { color: colors.white },
  barLabelFuture: { opacity: 0.45 },
  picked: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 8,
    backgroundColor: colors.accentSoft,
    borderRadius: 999,
    paddingHorizontal: 14,
    minHeight: 40,
  },
  pickedText: { color: colors.accent, fontSize: 14, fontWeight: '700' },
  list: { paddingBottom: 24 },
  dayHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', marginTop: 16, marginBottom: 8, marginHorizontal: 4 },
  dayTitle: { color: colors.ink, fontSize: 15, fontWeight: '700' },
  dayTotal: { color: colors.muted, fontSize: 14, fontWeight: '700' },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: colors.card,
    borderLeftWidth: 1,
    borderRightWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  rowFirst: { borderTopWidth: 1, borderTopLeftRadius: 20, borderTopRightRadius: 20 },
  rowLast: { borderBottomWidth: 1, borderBottomLeftRadius: 20, borderBottomRightRadius: 20 },
  rowRule: { borderBottomWidth: 1, borderBottomColor: colors.line },
  avatar: { width: 40, height: 40, borderRadius: 14, alignItems: 'center', justifyContent: 'center' },
  avatarText: { fontSize: 14, fontWeight: '800' },
  rowTitle: { color: colors.ink, fontSize: 15, fontWeight: '700' },
  rowHint: { color: colors.muted, fontSize: 13, fontWeight: '600', marginTop: 2 },
  rowError: { color: colors.red, fontSize: 12, fontWeight: '600', marginTop: 4 },
  rowSide: { alignItems: 'flex-end', gap: 6 },
  rowAmount: { color: colors.ink, fontSize: 16, fontWeight: '800' },
  rowDue: { color: colors.amber, fontSize: 12, fontWeight: '700' },
  empty: { alignItems: 'center', paddingVertical: 48, paddingHorizontal: 24 },
  emptyTitle: { color: colors.ink, fontSize: 16, fontWeight: '700' },
  emptyHint: { color: colors.muted, fontSize: 14, marginTop: 6, textAlign: 'center' },
});
