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
  // Fenêtre de confirmation de l'extranet (remplace confirm() du navigateur) : bouton de validation
  const confirmModal = async () => { await page.click('dialog.modal-confirm[open] [data-ok]'); };
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
    '/admin/synonyms', '/admin/custom-results', '/admin/custom-results/edit', '/admin/banners', '/admin/banners/edit', '/admin/redirects', '/admin/redirects/edit', '/admin/preview?q=palme', '/admin/preview?q=palme&brand[]=C4&show_disabled=1', '/admin/disabled', '/admin/boost-rules', '/admin/stats', '/admin/logs', '/admin/bots',
    '/admin/keys', '/admin/settings'];
  for (const p of pages) {
    const resp = await page.goto(BASE + p);
    const txt = await page.textContent('body');
    check('page ' + p, resp.status() === 200 && !/Fatal error|Warning:|Notice:|Deprecated:/.test(txt));
    // Interrupteurs bien formés (case + piste dans le même libellé, aucun « > » parasite)
    const badToggles = await page.$$eval('label.toggle', (ls) => ls.filter((l) => !l.querySelector('input[role=switch]') || !l.querySelector('.toggle-track') || /^\s*>/.test(l.textContent)).length);
    if (badToggles > 0) { check('interrupteurs bien formés sur ' + p, false); }
  }
  await page.goto(BASE + '/admin/preview?q=palme');
  check('aperçu : interrupteurs de filtre lisibles', (await page.textContent('form.card')).includes('En stock') && !(await page.textContent('form.card')).includes('> En stock'));
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
  await page.click('form[action$="/admin/sources/import"] button');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
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
  await page.selectOption('select[name="f[type][path]"]', '');
  await page.click('.sticky-actions button.btn');
  check('mapping visuel : enregistré', (await page.textContent('body')).includes('Mapping enregistré'));
  check('mapping visuel : « non mappé » conservé (pas de re-suggestion)', (await page.inputValue('select[name="f[type][path]"]')) === '');
  check('mapping visuel : interprétation du stock affichée', (await page.textContent('body')).includes('= oui'));
  await page.selectOption('select[name="f[type][path]"]', 'type');
  await page.click('.sticky-actions button.btn');
  check('mapping visuel : champ re-mappé', (await page.inputValue('select[name="f[type][path]"]')) === 'type');

  // Paramètres : interrupteurs marche/arrêt
  await page.goto(BASE + '/admin/settings');
  check('paramètres : interrupteurs', (await page.$$('.toggle input[role=switch]')).length === 3);
  await page.click('label.toggle:has(input[name=block_bad_user_agents])');
  await page.click('form.form > button.btn');
  check('interrupteur : désactivé et enregistré', (await page.isChecked('input[name=block_bad_user_agents][type=checkbox]')) === false);
  await page.screenshot({ path: OUT + '/14-parametres-interrupteurs.png', fullPage: false });
  await page.click('label.toggle:has(input[name=block_bad_user_agents])');
  await page.click('form.form > button.btn');
  check('interrupteur : réactivé', await page.isChecked('input[name=block_bad_user_agents][type=checkbox]'));

  // Pertinence & classement
  await page.goto(BASE + '/admin/relevance');
  check('pertinence : page affichée', (await page.textContent('body')).includes('Comment un produit est classé'));
  await page.fill('input[name="fields[brand][pos]"]', '1');
  await page.fill('input[name="fields[name][pos]"]', '2');
  await page.check('input[name=biz_importance][value=medium]');
  await page.fill('input[name=w_margin]', '35');
  await page.click('.sticky-actions button.btn >> nth=0');
  const relTxt = await page.textContent('body');
  check('pertinence : appliquée au moteur', relTxt.includes('appliqués à'));
  check('pertinence : avertissement poids (recalcul requis)', relTxt.includes('prochain recalcul des scores'));
  check('pertinence : nouvel ordre enregistré', (await page.inputValue('input[name="fields[brand][pos]"]')) === '1');
  await page.screenshot({ path: OUT + '/13-pertinence.png', fullPage: false });
  await page.fill('input[name="fields[brand][pos]"]', '2');
  await page.fill('input[name="fields[name][pos]"]', '1');
  await page.check('input[name=biz_importance][value=low]');
  await page.fill('input[name=w_margin]', '20');
  await page.click('.sticky-actions button.btn >> nth=0');
  await page.goto(BASE + '/admin/sources/test?id=1');
  check('mapping visuel : test du résultat sans rejet', !(await page.textContent('body')).includes('Rejeté'));

  // Statistiques : ajout d'un synonyme depuis une requête sans résultat exact
  await page.goto(BASE + '/admin/stats');
  const synForms = await page.$$('form.syn-form');
  check('stats : formulaire de synonyme sur les requêtes sans résultat', synForms.length > 0);
  if (synForms.length) {
    await page.fill('form.syn-form >> nth=0 >> input[name=synonyms]', 'palme');
    await page.click('form.syn-form >> nth=0 >> button');
    check('stats : synonyme ajouté et retour sur les statistiques', page.url().includes('/admin/stats') && (await page.textContent('body')).includes('synonymes envoyée'));
  }
  check('stats : produits cliqués', (await page.textContent('body')).includes('Produits les plus cliqués'));
  await page.screenshot({ path: OUT + '/15-stats.png', fullPage: false });

  // Aperçu : boost permanent et épinglage sans quitter la page
  await page.goto(BASE + '/admin/preview?q=masque');
  const firstSku = (await page.textContent('table.table tbody tr:nth-child(3) code')).trim();
  await Promise.all([page.waitForNavigation(), page.selectOption('table.table tbody tr:nth-child(3) select[name=boost]', '5')]);
  check('aperçu : boost en ligne, retour sur la même recherche', page.url().includes('/admin/preview') && page.url().includes('q=masque') && (await page.textContent('body')).includes('Boost ×5 enregistré'));
  check('aperçu : produit boosté remonté', (await page.textContent('table.table tbody tr:first-child code')).trim() === firstSku || (await page.textContent('table.table tbody tr:nth-child(2) code')).trim() === firstSku);
  await Promise.all([page.waitForNavigation(), page.selectOption('table.table tbody tr:has(code:text-is("' + firstSku + '")) select[name=boost]', '1')]);
  check('aperçu : boost retiré', /Boost de .* retiré/.test(await page.textContent('body')));
  await Promise.all([page.waitForNavigation(), page.click('table.table tbody tr:nth-child(4) button:has-text("Épingler")')]);
  check('aperçu : épinglage en ligne', page.url().includes('q=masque') && (await page.textContent('table.table tbody tr:first-child')).includes('retirer'));
  await page.screenshot({ path: OUT + '/19-apercu-boost-epingle.png', fullPage: false });
  await Promise.all([page.waitForNavigation(), page.click('table.table tbody button:has-text("retirer") >> nth=0')]);

  // Règles de boost : création dans la fenêtre
  await page.goto(BASE + '/admin/boost-rules');
  await page.click('button[data-br-open]:not([data-rule])');
  await page.fill('[data-f=name]', 'Test e2e');
  await page.fill('[data-f=boost]', '2');
  await page.selectOption('.br-cond select.br-field', 'brand');
  await page.click('.ms-toggle');
  await page.locator('.ms-list .ms-item').first().click();
  await Promise.all([page.waitForNavigation(), page.click('.modal-foot button.btn:not(.btn-light)')]);
  check('règles de boost : règle enregistrée', (await page.textContent('body')).includes('Règle « Test e2e » enregistrée') && (await page.textContent('table')).includes('×2'));
  await page.screenshot({ path: OUT + '/16-boosts.png', fullPage: false });
  await page.click('form[action$="/admin/boost-rules/delete"] button');
  await Promise.all([page.waitForNavigation(), confirmModal()]);

  // Cache : visibilité et purge
  await page.goto(BASE + '/admin/cache');
  check('cache : page affichée', (await page.textContent('body')).includes('Résultats en cache'));
  await page.screenshot({ path: OUT + '/10-cache.png', fullPage: false });
  await page.click('form[action$="/admin/cache/purge"] button.btn >> nth=0');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  check('cache : purge', (await page.textContent('body')).includes('supprimée(s)'));

  // Import manuel suivi en direct (le worker est lancé par le script de test)
  await page.goto(BASE + '/admin/sources');
  await page.click('form[action$="/admin/sources/import"] button >> nth=0');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  check('import : page de suivi ouverte', page.url().includes('/admin/imports/view'));
  check('import : barre de progression', (await page.$$('.prog-big .prog-bar')).length === 1);
  await page.screenshot({ path: OUT + '/11-import-en-attente.png', fullPage: false });
  if (process.env.E2E_WORKER === '1') {
    await page.waitForFunction(() => /Terminé/.test(document.querySelector('.prog-big [data-f="label"]').textContent), null, { timeout: 60000 });
    await page.waitForSelector('.cards .badge-ok', { timeout: 15000 });
    check('import : terminé et page actualisée', (await page.textContent('.prog-big')).includes('100') && (await page.textContent('.cards')).includes('Réussi'));
    await page.screenshot({ path: OUT + '/12-import-termine.png', fullPage: false });
  }

  // Projets : sélecteur, isolation dans l'extranet, copie avec résumé, restauration
  await page.goto(BASE + '/admin/projects');
  check('projets : liste', (await page.$$eval('input.name-input', (i) => i.map((x) => x.value))).includes('Cabesto Test'));
  await page.screenshot({ path: OUT + '/20-projets.png', fullPage: false });
  await Promise.all([page.waitForNavigation(), page.selectOption('.project-switch select', { label: 'Cabesto Test' })]);
  check('projets : sélecteur → projet Test actif', (await page.textContent('.project-chip')).includes('Cabesto Test'));
  await page.goto(BASE + '/admin/redirects');
  check('projets : redirection propre au projet Test', (await page.textContent('table')).includes('bonjour test'));
  await page.goto(BASE + '/admin/projects/copy');
  await page.selectOption('select[name=to]', { label: 'Production' });
  for (const sec of ['search', 'synonyms', 'rules', 'redirects', 'boost_rules', 'sources']) {
    await page.click('label.toggle:has(input[value=' + sec + '])');
  }
  await Promise.all([page.waitForNavigation(), page.click('form.card button.btn')]);
  check('copie : résumé des changements', (await page.textContent('body')).includes('Changements dans « Production »'));
  await page.screenshot({ path: OUT + '/21-copie-resume.png', fullPage: false });
  await page.click('form.actions button.btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  check('copie : appliquée avec sauvegarde', (await page.textContent('body')).includes('Sauvegarde #'));
  check('copie : bascule sur le projet cible', (await page.textContent('.project-chip')).includes('Production'));
  await page.click('form[action$="/admin/projects/restore"] button >> nth=0');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  check('sauvegarde : restaurée', (await page.textContent('body')).includes('Sauvegarde restaurée'));
  await page.goto(BASE + '/admin/redirects');
  check('projets : Production sans la redirection de Test', (await page.textContent('table')).includes('cgv#retours') && !(await page.textContent('table')).includes('bonjour test'));

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
