const { withAppDelegate, withInfoPlist } = require('expo/config-plugins');

// Apps built with the iOS 27 SDK are killed at launch unless they adopt the
// UIScene life cycle. Expo ships the scene delegate; the template does not use it yet.
const WINDOW_START = `#if os(iOS) || os(tvOS)
    window = UIWindow(frame: UIScreen.main.bounds)
    factory.startReactNative(
      withModuleName: "main",
      in: window,
      launchOptions: launchOptions)
#endif

`;

function withSceneDelegate(config) {
  return withAppDelegate(config, (mod) => {
    let src = mod.modResults.contents;
    if (!src.includes('ExpoReactNativeFactoryProvider')) {
      src = src.replace(
        'class AppDelegate: ExpoAppDelegate {',
        'class AppDelegate: ExpoAppDelegate, ExpoReactNativeFactoryProvider {'
      );
    }
    if (src.includes(WINDOW_START)) {
      src = src.replace(WINDOW_START, '');
    }
    if (!src.includes('ExpoReactNativeFactoryProvider') || src.includes('UIWindow(frame:')) {
      throw new Error('withSceneLifecycle: AppDelegate.swift no longer matches the expected template.');
    }
    mod.modResults.contents = src;
    return mod;
  });
}

function withSceneManifest(config) {
  return withInfoPlist(config, (mod) => {
    mod.modResults.UIApplicationSceneManifest = {
      UIApplicationSupportsMultipleScenes: false,
      UISceneConfigurations: {
        UIWindowSceneSessionRoleApplication: [
          {
            UISceneConfigurationName: 'Default Configuration',
            UISceneDelegateClassName: 'EXExpoAppSceneDelegate',
          },
        ],
      },
    };
    return mod;
  });
}

module.exports = function withSceneLifecycle(config) {
  return withSceneManifest(withSceneDelegate(config));
};
