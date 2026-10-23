// Tests navigateur de bout en bout (Chromium) : Twig/jQuery/Vue.js -> Symfony (APP_ENV=prod) -> SQLite.
// Prérequis : la pile lancée par ./run-stack.sh (base e2e recréée avec les données de démonstration).
module.exports = {
  testDir: __dirname,
  testMatch: /\.spec\.js$/,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8090',
    headless: true,
    locale: 'fr-FR',
    timezoneId: 'Africa/Casablanca',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  outputDir: __dirname + '/.tmp/results',
};
