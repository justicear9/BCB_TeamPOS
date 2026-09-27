import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

function extra() {
  return Constants.expoConfig?.extra ?? Constants.manifest?.extra ?? {};
}

export function apiBaseUrl() {
  return extra().apiBaseUrl;
}

export async function login(username, password) {
  const body = new URLSearchParams({
    grant_type: 'password',
    client_id: String(extra().passportClientId),
    client_secret: String(extra().passportClientSecret),
    username,
    password,
    scope: '',
  });
  const response = await fetch(`${apiBaseUrl()}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  });
  const json = await response.json();
  if (!response.ok) {
    throw new Error(json.message || json.error_description || 'Sign-in failed');
  }
  await SecureStore.setItemAsync('cashier_token', json.access_token);
  return json.access_token;
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
    const error = new Error(json.message || `Request failed (${response.status})`);
    error.status = response.status;
    error.body = json;
    throw error;
  }
  return json;
}
