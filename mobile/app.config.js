// The TeamPOS server address stays out of git: set CASHIER_API_BASE_URL in mobile/.env
// (or as an EAS environment variable for cloud builds).
module.exports = ({ config }) => ({
  ...config,
  extra: {
    ...config.extra,
    apiBaseUrl: process.env.CASHIER_API_BASE_URL || config.extra?.apiBaseUrl,
  },
});
