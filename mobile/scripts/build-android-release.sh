#!/usr/bin/env bash
# Builds a Play-ready, upload-key-signed .aab on this machine.
# Needs mobile/android-signing.properties (git-ignored) next to the keystore.
# Usage: scripts/build-android-release.sh [server-url]   (defaults to bcb)
set -euo pipefail

cd "$(dirname "$0")/.."
MOBILE_DIR="$(pwd)"

export JAVA_HOME="${JAVA_HOME:-/opt/homebrew/opt/openjdk@17}"
export ANDROID_HOME="${ANDROID_HOME:-/opt/homebrew/share/android-commandlinetools}"
export PATH="$JAVA_HOME/bin:$PATH"
export CASHIER_API_BASE_URL="${1:-https://bcb.teamjaketech.com}"
export NODE_ENV=production

props="$MOBILE_DIR/android-signing.properties"
[ -f "$props" ] || { echo "Missing $props"; exit 1; }
prop() { grep "^$1=" "$props" | cut -d= -f2-; }
store_file="$MOBILE_DIR/$(prop storeFile)"
[ -f "$store_file" ] || { echo "Missing keystore $store_file"; exit 1; }

CI=1 npx expo prebuild --platform android --clean

cd android
./gradlew :app:bundleRelease --no-daemon \
  -Pandroid.injected.signing.store.file="$store_file" \
  -Pandroid.injected.signing.store.password="$(prop storePassword)" \
  -Pandroid.injected.signing.key.alias="$(prop keyAlias)" \
  -Pandroid.injected.signing.key.password="$(prop keyPassword)"

out="$MOBILE_DIR/android/app/build/outputs/bundle/release/app-release.aab"
version_code=$(node -p "require('$MOBILE_DIR/app.json').expo.android.versionCode")
dest="$MOBILE_DIR/build/teampos-${version_code}.aab"
mkdir -p "$MOBILE_DIR/build"
cp "$out" "$dest"
echo "Signed bundle: $dest (versionCode $version_code, server $CASHIER_API_BASE_URL)"
