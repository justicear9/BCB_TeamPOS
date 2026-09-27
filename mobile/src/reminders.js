import { Platform } from 'react-native';
import { isRunningInExpoGo } from 'expo';

const CLOSE_REGISTER = 'close-register';
const REMIND_HOUR = 21;

// expo-notifications registers for push tokens as an import side effect, and
// that throws on Android inside Expo Go (SDK 53+). It is loaded lazily and
// skipped there; development and store builds get the reminder.
const SUPPORTED = Platform.OS !== 'web' && !(Platform.OS === 'android' && isRunningInExpoGo());
let Notifications = null;

function notifications() {
  if (!Notifications) {
    Notifications = require('expo-notifications');
    Notifications.setNotificationHandler({
      handleNotification: async () => ({
        shouldShowBanner: true,
        shouldShowList: true,
        shouldPlaySound: true,
        shouldSetBadge: false,
      }),
    });
  }
  return Notifications;
}

async function allowed() {
  const current = await Notifications.getPermissionsAsync();
  if (current.granted) {
    return true;
  }
  if (!current.canAskAgain) {
    return false;
  }
  return (await Notifications.requestPermissionsAsync()).granted;
}

/** While the register is open, a 9 PM reminder to count and close it. */
export async function remindToCloseRegister(open) {
  if (!SUPPORTED) {
    return;
  }
  try {
    notifications();
    await Notifications.cancelScheduledNotificationAsync(CLOSE_REGISTER).catch(() => {});
    if (!open) {
      return;
    }
    const at = new Date();
    at.setHours(REMIND_HOUR, 0, 0, 0);
    if (at.getTime() <= Date.now() || !(await allowed())) {
      return;
    }
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('register', {
        name: 'Cash register',
        importance: Notifications.AndroidImportance.HIGH,
      });
    }
    await Notifications.scheduleNotificationAsync({
      identifier: CLOSE_REGISTER,
      content: {
        title: 'Close your register',
        body: 'Your cash register is still open. Count the drawer and close it before you leave.',
      },
      trigger: { type: Notifications.SchedulableTriggerInputTypes.DATE, date: at, channelId: 'register' },
    });
  } catch {
    // A missing reminder must never block selling.
  }
}
