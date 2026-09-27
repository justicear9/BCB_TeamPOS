import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

let onSignedOut = null;

function extra() {
  return Constants.expoConfig?.extra ?? Constants.manifest?.extra ?? {};
}

export function apiBaseUrl() {
  return extra().apiBaseUrl;
}

export function whenSignedOut(handler) {
  onSignedOut = handler;
}

function firstError(json) {
  const detail = json?.errors ? Object.values(json.errors).flat()[0] : null;
  return detail || json?.message || '';
}

export async function login(username, password) {
  const response = await fetch(`${apiBaseUrl()}/cashier/api/auth/login`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password, device: Constants.deviceName || 'phone' }),
  });
  const json = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(
      response.status === 429 ? 'Too many tries. Wait a minute and try again.' : firstError(json) || 'Sign-in failed'
    );
  }
  await SecureStore.setItemAsync('cashier_token', json.access_token);
  return json;
}

export async function logout() {
  const token = await SecureStore.getItemAsync('cashier_token');
  await SecureStore.deleteItemAsync('cashier_token');
  if (!token) {
    return;
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 5000);
  try {
    await fetch(`${apiBaseUrl()}/cashier/api/auth/logout`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      signal: controller.signal,
    });
  } catch {
    // Offline sign-out still clears the phone. The token then ages out on the server.
  } finally {
    clearTimeout(timer);
  }
}

export async function authFetch(path, options = {}) {
  const token = await SecureStore.getItemAsync('cashier_token');
  const response = await fetch(`${apiBaseUrl()}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      ...(options.headers || {}),
    },
  });
  const json = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401 && onSignedOut) {
      onSignedOut();
    }
    const error = new Error(
      response.status === 401 ? 'Your sign-in has expired. Sign in again.' : firstError(json) || `Request failed (${response.status})`
    );
    error.status = response.status;
    error.body = json;
    throw error;
  }
  return json;
}
