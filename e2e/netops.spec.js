// Parcours de bout en bout dans un vrai navigateur : Twig + jQuery + Vue.js -> Symfony -> base réelle.
// Prérequis : la pile lancée par ./run-stack.sh (données de démonstration fictives, mot de passe factice).
const { test, expect } = require('@playwright/test');

const DEMO_PASSWORD = 'Demo1234!';
const SUPERVISOR = 'superviseur@netops.example';
const TECHNICIAN = 'technicien1@netops.example';

async function login(page, email) {
  await page.goto('/connexion');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(DEMO_PASSWORD);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('link', { name: 'Déconnexion' })).toBeVisible();
}

// Glisser-déposer HTML5 piloté à la souris, sans défilement entre l'appui et le déplacement
// (locator.dragTo fait défiler vers la cible et peut saisir une autre carte).
async function dragCard(page, source, target) {
  await page.evaluate(() => window.scrollTo(0, 0));
  const from = await source.boundingBox();
  const to = await target.boundingBox();
  await page.mouse.move(from.x + from.width / 2, from.y + 20);
  await page.mouse.down();
  await page.mouse.move(from.x + from.width / 2 + 12, from.y + 32, { steps: 4 });
  await page.mouse.move(to.x + to.width / 2, to.y + 40, { steps: 12 });
  await page.mouse.up();
}

async function logout(page) {
  await page.getByRole('link', { name: 'Déconnexion' }).click();
  await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
}

test('superviseur assigne, puis le technicien démarre et résout l’incident', async ({ page }) => {
  await login(page, SUPERVISOR);

  // Liste filtrée en AJAX (jQuery) : statut « Qualifié »
  await page.goto('/incidents');
  await page.getByLabel('Statut').selectOption('QUALIFIE');
  await expect(page.locator('#incident-results tbody tr').first()).toContainText('Qualifié');
  await expect(page).toHaveURL(/status=QUALIFIE/);

  await page.locator('#incident-results tbody tr a').first().click();
  const reference = (await page.locator('h1').innerText()).trim();
  expect(reference).toMatch(/^INC-\d{4}-\d{6}$/);

  await page.getByLabel('Technicien').selectOption({ label: 'Karim Tazi' });
  await page.getByTestId('action-assigner').click();
  await expect(page.locator('.alert-success')).toContainText('assigné à Karim Tazi');
  await expect(page.locator('h1 + .badge, .badge').filter({ hasText: 'Assigné' }).first()).toBeVisible();
  await logout(page);

  await login(page, TECHNICIAN);
  await page.goto(`/incidents/${reference}`);
  await page.getByTestId('action-demarrer').click();
  await expect(page.locator('.alert-success')).toContainText('En cours');

  await page.getByLabel(/Compte rendu d’intervention/).fill('Carte de transmission remplacée, liaison rétablie et tests concluants.');
  await page.getByTestId('action-resoudre').click();
  await expect(page.locator('.alert-success')).toContainText('Résolu');
  await expect(page.getByTestId('sla-badge')).toContainText('SLA');
  await expect(page.locator('#journal-section')).toContainText('En cours → Résolu');
});

test('Kanban : glisser-déposer autorisé puis refusé', async ({ page }) => {
  await page.setViewportSize({ width: 1600, height: 1000 });
  await login(page, SUPERVISOR);
  await page.goto('/kanban');

  const nouveau = page.locator('section.kanban-column[aria-label="Nouveau"]');
  const qualifie = page.locator('section.kanban-column[aria-label="Qualifié"]');
  const enCours = page.locator('section.kanban-column[aria-label="En cours"]');
  const card = nouveau.locator('article.kanban-card').first();
  await expect(card).toBeVisible();
  const reference = (await card.locator('a').first().innerText()).trim();

  // NOUVEAU -> QUALIFIE : transition « qualifier » appelée sur l'API REST
  await dragCard(page, card, qualifie);
  await expect(page.locator('.kanban-message .alert-success')).toContainText(`${reference} : Qualifier → Qualifié`);
  await expect(qualifie.getByText(reference)).toBeVisible();

  // QUALIFIE -> EN_COURS : aucune transition directe, la carte reste en place
  const moved = qualifie.locator('article.kanban-card', { hasText: reference });
  await dragCard(page, moved, enCours);
  await expect(page.locator('.kanban-message .alert-warning')).toContainText('impossible');
  await expect(qualifie.getByText(reference)).toBeVisible();
});
