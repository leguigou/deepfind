// Test de bout en bout de l'extranet et du widget dans Chromium (Playwright).
// Usage : node tests/e2e.cjs [URL] [DOSSIER_CAPTURES]   (serveurs démarrés au préalable, cf. README)
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://localhost:8080';
const OUT = process.argv[3] || 'tests/_tmp/screens';
const TOKEN = process.env.ADMIN_TOKEN || 'test-admin-token-0123456789abcdef0123';
const fs = require('fs');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch();
  // User-agent de navigateur classique (le navigateur « headless » est volontairement refusé par l'anti-robots).
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, colorScheme: 'dark',
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('JS: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  let ok = 0; const fail = [];
  const check = (name, cond) => { if (cond) { ok++; console.log('  ✔ ' + name); } else { fail.push(name); console.log('  ✘ ' + name); } };

  await page.goto(BASE + '/admin');
  check('redirection vers la connexion', page.url().includes('/admin/login'));
  await page.fill('input[name=token]', 'mauvais-jeton');
  await page.click('button.btn');
  check('jeton invalide refusé', (await page.textContent('body')).includes('Jeton invalide'));
  await page.fill('input[name=token]', TOKEN);
  await page.click('button.btn');
  check('connexion', page.url().endsWith('/admin'));
  await page.screenshot({ path: OUT + '/01-dashboard-sombre.png', fullPage: true });

  const pages = ['/admin/sources', '/admin/sources/edit?id=1', '/admin/sources/test?id=1', '/admin/imports', '/admin/imports/view?id=1',
    '/admin/synonyms', '/admin/rules', '/admin/redirects', '/admin/preview?q=palme', '/admin/stats', '/admin/logs', '/admin/bots',
    '/admin/keys', '/admin/settings'];
  for (const p of pages) {
    const resp = await page.goto(BASE + p);
    const txt = await page.textContent('body');
    check('page ' + p, resp.status() === 200 && !/Fatal error|Warning:|Notice:|Deprecated:/.test(txt));
  }
  await page.goto(BASE + '/admin/preview?q=palme');
  await page.screenshot({ path: OUT + '/02-apercu-interne.png', fullPage: false });
  check('aperçu : produit épinglé en 1er', (await page.textContent('table.table tbody tr:first-child')).includes('C4-PREDATOR'));

  // Thème clair + menu replié (mémorisés)
  const before = await page.getAttribute('html', 'data-theme');
  if (before === 'light') { await page.click('[data-toggle-theme]'); }
  check('thème sombre par défaut ou choisi', (await page.getAttribute('html', 'data-theme')) === 'dark');
  await page.click('[data-toggle-theme]');
  check('bascule thème clair', (await page.getAttribute('html', 'data-theme')) === 'light');
  await page.goto(BASE + '/admin/bots');
  check('thème mémorisé', (await page.getAttribute('html', 'data-theme')) === 'light');
  await page.screenshot({ path: OUT + '/03-robots-clair.png', fullPage: false });
  await page.click('[data-toggle-menu]');
  check('menu replié', await page.evaluate(() => document.documentElement.classList.contains('menu-collapsed')));
  await page.goto(BASE + '/admin/stats');
  check('menu replié mémorisé', await page.evaluate(() => document.documentElement.classList.contains('menu-collapsed')));
  await page.screenshot({ path: OUT + '/04-stats-clair-menu-replie.png', fullPage: false });
  await page.click('[data-toggle-menu]');
  await page.click('[data-toggle-theme]');

  // Action : ajout d'un synonyme
  await page.goto(BASE + '/admin/synonyms');
  await page.fill('input[name=term]', 'lunette');
  await page.fill('input[name=synonyms]', 'masque');
  await page.click('form[action$="/admin/synonyms/add"] button');
  check('ajout synonyme', (await page.textContent('body')).includes('lunette'));
  // Action : import manuel mis en file d'attente
  await page.goto(BASE + '/admin/sources');
  page.once('dialog', (d) => d.accept());
  await page.click('form[action$="/admin/sources/import"] button');
  check('import mis en file d\'attente', /mis en file|déjà en attente/.test(await page.textContent('body')));
  // Action : blocage manuel d'une IP
  await page.goto(BASE + '/admin/bots');
  await page.fill('input[name=ip]', '203.0.113.50');
  await page.click('form[action$="/admin/bots/block"] button.btn');
  check('blocage manuel', (await page.textContent('body')).includes('203.0.113.x'));
  // Planification d'un flux
  await page.goto(BASE + '/admin/sources/edit?id=1');
  await page.selectOption('select[data-cron-preset="cron_delta"]', '*/15 6-23 * * *');
  check('planification : préréglage recopié', (await page.inputValue('#cron_delta')) === '*/15 6-23 * * *');
  await page.click('form.form .actions button.btn >> nth=0');
  check('planification : enregistrée', (await page.inputValue('#cron_delta')) === '*/15 6-23 * * *' && (await page.textContent('body')).includes('Prochaines exécutions'));
  await page.screenshot({ path: OUT + '/05-source-planification.png', fullPage: true });
  await page.goto(BASE + '/admin/sources');
  check('planification : affichée dans la liste', (await page.textContent('table')).includes('Toutes les 15 min de 6 h à 23 h'));

  // Mapping visuel : détection, aller-retour d'enregistrement
  await page.goto(BASE + '/admin/sources/mapping?id=1');
  check('mapping visuel : champs détectés', (await page.$$('select[data-sample-select]')).length > 20);
  check('mapping visuel : SKU pré-rempli', (await page.inputValue('select[name="f[sku][path]"]')) === 'sku');
  check('mapping visuel : exemple affiché', (await page.textContent('.map-sample')).includes('ex.'));
  await page.screenshot({ path: OUT + '/09-mapping-visuel.png', fullPage: false });
  await page.click('.sticky-actions button.btn');
  check('mapping visuel : enregistré', (await page.textContent('body')).includes('Mapping enregistré'));
  await page.goto(BASE + '/admin/sources/test?id=1');
  check('mapping visuel : test du résultat sans rejet', !(await page.textContent('body')).includes('Rejeté'));

  // Cache : visibilité et purge
  await page.goto(BASE + '/admin/cache');
  check('cache : page affichée', (await page.textContent('body')).includes('Résultats en cache'));
  await page.screenshot({ path: OUT + '/10-cache.png', fullPage: false });
  page.once('dialog', (d) => d.accept());
  await page.click('form[action$="/admin/cache/purge"] button.btn >> nth=0');
  check('cache : purge', (await page.textContent('body')).includes('entrée(s) supprimée(s)'));

  // Import manuel suivi en direct (le worker est lancé par le script de test)
  await page.goto(BASE + '/admin/sources');
  page.once('dialog', (d) => d.accept());
  await page.click('form[action$="/admin/sources/import"] button >> nth=0');
  check('import : page de suivi ouverte', page.url().includes('/admin/imports/view'));
  check('import : barre de progression', (await page.$$('.prog-big .prog-bar')).length === 1);
  await page.screenshot({ path: OUT + '/11-import-en-attente.png', fullPage: false });
  if (process.env.E2E_WORKER === '1') {
    await page.waitForFunction(() => /Terminé/.test(document.querySelector('.prog-big [data-f="label"]').textContent), null, { timeout: 60000 });
    await page.waitForSelector('.cards .badge-ok', { timeout: 15000 });
    check('import : terminé et page actualisée', (await page.textContent('.prog-big')).includes('100') && (await page.textContent('.cards')).includes('Réussi'));
    await page.screenshot({ path: OUT + '/12-import-termine.png', fullPage: false });
  }

  // Widget sur la page de démo
  await page.goto(BASE + '/admin/demo');
  await page.fill('#search', 'palme');
  await page.waitForSelector('.df-card', { timeout: 5000 });
  await page.waitForTimeout(400);
  const n1 = await page.$$eval('.df-card', (c) => c.length);
  check('widget : résultats affichés', n1 > 0);
  check('widget : marques à gauche', (await page.textContent('.df-side')).includes('Marques'));
  check('widget : articles de blog', (await page.textContent('.df-side')).includes('Articles de blog'));
  await page.screenshot({ path: OUT + '/06-widget.png' });
  await page.evaluate(() => { const m = document.querySelector('.df-main'); m.scrollTop = m.scrollHeight; });
  await page.waitForTimeout(800);
  const n2 = await page.$$eval('.df-card', (c) => c.length);
  check('widget : chargement au défilement (' + n1 + ' → ' + n2 + ')', n2 > n1);
  await page.click('.df-fv >> nth=0');
  await page.waitForTimeout(600);
  check('widget : filtre marque actif', (await page.$$('.df-fv.df-on')).length === 1);
  await page.check('input[name=on_sale]');
  await page.waitForTimeout(600);
  await page.screenshot({ path: OUT + '/07-widget-filtres.png' });
  await page.keyboard.press('Escape');
  check('widget : fermeture Échap', await page.$eval('.df-overlay', (o) => o.hidden));

  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1' });
  const mp = await mobile.newPage();
  await mp.goto(BASE + '/admin/login');
  await mp.fill('input[name=token]', TOKEN);
  await mp.click('button.btn');
  await mp.goto(BASE + '/admin/demo');
  await mp.fill('#search', 'masque');
  await mp.waitForSelector('.df-card', { timeout: 5000 });
  await mp.waitForTimeout(300);
  await mp.screenshot({ path: OUT + '/08-widget-mobile.png' });
  check('widget mobile : pas de défilement horizontal', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));

  check('aucune erreur JavaScript', errors.length === 0);
  if (errors.length) { console.log(errors.join('\n')); }
  await browser.close();
  console.log(`\n${ok} réussi(s), ${fail.length} échec(s)`);
  process.exit(fail.length ? 1 : 0);
})();
