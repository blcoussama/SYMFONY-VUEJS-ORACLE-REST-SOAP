// Captures d'écran du README (docs/screenshots/*.png), prises sur les données de démonstration.
// Lancement : npm run screenshots (pile démarrée par ./run-stack.sh, base fraîchement rechargée).
const path = require('path');
const { test, expect } = require('@playwright/test');

const OUT = path.join(__dirname, '..', 'docs', 'screenshots');
const DEMO_PASSWORD = 'Demo1234!';

async function login(page, email) {
  await page.goto('/connexion');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(DEMO_PASSWORD);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('link', { name: 'Déconnexion' })).toBeVisible();
}

test.use({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });

test('@captures connexion', async ({ page }) => {
  await page.goto('/connexion');
  await page.locator('details').evaluate((d) => { d.open = true; });
  await page.screenshot({ path: path.join(OUT, '01-connexion.png') });
});

test('@captures liste filtrée et détail', async ({ page }) => {
  await login(page, 'superviseur@netops.example');
  await page.goto('/incidents');
  await page.getByLabel('Statut').selectOption('OUVERTS');
  await expect(page).toHaveURL(/status=OUVERTS/);
  await page.getByLabel('Région').selectOption('Tanger-Tétouan-Al Hoceïma');
  await expect(page.locator('#incident-results')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('#filter-site option')).toHaveCount(6);
  await page.screenshot({ path: path.join(OUT, '02-liste-filtree.png'), fullPage: true });

  await page.goto('/incidents?status=EN_COURS');
  await page.locator('#incident-results tbody tr a').first().click();
  await expect(page.locator('#journal')).toBeVisible();
  await page.screenshot({ path: path.join(OUT, '03-detail-journal.png'), fullPage: true });
});

test('@captures kanban', async ({ page }) => {
  await login(page, 'superviseur@netops.example');
  await page.goto('/kanban');
  await expect(page.locator('article.kanban-card').first()).toBeVisible();
  await page.screenshot({ path: path.join(OUT, '04-kanban.png') });
});

test('@captures tableau de bord', async ({ page }) => {
  await login(page, 'superviseur@netops.example');
  await page.goto('/tableau-de-bord');
  await expect(page.getByTestId('kpi-open')).toBeVisible();
  await page.screenshot({ path: path.join(OUT, '05-tableau-de-bord.png'), fullPage: true });
});

test('@captures mes interventions', async ({ page }) => {
  await login(page, 'technicien1@netops.example');
  await page.goto('/mes-interventions');
  await expect(page.locator('h1')).toHaveText('Mes interventions');
  await page.screenshot({ path: path.join(OUT, '06-mes-interventions.png'), fullPage: true });
});
