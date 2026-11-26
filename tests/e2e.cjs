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
  const check = (name, cond, detail) => { if (cond) { ok++; console.log('  ✔ ' + name); } else { fail.push(name); console.log('  ✘ ' + name + (detail !== undefined ? '\n      ' + String(detail).slice(0, 600) : '')); } };
  // Fiche enregistrée : « Supprimer » sur la même ligne et à la même hauteur que le bouton « Enregistrer »
  const deleteAligned = async () => page.evaluate(() => {
    const del = document.querySelector('.cr-delete-btn');
    const row = del && del.parentElement;
    const save = row && Array.prototype.filter.call(row.querySelectorAll('button.btn'), (b) => !b.classList.contains('btn-light') && !b.classList.contains('cr-delete-btn'))[0];
    if (!del || !save) { return false; }
    const a = del.getBoundingClientRect();
    const b = save.getBoundingClientRect();
    return Math.abs(a.top - b.top) < 2 && Math.abs(a.height - b.height) < 2 && a.left < b.left;
  });

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
    '/admin/synonyms', '/admin/custom-results', '/admin/custom-results/edit', '/admin/page-rules', '/admin/page-rules/edit', '/admin/banners', '/admin/banners/edit', '/admin/popins', '/admin/popins/edit', '/admin/carousels', '/admin/carousels/edit', '/admin/redirects', '/admin/redirects/edit', '/admin/preview?q=palme', '/admin/preview?q=palme&brand[]=C4&show_disabled=1', '/admin/disabled', '/admin/boost-rules', '/admin/monitor', '/admin/stats', '/admin/audience', '/admin/audience?days=365', '/admin/logs', '/admin/bots',
    '/admin/keys', '/admin/variables', '/admin/integration', '/admin/integration?tab=filters', '/admin/integration?tab=sorts', '/admin/settings'];
  for (const p of pages) {
    const resp = await page.goto(BASE + p);
    const txt = await page.textContent('body');
    check('page ' + p, resp.status() === 200 && !/Fatal error|Warning:|Notice:|Deprecated:/.test(txt));
    // Interrupteurs bien formés (case + piste dans le même libellé, aucun « > » parasite)
    const badToggles = await page.$$eval('label.toggle', (ls) => ls.filter((l) => !l.querySelector('input[role=switch]') || !l.querySelector('.toggle-track') || /^\s*>/.test(l.textContent)).length);
    if (badToggles > 0) { check('interrupteurs bien formés sur ' + p, false); }
  }
  // Aide (wiki) : menu sous Paramètres, nouvel onglet, rubriques, recherche, 404, raccourci /wiki
  await page.goto(BASE + '/admin');
  const helpLink = page.locator('nav.side a[href$="/admin/wiki"]').first();
  check('aide : lien « Aide » dans le menu, nouvel onglet', (await helpLink.textContent()).includes('Aide') && (await helpLink.getAttribute('target')) === '_blank');
  let wr = await page.goto(BASE + '/wiki/recommandations/carrousels/sources-tri-et-apprentissage');
  check('aide : /wiki redirige vers /admin/wiki', wr.status() === 200 && page.url().endsWith('/admin/wiki/recommandations/carrousels/sources-tri-et-apprentissage'));
  await page.waitForTimeout(400);
  check('aide : rubrique entière à la suite, page demandée affichée et surlignée à gauche, sommaire',
    (await page.textContent('.wk-content h1')).includes('Recommandations') && await page.locator('.wk-section').count() > 5
    && (await page.textContent('#p-recommandations-carrousels-sources-tri-et-apprentissage h2')).includes('Sources, tri et apprentissage')
    && (await page.textContent('.wk-nav a[aria-current=page]')).includes('Sources, tri et apprentissage')
    && await page.locator('.wk-toc ul:not([hidden]) a').count() > 2);
  await page.click('.wk-nav a[data-wk-section$="apercu-direct"]');
  await page.waitForFunction(() => location.pathname.endsWith('/recommandations/apercu-direct'), null, { timeout: 4000 });
  await page.waitForTimeout(700);
  check('aide : clic à gauche = défilement jusqu\'à la page, sans rechargement, page surlignée',
    (await page.textContent('.wk-nav a[aria-current=page]')).includes('Aperçu direct') && await page.locator('.wk-section').count() > 5);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(400);
  check('aide : en remontant, la page lue change à gauche', (await page.textContent('.wk-nav a[aria-current=page]')).includes('Recommandations'));
  await page.fill('[data-wk-search]', 'consentement');
  await page.locator('.wk-result >> nth=0').waitFor({ timeout: 3000 });
  check('aide : recherche (sans accents)', (await page.textContent('[data-wk-results]')).includes('Personnalisation et consentement'));
  await page.screenshot({ path: OUT + '/50-aide.png' });
  await page.keyboard.press('Enter');
  await page.waitForURL(/personnalisation-et-consentement$/, { timeout: 5000 });
  await page.waitForLoadState();
  check('aide : Entrée ouvre le premier résultat', page.url().endsWith('/carrousels/personnalisation-et-consentement'));
  await page.goto(BASE + '/admin/wiki/catalogue/mapping');
  // image chargée à l'approche (lazy) : la page Mapping est plus bas dans la rubrique Catalogue
  await page.locator('#p-catalogue-mapping .wk-img').first().scrollIntoViewIfNeeded();
  await page.waitForFunction(() => { const i = document.querySelector('#p-catalogue-mapping .wk-img'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 5000 }).catch(() => {});
  check('aide : capture d\'écran chargée', await page.$eval('#p-catalogue-mapping .wk-img', (i) => i.complete && i.naturalWidth > 0));
  // (requêtes directes : une page 404 ouverte dans l'onglet compterait comme erreur de la console)
  wr = await page.request.get(BASE + '/admin/wiki/inexistante');
  check('aide : page inexistante = 404 avec rubriques', wr.status() === 404 && (await wr.text()).includes('data-wk-nav'));
  wr = await page.request.get(BASE + '/admin/wiki/images/..%2F..%2F.env');
  check('aide : image hors dossier refusée', wr.status() === 404);
  // Fiche carrousel : boutons d'action au-dessus de la liste de contrôle
  await page.goto(BASE + '/admin/carousels/edit');
  check('carrousel : boutons avant la liste de contrôle', await page.evaluate(() => {
    const f = document.querySelector('.cr-foot'); const l = document.querySelector('.ca-preview');
    return !!(f && l && (f.compareDocumentPosition(l) & Node.DOCUMENT_POSITION_FOLLOWING));
  }));

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

  // Action : ajout d'un groupe de synonymes (une ligne de mots séparés par des virgules)
  await page.goto(BASE + '/admin/synonyms');
  await page.fill('form[action$="/admin/synonyms/add"] input[name=words]', 'lunette, masque');
  await page.click('form[action$="/admin/synonyms/add"] button');
  check('ajout synonyme', (await page.textContent('#synonyms-table')).includes('lunette'));
  await page.fill('form[action$="/admin/synonyms/add"] input[name=words]', 'seulement-un-mot');
  await page.click('form[action$="/admin/synonyms/add"] button');
  check('synonymes : un seul mot refusé', (await page.textContent('body')).includes('au moins deux mots'));
  // Recherche dans le tableau (sans accents ni majuscules) puis modification dans la fenêtre
  const synRows = await page.locator('#synonyms-table tbody tr[data-search]').count();
  await page.fill('[data-table-search]', 'LUNETTÉ');
  const synVisible = await page.locator('#synonyms-table tbody tr[data-search]:visible').count();
  check('synonymes : recherche dans le tableau', synVisible === 1 && (synRows === 1 || synVisible < synRows)
    && (await page.textContent('[data-table-search-count]')).includes('1 / '));
  await page.fill('[data-table-search]', 'zzzz-introuvable');
  check('synonymes : message si aucun résultat', await page.isVisible('[data-table-search-empty]'));
  await page.fill('[data-table-search]', 'lunette');
  await page.click('#synonyms-table tr[data-search]:visible button[data-dialog-open="synonym-dialog"]');
  check('synonymes : fenêtre « Modifier » pré-remplie', (await page.inputValue('#synonym-dialog input[name=words]')) === 'lunette, masque'
    && !(await page.isChecked('#synonym-dialog input[name=one_way]')));
  await page.fill('#synonym-dialog input[name=words]', 'lunette, masque, masques');
  await page.check('#synonym-dialog input[name=one_way]', { force: true });
  await Promise.all([page.waitForNavigation(), page.click('#synonym-dialog .modal-foot button.btn:not(.btn-light)')]);
  const synRow = await page.textContent('#synonyms-table tr[data-search*="lunette"]');
  check('synonymes : modification enregistrée (sens unique)', synRow.includes('masques') && synRow.includes('→') && synRow.includes('Sens unique'));
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
  // anti-robots, touche Entrée, autocomplétion d'origine, photo au survol, journaux
  check('paramètres : interrupteurs', (await page.$$('.toggle input[role=switch]')).length === 5);
  await page.click('label.toggle:has(input[name=block_bad_user_agents])');
  await page.click('form.st .st-foot button.btn');
  check('interrupteur : désactivé et enregistré', (await page.isChecked('input[name=block_bad_user_agents][type=checkbox]')) === false);
  await page.screenshot({ path: OUT + '/14-parametres-interrupteurs.png', fullPage: false });
  await page.click('label.toggle:has(input[name=block_bad_user_agents])');
  await page.click('form.st .st-foot button.btn');
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
  await page.locator('.ms-list .ms-item').nth(1).click();
  const nChips = await page.locator('.ms-toggle .chip').count();
  await page.click('.ms-toggle .chip .chip-x');
  check('conditions : la croix d\'un tag retire la valeur', nChips === 2 && (await page.locator('.ms-toggle .chip').count()) === 1
    && !(await page.locator('.ms-list .ms-item input:checked').nth(1).count()));
  await page.click('[data-f=name]'); // clic hors de la liste : elle se ferme
  await page.click('[data-add-cond]');
  await page.locator('.br-cond select.br-field').nth(1).selectOption('name');
  const nameOps = await page.locator('.br-cond').nth(1).locator('select.br-op option').evaluateAll((o) => o.map((x) => x.value));
  check('conditions : « Nom du produit » en comparaison de texte seule', nameOps[0] === 'contains' && !nameOps.includes('is'));
  const nameText = page.locator('.br-cond').nth(1).locator('input.br-text');
  await nameText.fill('batterie');
  await nameText.press('Enter');
  await nameText.fill('station');
  await nameText.press('Enter');
  await nameText.fill('a');
  await nameText.press('Enter');
  await page.locator('.br-cond').nth(1).locator('.tag-input .chip').nth(1).locator('.chip-x').click();
  check('conditions : plusieurs textes en tags, retrait par la croix', (await page.locator('.br-cond').nth(1).locator('.tag-input .chip').allTextContents()).map((t) => t.replace('✕', '').trim()).join('|') === 'batterie|a');
  await Promise.all([page.waitForNavigation(), page.click('.modal-foot button.btn:not(.btn-light)')]);
  check('règles de boost : règle enregistrée', (await page.textContent('body')).includes('Règle « Test e2e » enregistrée') && (await page.textContent('table')).includes('×2'));
  await page.screenshot({ path: OUT + '/16-boosts.png', fullPage: false });
  await page.click('form[action$="/admin/boost-rules/delete"] button, .cr-delete-btn');
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
  check('projets : liste', (await page.$$eval('input.name-input', (i) => i.map((x) => x.value))).includes('Projet Test'));
  await page.screenshot({ path: OUT + '/20-projets.png', fullPage: false });
  await Promise.all([page.waitForNavigation(), page.selectOption('.project-switch select', { label: 'Projet Test' })]);
  check('projets : sélecteur → projet Test actif', (await page.textContent('.project-chip')).includes('Projet Test'));
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

  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1' });
  const mp = await mobile.newPage();
  await mp.goto(BASE + '/admin/login');
  await mp.fill('input[name=token]', TOKEN);
  await mp.click('button.btn');
  await mp.goto(BASE + '/admin/demo');
  // La démo n'a pas d'URL de résultats configurée par défaut.
  await mp.waitForFunction(() => window.DeepFind?.isActive());
  await mp.evaluate(() => { window.DeepFind.config.resultsUrl = '/admin/demo?q='; });
  await mp.route('**/api/v1/search?*', async (route) => {
    const response = await route.fetch();
    const data = await response.json();
    data.total = data.products.length + 117;
    await route.fulfill({ response, json: data });
  }, { times: 1 });
  await mp.fill('#search', 'masque');
  await mp.waitForSelector('.df-card', { timeout: 5000 });
  check('widget mobile : pas de défilement horizontal', await mp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  const seeAll = mp.locator('.df-see-all[href$="q=masque"]').filter({ hasText: 'Tout voir (+117)' });
  await seeAll.waitFor({ timeout: 5000 });
  for (const width of [390, 320]) {
    await mp.setViewportSize({ width, height: 844 });
    // mesurer une fois l'animation d'ouverture terminée (sinon hauteurs à 43,999 px pendant la transition)
    await mp.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'));
    const aligned = await mp.locator('.df-tools').evaluate((tools) => {
      const [filters, sort, link] = ['.df-filters-btn', '.df-sort', '.df-see-all']
        .map((selector) => tools.querySelector(selector).getBoundingClientRect());
      return filters.top === sort.top && sort.top === link.top
        && filters.height === sort.height && filters.height >= 44
        && filters.right <= sort.left && sort.right <= link.left
        && Math.abs(link.right - tools.getBoundingClientRect().right) < 1;
    });
    check('widget mobile : filtres, tri et lien alignés à ' + width + ' px', aligned);
    await mp.screenshot({ path: OUT + (width === 390 ? '/08-widget-mobile.png' : '/22-widget-mobile-320.png') });
  }
  await mp.setViewportSize({ width: 390, height: 844 });
  const filters = mp.locator('.df-filters-btn');
  const side = mp.locator('.df-side');
  check('widget mobile : filtres masqués au départ', !await side.isVisible() && await filters.getAttribute('aria-expanded') === 'false');
  await filters.tap();
  check('widget mobile : stock, promotion et facettes dans le volet', await side.locator('input[name=in_stock]').isVisible()
    && await side.locator('input[name=on_sale]').isVisible() && await side.locator('.df-fv').first().isVisible());
  await side.locator('input[name=in_stock]').check();
  await side.locator('.df-clear').waitFor();
  await mp.screenshot({ path: OUT + '/09-widget-mobile-filtres.png' });
  const closeFilters = side.getByRole('button', { name: 'Fermer les filtres' });
  const closeTop = (await closeFilters.boundingBox()).y;
  const scrollTop = await side.locator('.df-side-body').evaluate((body) => { body.scrollTop = body.scrollHeight; return body.scrollTop; });
  check('widget mobile : fermeture accessible après défilement', scrollTop > 0 && (await closeFilters.boundingBox()).y === closeTop);
  await closeFilters.tap();
  check('widget mobile : fermeture du volet et retour aux résultats', !await side.isVisible() && await mp.locator('.df-main').isVisible()
    && await filters.getAttribute('aria-expanded') === 'false');
  await filters.tap();
  check('widget mobile : filtre conservé à la réouverture', await side.locator('input[name=in_stock]').isChecked());
  await side.getByRole('button', { name: 'Effacer les filtres' }).tap();
  check('widget mobile : effacement du filtre de stock', !await side.locator('input[name=in_stock]').isChecked());
  await mp.keyboard.press('Escape');
  check('widget mobile : Échap ferme le volet', !await side.isVisible() && await filters.isVisible());
  await filters.tap();
  await mp.locator('.df-side-backdrop').tap({ position: { x: 380, y: 100 } });
  check('widget mobile : un appui à côté du volet revient aux résultats', !await side.isVisible() && await filters.isVisible());

  // Mesure d'audience : page Audience, puis faux site (même domaine) qui charge le widget ; signal « page vue » selon les pages choisies
  await page.goto(BASE + '/admin/audience');
  // données de test : une seule journée complète (la veille) → un point par série au lieu d'une courbe
  check('audience : courbe quotidienne et indicateurs', await page.locator('[data-audience-chart] svg .aud-line, [data-audience-chart] svg .aud-dot:not(.aud-hover)').count() === 2
    && (await page.textContent('.cards')).includes('Visiteurs qui cherchent'));
  const chartBox = await page.locator('[data-audience-chart]').boundingBox();
  await page.mouse.move(chartBox.x + chartBox.width - 30, chartBox.y + 120);
  check('audience : infobulle au survol', await page.isVisible('.aud-tip') && (await page.textContent('.aud-tip')).includes('Visiteurs'));
  check('audience : adresses masquées sauf « Pages choisies »', !await page.isVisible('.aud-paths textarea'));
  // période personnalisée : dates de la veille au jour même
  check('audience : champs de dates masqués par défaut', !await page.isVisible('#aud-custom'));
  await page.click('[data-custom-toggle]');
  // « aujourd'hui » = journée de mesure en cours (elle change à 4 h, heure de Paris) : date maximale proposée par la page
  const tYmd = await page.getAttribute('#aud-custom input[name=to]', 'max');
  const tD = new Date(tYmd + 'T12:00:00Z');
  const yYmd = new Date(tD.getTime() - 86400000).toISOString().slice(0, 10);
  await page.fill('#aud-custom input[name=from]', yYmd);
  await page.fill('#aud-custom input[name=to]', tYmd);
  await Promise.all([page.waitForNavigation(), page.click('#aud-custom button')]);
  check('audience : période personnalisée appliquée (dates, bouton actif, export)', page.url().includes('from=' + yYmd)
    && (await page.textContent('.aud-range')).includes(yYmd.split('-').reverse().join('/')) && await page.isVisible('#aud-custom')
    && (await page.getAttribute('a[href*="/admin/audience/export"]', 'href')).includes('from=' + yYmd));
  const siteKey = fs.readFileSync('tests/_tmp/key', 'utf8').trim();
  const sitePage = async (path) => {
    const sc = await browser.newContext({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
    await sc.route(/\/boutique\//, (r) => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><title>Boutique</title></head><body>'
      + '<input name="q"><script src="' + BASE + '/widget/deepfind.js?v=24" data-key="' + siteKey + '" data-lang="fr"></script></body></html>' }));
    let n = 0;
    sc.on('request', (r) => { if (r.url().endsWith('/api/v1/visit')) { n++; } });
    const sp = await sc.newPage();
    await sp.goto(BASE + path);
    await sp.waitForTimeout(1500);
    await sc.close();
    return n;
  };
  check('audience : signal « page vue » envoyé par le widget (toutes les pages)', await sitePage('/boutique/plongee/palmes.html') === 1);
  await page.check('.aud-pages input[value=paths]');
  await page.fill('.aud-paths textarea', '/boutique/apnee');
  await Promise.all([page.waitForNavigation(), page.click('.aud-settings button.btn')]);
  check('audience : réglages enregistrés', (await page.textContent('body')).includes('Réglages de la mesure d\'audience enregistrés')
    && await page.isVisible('.aud-paths textarea'));
  check('audience : pages choisies (signal seulement sur /boutique/apnee…)', await sitePage('/boutique/plongee.html') === 0 && await sitePage('/boutique/apnee/masques.html') === 1);
  await page.check('.aud-pages input[value=all]');
  await Promise.all([page.waitForNavigation(), page.click('.aud-settings button.btn')]);

  // Règles selon la page : création dans l'extranet, puis effet sur un faux site (filtre pré-coché retirable)
  await page.goto(BASE + '/admin/page-rules/edit');
  await page.fill('[data-f=name]', 'Règle C4');
  await page.fill('.pr-url-text', '/boutique/c4/');
  await page.fill('[data-f=label]', 'Marque C4');
  await page.selectOption('.br-cond select.br-field', 'brand');
  await page.click('.br-cond .ms-toggle');
  await page.locator('.ms-list .ms-item', { hasText: 'C4' }).first().click();
  await page.click('[data-f=name]');
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  check('règles de page : règle créée', (await page.textContent('body')).includes('Règle « Règle C4 » enregistrée'));
  await page.goto(BASE + '/admin/page-rules');
  check('règles de page : liste (adresse, action)', (await page.textContent('.pr-table')).includes('/boutique/c4/') && (await page.textContent('.pr-table')).includes('Filtre pré-coché'));
  await page.goto(BASE + '/admin/preview?q=palme&page=' + encodeURIComponent('/boutique/c4/x.html'));
  check('règles de page : aperçu interne avec « Page du site »', (await page.textContent('.flash-info')).includes('Règle C4'));
  const sc = await browser.newContext({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
  await sc.route(/\/boutique\//, (r) => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><title>Boutique</title></head><body>'
    + '<input name="q"><script src="' + BASE + '/widget/deepfind.js?v=24" data-key="' + siteKey + '" data-lang="fr"></script></body></html>' }));
  const shop = await sc.newPage();
  await shop.goto(BASE + '/boutique/c4/palmes.html');
  await shop.waitForFunction(() => window.DeepFind && window.DeepFind.isActive());
  await shop.fill('input[name=q]', 'palme');
  await shop.waitForSelector('.df-card', { timeout: 5000 });
  await shop.waitForSelector('.df-pagefilter:not([hidden])', { timeout: 5000 });
  const nLimited = await shop.locator('.df-card').count();
  check('règles de page : widget limité, bandeau « Marque C4 ✕ »', (await shop.textContent('.df-pagefilter')).includes('Marque C4') && nLimited > 0);
  await shop.click('.df-pagefilter-btn');
  await shop.waitForSelector('.df-pagefilter-back', { timeout: 5000 });
  await shop.waitForFunction((n) => document.querySelectorAll('.df-card').length > n, nLimited, { timeout: 5000 }).catch(() => {});
  check('règles de page : ✕ élargit à tout le catalogue', await shop.locator('.df-card').count() > nLimited && (await shop.textContent('.df-pagefilter')).includes('tout le catalogue'));
  await sc.close();
  await page.goto(BASE + '/admin/page-rules');
  await page.click('form[action$="/admin/page-rules/delete"] button, .cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  check('règles de page : suppression', (await page.textContent('body')).includes('Règle supprimée'));
  // « contient » : textes en tags, enregistrés puis réaffichés
  await page.goto(BASE + '/admin/page-rules/edit');
  await page.fill('[data-f=name]', 'Règle contient');
  await page.fill('.pr-url-text', '/alimentation');
  await page.selectOption('.br-cond select.br-field', 'brand');
  const lopOptions = await page.locator('.br-cond select.br-op option').evaluateAll((o) => o.map((x) => x.value));
  await page.selectOption('.br-cond select.br-op', 'contains');
  await page.fill('.br-cond .tag-input input.br-text', 'ress');
  await page.press('.br-cond .tag-input input.br-text', 'Enter');
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  check('fiches : « Supprimer » aligné avec « Enregistrer » (règle selon la page)', await deleteAligned());
  check('règles de page : « contient » proposé, enregistré et réaffiché', lopOptions.join(',') === 'in,not_in,contains,not_contains'
    && (await page.textContent('body')).includes('enregistrée') && await page.inputValue('.br-cond select.br-op') === 'contains'
    && (await page.textContent('.br-cond .tag-input')).includes('ress'));
  page.once('dialog', () => {});
  await page.click('form[action$="/admin/page-rules/delete"] button, .cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);

  // Popins : création dans l'extranet, affichage sur un faux site (pages choisies, fermeture mémorisée, statistiques)
  await page.goto(BASE + '/admin/popins/edit');
  await page.fill('[data-f=name]', 'Popin e2e');
  await page.check('input[name=pp_pages][value=custom]');
  await page.fill('[data-urls=urls] .pr-url-text', '/boutique/promo/');
  await page.click('[data-add-url=exclude_urls]');
  await page.fill('[data-urls=exclude_urls] .pr-url-text', '/boutique/promo/panier');
  await page.fill('[data-f=delay_seconds]', '0');
  await page.fill('[data-f=remember_days]', '2');
  await page.fill('[data-f=image_desktop]', BASE + '/assets/demo/palme.svg');
  await page.fill('[data-f=alt]', 'Soldes sur les palmes');
  await page.fill('[data-f=link_url]', BASE + '/boutique/soldes.html');
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  check('popins : popin créée', (await page.textContent('body')).includes('Popin « Popin e2e » enregistrée'));
  check('fiches : « Supprimer » aligné avec « Enregistrer » (popin)', await deleteAligned());
  check('popins : fiche réaffichée (pages, exclusion, délai)', await page.inputValue('[data-urls=urls] .pr-url-text') === '/boutique/promo/'
    && await page.inputValue('[data-urls=exclude_urls] .pr-url-text') === '/boutique/promo/panier' && await page.inputValue('[data-f=delay_seconds]') === '0');
  await page.screenshot({ path: OUT + '/popin-fiche.png', fullPage: true });
  await page.goto(BASE + '/admin/popins');
  check('popins : liste (pages, exclusion)', (await page.textContent('.pp-table')).includes('/boutique/promo/') && (await page.textContent('.pp-table')).includes('sauf /boutique/promo/panier'));
  const shopHtml = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Boutique</title></head><body>'
    + '<input name="q"><h1>Boutique</h1><script src="' + BASE + '/widget/deepfind.js?v=25" data-key="' + siteKey + '" data-lang="fr"></script></body></html>';
  const popinCtx = async () => {
    const c = await browser.newContext({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
    await c.route(/\/boutique\//, (r) => r.fulfill({ contentType: 'text/html', body: shopHtml }));
    const pg = await c.newPage();
    pg.on('pageerror', (e) => errors.push('JS boutique : ' + e.message));
    return [c, pg];
  };
  const [pc1, pp1] = await popinCtx();
  await pp1.goto(BASE + '/boutique/plongee.html');
  await pp1.waitForFunction(() => window.DeepFind && window.DeepFind.isActive());
  await pp1.waitForTimeout(800);
  const nOther = await pp1.locator('.df-popin').count();
  await pp1.goto(BASE + '/boutique/promo/panier.html');
  await pp1.waitForFunction(() => window.DeepFind && window.DeepFind.isActive());
  await pp1.waitForTimeout(800);
  const nExcluded = await pp1.locator('.df-popin').count();
  check('popins : absente des autres pages et des pages exclues', nOther === 0 && nExcluded === 0);
  await pp1.goto(BASE + '/boutique/promo/palmes.html');
  await pp1.waitForSelector('.df-popin .df-popin-box', { timeout: 5000 });
  check('popins : affichée sur la page choisie (image, texte alternatif, lien, fenêtre accessible)',
    (await pp1.getAttribute('.df-popin-img', 'src')).endsWith('/assets/demo/palme.svg') && await pp1.getAttribute('.df-popin-img', 'alt') === 'Soldes sur les palmes'
    && (await pp1.getAttribute('.df-popin-link', 'href')).endsWith('/boutique/soldes.html') && await pp1.getAttribute('.df-popin-frame', 'role') === 'dialog'
    && await pp1.evaluate(() => document.activeElement && document.activeElement.classList.contains('df-popin-close')));
  await pp1.waitForTimeout(400); // fin de l'animation d'ouverture
  const xBox = await pp1.locator('.df-popin-close').boundingBox();
  const pBox = await pp1.locator('.df-popin-box').boundingBox();
  check('popins : croix de fermeture à l\'extérieur, au-dessus de la popin', xBox.y + xBox.height <= pBox.y && Math.abs((xBox.x + xBox.width) - (pBox.x + pBox.width)) < 2);
  await pp1.waitForTimeout(300);
  await pp1.screenshot({ path: OUT + '/popin-site.png' });
  await pp1.click('.df-popin-close');
  check('popins : fermeture', await pp1.locator('.df-popin').count() === 0);
  await pp1.reload();
  await pp1.waitForFunction(() => window.DeepFind && window.DeepFind.isActive());
  await pp1.waitForTimeout(800);
  const remembered = await pp1.evaluate(() => Object.keys(localStorage).filter((k) => k.indexOf('df-popin:') === 0).map((k) => Number(localStorage.getItem(k))));
  check('popins : fermeture mémorisée 2 jours (pas de réaffichage)', await pp1.locator('.df-popin').count() === 0 && remembered.length === 1
    && remembered[0] > Date.now() + 1.9 * 86400000 && remembered[0] < Date.now() + 2.1 * 86400000);
  await pc1.close();
  // autre visiteur : clic sur le lien de la popin (compté), touche Échap sur mobile
  const [pc2, pp2] = await popinCtx();
  await pp2.goto(BASE + '/boutique/promo/masques.html');
  await pp2.waitForSelector('.df-popin .df-popin-link', { timeout: 5000 });
  await Promise.all([pp2.waitForNavigation(), pp2.click('.df-popin-link')]);
  check('popins : clic sur le lien → page de destination', pp2.url().endsWith('/boutique/soldes.html'));
  await pc2.close();
  const pmc = await browser.newContext({ ...require('playwright').devices['iPhone 13'] });
  await pmc.route(/\/boutique\//, (r) => r.fulfill({ contentType: 'text/html', body: shopHtml }));
  const pmp = await pmc.newPage();
  await pmp.goto(BASE + '/boutique/promo/tubas.html');
  await pmp.waitForSelector('.df-popin .df-popin-box', { timeout: 5000 });
  const pmBox = await pmp.locator('.df-popin-box').boundingBox();
  await pmp.screenshot({ path: OUT + '/popin-mobile.png' });
  await pmp.keyboard.press('Escape');
  check('popins : mobile (largeur de l\'écran) et fermeture par Échap', pmBox && pmBox.width <= 390 - 31 && await pmp.locator('.df-popin').count() === 0);
  await pmc.close();
  await page.waitForTimeout(500);
  await page.goto(BASE + '/admin/popins');
  const ppNums = await page.locator('.pp-table tbody tr:first-child td.num').evaluateAll((tds) => tds.map((td) => td.textContent.trim()));
  // N°, affichages, clics, CTR, fermetures
  check('popins : statistiques (3 affichages, 1 clic, 2 fermetures)', ppNums[1] === '3' && ppNums[2] === '1' && ppNums[4] === '2');
  await page.screenshot({ path: OUT + '/popin-liste.png', fullPage: true });
  await page.click('form[action$="/admin/popins/delete"] button, .cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  check('popins : suppression', (await page.textContent('body')).includes('Popin supprimée'));

  // Popin au contenu HTML rédigé dans l'éditeur visuel (titre, texte, bouton), puis vérifié en code et sur le site
  await page.goto(BASE + '/admin/popins/edit');
  await page.fill('[data-f=name]', 'Popin éditeur');
  await page.selectOption('[data-f=mode]', 'code');
  await page.fill('[data-f=delay_seconds]', '0');
  await page.fill('[data-f=min_height]', '360');
  await page.click('.wy-area');
  await page.keyboard.type('Livraison offerte');
  await page.selectOption('.wy-block', 'h2');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.type('Dès 50 € d\'achat, ');
  await page.click('.wy-btn[aria-label="Gras"]');
  await page.keyboard.type('partout en France');
  await page.keyboard.press('Enter');
  await page.click('.wy-btn[aria-label="Bouton (lien mis en avant)"]');
  await page.fill('.wy-pop input[type=url]', BASE + '/boutique/soldes.html');
  await page.fill('.wy-pop input[type=text]', 'J\'en profite');
  await page.click('.wy-pop .btn:not(.btn-light)');
  const wyHtml = await page.inputValue('[data-f=html]');
  check('éditeur visuel : titre, gras et bouton écrits dans le HTML', /<h2>Livraison offerte<\/h2>/.test(wyHtml) && /<b>partout en France<\/b>|<strong>partout en France<\/strong>/.test(wyHtml)
    && wyHtml.includes('/boutique/soldes.html') && wyHtml.includes('J\'en profite') && !/<p><br><\/p>$/.test(wyHtml));
  await page.screenshot({ path: OUT + '/editeur-visuel.png', fullPage: false, clip: { x: 0, y: Math.max(0, (await page.locator('.wy').boundingBox()).y - 140), width: 1440, height: 700 } });
  await page.click('[data-wy-tab=code]');
  await page.fill('[data-f=html]', wyHtml + '<p>Ajout en code</p><script>alert(1)</script>');
  await page.click('[data-wy-tab=visual]');
  check('éditeur visuel : modification du code reprise, script ignoré', (await page.textContent('.wy-area')).includes('Ajout en code') && await page.locator('.wy-area script').count() === 0);
  await page.click('.wy-area h2');
  // aperçu direct : la popin non enregistrée, dessinée par le vrai widget, en format ordinateur puis mobile
  await page.click('[data-live-preview]');
  const lpf = page.frameLocator('.lv-dialog .lv-frame');
  await lpf.locator('.df-popin .df-popin-html h2').waitFor({ timeout: 5000 });
  const lpDesk = await lpf.locator('.df-popin-box').boundingBox();
  const lpTitle = await lpf.locator('.df-popin-html h2').textContent();
  await page.waitForTimeout(400);
  await page.screenshot({ path: OUT + '/apercu-popin-ordinateur.png' });
  await page.click('.lv-sw[data-device=mobile]');
  await page.waitForTimeout(500);
  const lpMob = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-popin-box').evaluate((n) => n.getBoundingClientRect().width);
  const lpFrameW = await page.evaluate(() => document.querySelector('.lv-dialog .lv-frame').style.width);
  await page.screenshot({ path: OUT + '/apercu-popin-mobile.png' });
  check('aperçu direct : popin non enregistrée dessinée par le widget, ordinateur puis mobile', lpTitle === 'Livraison offerte' && lpDesk !== null
    && lpFrameW === '390px' && lpMob <= 390 - 31 && lpMob > 300);
  await page.click('.lv-dialog .lv-actions .btn:not(.btn-light)');
  await page.evaluate(() => localStorage.removeItem('df-lp-device'));
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  check('éditeur visuel : popin enregistrée, contenu réaffiché', (await page.textContent('body')).includes('Popin « Popin éditeur » enregistrée')
    && (await page.textContent('.wy-area')).includes('Livraison offerte') && !(await page.inputValue('[data-f=html]')).includes('script'));
  const [pc3, pp3] = await popinCtx();
  await pp3.goto(BASE + '/boutique/plongee.html');
  await pp3.waitForSelector('.df-popin .df-popin-html h2', { timeout: 5000 });
  check('éditeur visuel : contenu affiché sur le site (titre, bouton)', (await pp3.textContent('.df-popin-html h2')) === 'Livraison offerte'
    && (await pp3.getAttribute('.df-popin-html a', 'href')).endsWith('/boutique/soldes.html')
    && await pp3.evaluate(() => getComputedStyle(document.querySelector('.df-popin-html a.df-btn')).textDecorationLine) === 'none');
  await pp3.waitForTimeout(400); // fin de l'animation d'ouverture
  const hBox = await pp3.locator('.df-popin-box').boundingBox();
  check('popins : hauteur minimale respectée (360 px), contenu centré', Math.round(hBox.height) >= 360
    && await pp3.evaluate(() => { const b = document.querySelector('.df-popin-box').getBoundingClientRect(); const c = document.querySelector('.df-popin-html').getBoundingClientRect(); return c.top - b.top > 20 && Math.abs((c.top - b.top) - (b.bottom - c.bottom)) < 3; }));
  await pp3.waitForTimeout(400);
  await pp3.screenshot({ path: OUT + '/popin-html-site.png' });
  await pc3.close();
  await page.click('form[action$="/admin/popins/delete"] button, .cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);

  // Carrousels de produits : création (produit épinglé, tri), aperçu, affichage sur un faux site (emplacement, défilement, glisser,
  // vues et clics), mobile, historique personnalisé avec consentement
  await page.goto(BASE + '/admin/carousels/edit');
  await page.fill('[data-f=name]', 'Carrousel e2e');
  await page.fill('[data-f=selector]', '#reco');
  await page.selectOption('[data-f=insert_mode]', 'after');
  await page.fill('[data-f=title]', 'Nos coups de cœur');
  await page.fill('[data-f=title_class]', 'titre-theme md:text-3xl');
  await page.fill('[data-f=more_url]', '/boutique/promos.html');
  await page.fill('[data-item-search]', 'predator');
  await page.waitForSelector('.ca-result');
  await page.click('.ca-result >> nth=0');
  await page.selectOption('[data-f=sort_by]', 'sales');
  await page.fill('[data-f=max_items]', '8');
  await page.click('[data-preview-run]');
  await page.waitForSelector('.ca-pcard');
  const pinnedSku = await page.getAttribute('.ca-item', 'data-sku');
  const pvCount = await page.locator('.ca-pcard').count();
  check('carrousels : aperçu des produits (épinglé en tête, nombre)', pvCount === 8 && (await page.textContent('.ca-pcard >> nth=0')).includes(pinnedSku));
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  check('carrousels : carrousel créé et réaffiché', (await page.textContent('body')).includes('Carrousel « Carrousel e2e » enregistré')
    && await page.inputValue('[data-f=selector]') === '#reco' && await page.locator('.ca-item').count() === 1);
  await page.screenshot({ path: OUT + '/carrousel-fiche.png', fullPage: true });
  check('fiches : « Supprimer » aligné avec « Enregistrer » (carrousel)', await deleteAligned());
  check('carrousels : liste de contrôle nommée comme telle', (await page.textContent('.ca-preview-head')).includes('Liste des produits sélectionnés (contrôle)'));
  await page.click('[data-live-preview-alt]'); // « Voir le rendu réel » depuis la liste de contrôle
  const lcf = page.frameLocator('.lv-dialog .lv-frame');
  await lcf.locator('#df-preview-slot .df-carousel .df-card >> nth=0').waitFor({ timeout: 5000 });
  const lcCards = await lcf.locator('.df-carousel .df-card').count();
  const lcTitle = await lcf.locator('.df-car-title').textContent();
  await page.waitForTimeout(400);
  await page.screenshot({ path: OUT + '/apercu-carrousel-ordinateur.png' });
  await page.click('.lv-sw[data-device=mobile]');
  await page.waitForTimeout(600);
  const lcMob = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-car-next').evaluate((n) => getComputedStyle(n).display);
  await page.screenshot({ path: OUT + '/apercu-carrousel-mobile.png' });
  check('aperçu direct : carrousel dessiné dans une page fictive (8 produits, titre), mobile sans flèches', lcCards === 8 && lcTitle === 'Nos coups de cœur' && lcMob === 'none');
  await page.click('.lv-dialog .lv-actions .btn:not(.btn-light)');
  await page.evaluate(() => localStorage.removeItem('df-lp-device'));
  // modèle de carte propre au carrousel : visible dans l'aperçu, puis retour au modèle par défaut
  const tplDefault = await page.getAttribute('[data-card-tpl]', 'data-default-desktop');
  const tplStart = await page.inputValue('[data-f=card_template]');
  const wrapShown = (await page.textContent('[data-card-tpl] .tpl-wrap')).includes('<a class="df-card" href="') && await page.getAttribute('[data-f=card_link]', 'placeholder') === '{{url}}';
  await page.fill('[data-f=card_template]', '<div class="df-name perso-nom">{{name}}</div>\n<div class="perso-prix">{{price}}</div>\n<small class="perso-url">{{url}}</small>');
  await page.click('[data-live-preview]');
  await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .df-card .perso-prix >> nth=0').waitFor({ timeout: 5000 });
  const persoN = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .perso-prix').count();
  const persoImg = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .df-media').count();
  const persoUrl = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .perso-url >> nth=0').textContent();
  await page.waitForTimeout(300);
  await page.screenshot({ path: OUT + '/apercu-carrousel-modele.png' });
  await page.click('.lv-dialog .lv-actions .btn:not(.btn-light)');
  // lien de la carte du carrousel : paramètres ajoutés à l'adresse du produit, puis chemin seul
  await page.fill('[data-f=card_link]', '{{url}}?utm_campaign=carrousel&ref={{sku}}');
  await page.click('[data-live-preview]');
  await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .df-card >> nth=0').waitFor({ timeout: 5000 });
  await page.waitForTimeout(300);
  const linkParams = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .df-card >> nth=0').getAttribute('href');
  await page.click('.lv-dialog .lv-actions .btn:not(.btn-light)');
  await page.fill('[data-f=card_link]', '{{url_path}}');
  await page.click('[data-live-preview]');
  await page.waitForTimeout(800);
  const linkPath = await page.frameLocator('.lv-dialog .lv-frame').locator('.df-carousel .df-card >> nth=0').getAttribute('href');
  await page.click('.lv-dialog .lv-actions .btn:not(.btn-light)');
  check('carrousels : lien de la carte modifiable ({{url}} + paramètres, {{url_path}})', /^https:\/\/www\.example\.com\/[^?#]+\?utm_campaign=carrousel&ref=[A-Z0-9-]+$/.test(linkParams)
    && new URL(linkPath).origin === new URL(BASE).origin && new URL(linkPath).pathname.endsWith('.html'));
  await page.click('[data-tpl-reset]');
  check('carrousels : modèle de carte modifiable (aperçu, lien {{url}} affiché et utilisable), « revenir au modèle par défaut »', tplStart === tplDefault && persoN === 8 && persoImg === 0
    && wrapShown && /^https:\/\/www\.example\.com\//.test(persoUrl)
    && await page.inputValue('[data-f=card_template]') === tplDefault);
  const hFrame = (await page.request.get(BASE + '/admin/live-preview?type=carousel')).headers();
  const hOther = (await page.request.get(BASE + '/admin/carousels')).headers();
  check('aperçu direct : seule la page d\'aperçu peut s\'afficher dans un cadre, et seulement dans l\'extranet',
    hFrame['x-frame-options'] === 'SAMEORIGIN' && hFrame['content-security-policy'].includes("frame-ancestors 'self'")
    && hOther['x-frame-options'] === 'DENY' && hOther['content-security-policy'].includes("frame-ancestors 'none'"));
  const shopCar = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Boutique</title>'
    + '<style>body{font-family:system-ui,sans-serif;margin:0;padding:24px;max-width:1200px;margin:auto}</style></head><body>'
    + '<input name="q"><h1>Boutique</h1><div id="reco"><p>Bloc du thème</p></div><div id="reco2"></div><p>Suite de la page</p>'
    + '<script src="' + BASE + '/widget/deepfind.js?v=28" data-key="' + siteKey + '" data-lang="fr"></script></body></html>';
  const carCtx = async (opts) => {
    const cc = await browser.newContext(Object.assign({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', viewport: { width: 1280, height: 900 } }, opts || {}));
    await cc.route(/\/boutique\//, (r) => r.fulfill({ contentType: 'text/html', body: shopCar }));
    await cc.route(/www\.example\.com/, (r) => r.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Produit</title><h1>Fiche produit</h1>' }));
    const cp = await cc.newPage();
    cp.on('pageerror', (e) => errors.push('JS carrousel : ' + e.message));
    return [cc, cp];
  };
  const [cc1, cp1] = await carCtx();
  await cp1.goto(BASE + '/boutique/plongee.html');
  await cp1.waitForSelector('.df-carousel .df-card', { timeout: 5000 });
  const placed = await cp1.evaluate(() => { const r = document.getElementById('reco'); return r.nextElementSibling && r.nextElementSibling.classList.contains('df-carousel'); });
  const nCards = await cp1.locator('.df-carousel .df-card').count();
  check('carrousels : inséré après le bloc #reco, titre, lien, produits (épinglé en tête)', placed && nCards === 8
    && (await cp1.textContent('.df-car-title')) === 'Nos coups de cœur' && (await cp1.getAttribute('.df-car-more', 'href')) === '/boutique/promos.html'
    && (await cp1.getAttribute('.df-car-title', 'class')) === 'df-car-title df-car-title-own titre-theme md:text-3xl'
    && (await cp1.textContent('.df-carousel .df-card >> nth=0')).length > 0);
  await cp1.waitForTimeout(400);
  await cp1.screenshot({ path: OUT + '/carrousel-site.png' });
  const left0 = await cp1.evaluate(() => document.querySelector('.df-car-track').scrollLeft);
  const prevHidden = await cp1.evaluate(() => document.querySelector('.df-car-prev').disabled);
  await cp1.click('.df-car-next');
  await cp1.waitForTimeout(700);
  const left1 = await cp1.evaluate(() => document.querySelector('.df-car-track').scrollLeft);
  const tb = await cp1.locator('.df-car-track').boundingBox();
  // au bout du bandeau après « suivant » : glisser vers la droite ramène en arrière
  await cp1.mouse.move(tb.x + tb.width * 0.3, tb.y + tb.height / 2);
  await cp1.mouse.down();
  await cp1.mouse.move(tb.x + tb.width * 0.6, tb.y + tb.height / 2, { steps: 8 });
  await cp1.mouse.up();
  await cp1.waitForTimeout(500);
  const left2 = await cp1.evaluate(() => document.querySelector('.df-car-track').scrollLeft);
  check('carrousels : flèche suivante, glisser à la souris sans ouvrir de produit', prevHidden && left1 > left0 && left2 < left1 - 100 && cp1.url().includes('/boutique/plongee.html'));
  await Promise.all([cp1.waitForNavigation(), cp1.click('.df-carousel .df-card >> nth=0')]);
  check('carrousels : clic sur un produit → sa page', cp1.url().includes('www.example.com'));
  await cc1.close();
  const [cc2, cp2] = await carCtx(Object.assign({}, require('playwright').devices['iPhone 13']));
  await cp2.goto(BASE + '/boutique/plongee.html');
  await cp2.waitForSelector('.df-carousel .df-card', { timeout: 5000 });
  await cp2.waitForTimeout(400);
  const mob = await cp2.evaluate(() => {
    const t = document.querySelector('.df-car-track').getBoundingClientRect();
    const c0 = document.querySelector('.df-carousel .df-card').getBoundingClientRect();
    return { track: t.width, card: c0.width, btn: getComputedStyle(document.querySelector('.df-car-next')).display };
  });
  await cp2.screenshot({ path: OUT + '/carrousel-mobile.png' });
  check('carrousels : mobile (2 produits et un bout du suivant, sans flèches)', mob.btn === 'none' && mob.card * 2 < mob.track && mob.card * 2.5 > mob.track);
  await cc2.close();
  await page.waitForTimeout(500);
  await page.goto(BASE + '/admin/carousels');
  const caNums = await page.locator('.ca-table tbody tr:first-child td.num').evaluateAll((tds) => tds.map((td) => td.textContent.trim()));
  check('carrousels : statistiques (2 vues, 1 clic)', caNums[1] === '2' && caNums[2] === '1');
  await page.screenshot({ path: OUT + '/carrousel-liste.png', fullPage: true });
  // personnalisé « vus récemment » : rien sans consentement (sélection automatique), historique après consentement
  await page.goto(BASE + '/admin/carousels/edit');
  await page.fill('[data-f=name]', 'Vus récemment e2e');
  await page.fill('[data-f=selector]', '#reco2');
  await page.fill('[data-f=title]', 'Vus récemment');
  await page.check('input[name=ca_source][value=personal]');
  await page.check('input[name=ca_personal][value=recent]');
  await page.fill('[data-f=max_items]', '4');
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  const [cc3, cp3] = await carCtx();
  await cp3.goto(BASE + '/boutique/plongee.html');
  await cp3.waitForSelector('#reco2 .df-carousel .df-card', { timeout: 5000 });
  const noHist = await cp3.evaluate(() => Object.keys(localStorage).filter((k) => k.indexOf('df-hist:') === 0).length);
  const firstCar = await cp3.evaluate(() => Array.prototype.map.call(document.querySelectorAll('.df-carousel-' + document.querySelector('[data-df-carousel]').getAttribute('data-df-carousel') + ' .df-card'), (a) => a.href));
  await cp3.evaluate(() => window.DeepFind.consent(true));
  const picked = firstCar[3];
  await Promise.all([cp3.waitForNavigation(), cp3.click('.df-carousel >> nth=0 >> .df-card >> nth=3')]);
  await cp3.goto(BASE + '/boutique/plongee.html');
  await cp3.waitForSelector('#reco2 .df-carousel .df-card', { timeout: 5000 });
  const recent = await cp3.evaluate(() => document.querySelector('#reco2 .df-carousel .df-card').href);
  const hist = await cp3.evaluate(() => Object.keys(localStorage).filter((k) => k.indexOf('df-hist:') === 0).map((k) => JSON.parse(localStorage.getItem(k)).length));
  await cp3.evaluate(() => window.DeepFind.consent(false));
  const cleared = await cp3.evaluate(() => Object.keys(localStorage).filter((k) => k.indexOf('df-hist:') === 0 || k === 'df-consent').length);
  check('carrousels : personnalisé (sans consentement : aucun historique ; avec : produit cliqué en tête ; retrait : historique effacé)',
    noHist === 0 && recent === picked && hist[0] === 1 && cleared === 0);
  await cp3.screenshot({ path: OUT + '/carrousel-perso.png', fullPage: true });
  await cc3.close();
  for (let k = 0; k < 2; k++) {
    await page.goto(BASE + '/admin/carousels');
    await page.click('form[action$="/admin/carousels/delete"] button, .cr-delete-btn');
    await Promise.all([page.waitForNavigation(), confirmModal()]);
  }
  check('carrousels : suppression', (await page.textContent('body')).includes('Carrousel supprimé') && await page.locator('.ca-table tbody tr[data-row]').count() === 0);

  // Parcours d'achat et Parcours (Analyse) : activés dans Audience › Réglages, puis un visiteur sur un faux site
  await page.goto(BASE + '/admin/audience');
  await page.check('#aud-settings input[name=journeys]', { force: true });
  await Promise.all([page.waitForNavigation(), page.click('#aud-settings > button.btn')]);
  check('parcours : activés depuis Audience › Réglages', await page.isChecked('#aud-settings input[name=journeys]'));
  const [jc, jp] = await carCtx();
  await jp.goto(BASE + '/boutique/plongee.html');
  await jp.waitForFunction(() => window.DeepFind && window.DeepFind.isActive());
  await jp.click('input[name=q]');
  await jp.keyboard.type('masque', { delay: 40 });
  await jp.waitForSelector('.df-card', { timeout: 5000 });
  await jp.waitForTimeout(500);
  await jp.keyboard.press('Escape'); // fermée sans clic
  await jp.evaluate(() => {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: 'add_to_cart', ecommerce: { items: [{ item_id: 'SEAC-X-VISION', item_name: 'Masque X-Vision', quantity: 1, price: 59.9 }] } });
    window.DeepFind.track('cart', { sku: 'SEAC-X-VISION', qty: 1, price: 59.9 }); // même ajout signalé deux fois : un seul compté
    window.DeepFind.track('order', { id: 'E2E-1', total: 59.9, items: [{ sku: 'SEAC-X-VISION', qty: 1, price: 59.9 }] });
  });
  await jp.waitForTimeout(1200);
  await jc.close();
  await page.goto(BASE + '/admin/journeys');
  const jrRows = await page.locator('.jr-table tbody tr').filter({ hasText: 'masque' }).count();
  await page.locator('.jr-table tbody tr').filter({ hasText: 'masque' }).first().locator('a').first().click();
  await page.waitForSelector('.jr-timeline');
  const tl = await page.textContent('.jr-timeline');
  check('parcours : chronologie (page, barre ouverte, recherche, fermeture sans clic, panier dédoublonné, commande)', jrRows >= 1
    && tl.includes('Boutique') && tl.includes('Barre de recherche ouverte') && tl.includes('Recherche « masque »') && tl.includes('fermée sans clic')
    && (tl.match(/Ajout au panier :/g) || []).length === 1 && tl.includes('Commande : 59,90') && (await page.title()).includes('Visiteur'), tl.replace(/\s+/g, ' ').slice(0, 400));
  await page.screenshot({ path: OUT + '/parcours.png', fullPage: true });
  await page.goto(BASE + '/admin/audience');
  check('parcours d\'achat : section de la page Audience', (await page.textContent('#parcours-achat + .flash, .aud-kpis')).length > 0
    && (await page.textContent('body')).includes('Recherches qui rapportent') && (await page.textContent('body')).includes('59,90'));
  await page.uncheck('#aud-settings input[name=journeys]', { force: true });
  await Promise.all([page.waitForNavigation(), page.click('#aud-settings > button.btn')]);

  // Campagne de produits à pousser : création depuis l'écran (recherche d'un produit + liste de SKU collée), tableau de bord
  await page.goto(BASE + '/admin/campaigns/edit');
  await page.fill('input[name=name]', 'Campagne e2e');
  await page.fill('[data-item-search]', 'masque');
  await page.locator('.ca-result >> nth=0').click();
  await page.keyboard.press('Escape');
  await page.click('.cp-bulk summary');
  await page.fill('[data-bulk]', 'SEAC-X-VISION\nINCONNU-E2E');
  await page.click('[data-bulk-add]');
  await Promise.all([page.waitForNavigation(), page.click('.cp-form .cr-foot button.btn:not(.btn-danger)')]);
  const cpBody = await page.textContent('body');
  check('campagnes : création, tableau de bord par produit et par emplacement, SKU introuvable signalé',
    cpBody.includes('Campagne « Campagne e2e » enregistrée') && await page.locator('.cp-table tbody tr').count() >= 2
    && cpBody.includes('introuvable dans le catalogue') && cpBody.includes('Où les ventes ont été faites'));
  await page.screenshot({ path: OUT + '/campagne.png', fullPage: true });
  await page.goto(BASE + '/admin/campaigns');
  check('campagnes : liste', (await page.textContent('.cp-table')).includes('Campagne e2e'));
  // Campagne par règles seules : même éditeur de conditions que les boosts (Marque est …)
  await page.goto(BASE + '/admin/campaigns/edit');
  await page.fill('input[name=name]', 'Règles e2e');
  await page.click('[data-add-cond]');
  await page.selectOption('.cp-rules .br-cond select.br-field', 'brand');
  await page.click('.cp-rules .br-cond .ms-toggle');
  await page.locator('.cp-rules .ms-list .ms-item', { hasText: 'C4' }).first().click();
  await page.click('input[name=name]');
  await page.locator('.cp-rules').screenshot({ path: OUT + '/campagne-regles.png' });
  await Promise.all([page.waitForNavigation(), page.click('.cp-form .cr-foot button.btn:not(.btn-danger)')]);
  const cpRBody = await page.textContent('body');
  check('campagnes : produits par règles (enregistrée, produits correspondants comptés, badge « règle »)', cpRBody.includes('Campagne « Règles e2e » enregistrée')
    && /Règles : \d+ produit\(s\)/.test(cpRBody) && await page.locator('.cp-table .badge:has-text("règle")').count() > 0, cpRBody.slice(0, 400));
  // Résultats seuls ; « Modifier la campagne » ouvre le formulaire seul (règles gardées), sans tableau de bord
  const cpResultsNoForm = await page.locator('[data-campaign-form]').count() === 0;
  await Promise.all([page.waitForNavigation(), page.click('a:has-text("Modifier la campagne")')]);
  check('campagnes : résultats et formulaire sur deux pages (Modifier → formulaire seul, règles gardées)', cpResultsNoForm
    && await page.locator('.cp-table').count() === 0 && await page.inputValue('input[name=name]') === 'Règles e2e'
    && (await page.locator('.cp-rules .br-cond').count()) === 1 && (await page.textContent('.cp-rules')).includes('produit(s) correspondent'));
  await page.goto(BASE + '/admin/campaigns');
  await Promise.all([page.waitForNavigation(), page.locator('.cp-table tr', { hasText: 'Règles e2e' }).locator('a:has-text("Modifier")').click()]);
  check('campagnes : bouton « Modifier » de la liste', await page.inputValue('input[name=name]') === 'Règles e2e');
  await page.click('.cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);

  // Carrousel « page panier » : panier lu dans les données Magento du navigateur, message de livraison offerte
  await page.goto(BASE + '/admin/settings');
  const csrfS = await page.getAttribute('input[name=_csrf]', 'value');
  await page.request.post(BASE + '/admin/settings/save', { form: { _csrf: csrfS, free_shipping_threshold: '200' } });
  await page.goto(BASE + '/admin/carousels/edit');
  const csrfC = await page.getAttribute('[data-carousel-form]', 'data-csrf');
  const payloadC = { name: 'Panier e2e', active: true, duration: 'always', pages: 'all', selector: '#reco', insert_mode: 'after', title: 'Complétez votre panier',
    source: 'cart', items: [], match: 'all', conditions: [], sort_by: 'biz', max_items: 6, per_view_desktop: 4, per_view_mobile: 2, in_stock_only: false,
    segments: { channels: [], utm: [] } };
  const savedC = await page.request.post(BASE + '/admin/carousels/save', { form: { _csrf: csrfC, payload: JSON.stringify(payloadC) } });
  const cartCarId = new URL(savedC.url()).searchParams.get('id');
  const [kc, kp] = await carCtx();
  await kp.addInitScript(() => { try { localStorage.setItem('mage-cache-storage', JSON.stringify({ cart: { items: [{ product_sku: 'C4-PREDATOR', qty: 1 }], subtotalAmount: '120.00' } })); } catch (e) { /* */ } });
  await kp.goto(BASE + '/boutique/plongee.html');
  await kp.waitForSelector('.df-car-ship', { timeout: 8000 });
  const shipTxt = await kp.textContent('.df-car-ship');
  check('page panier : panier Magento lu, « plus que 80 € pour la livraison offerte », produits du panier exclus', shipTxt.includes('Plus que') && shipTxt.includes('80,00')
    && !(await kp.evaluate(() => Array.prototype.some.call(document.querySelectorAll('.df-carousel .df-card'), (a) => /c4-predator/i.test(a.href)))), shipTxt);
  await kp.locator('.df-carousel').screenshot({ path: OUT + '/carrousel-panier.png' });
  await kc.close();
  await page.request.post(BASE + '/admin/carousels/delete', { form: { _csrf: csrfC, id: cartCarId } });
  await page.request.post(BASE + '/admin/settings/save', { form: { _csrf: csrfS, free_shipping_threshold: '0' } });

  // Notifications : réglages (forme, appareils), invitation sur le site, abonnement, puis message programmé
  await page.goto(BASE + '/admin/push/settings');
  await page.check('input[name=push_enabled]', { force: true });
  await page.check('input[name=push_form][value=banner_bottom]');
  await page.selectOption('select[name=push_devices]', 'all');
  await page.fill('input[name=push_delay]', '0');
  await Promise.all([page.waitForNavigation(), page.click('.ps-form .cr-foot button.btn')]);
  check('notifications : réglages enregistrés', (await page.textContent('body')).includes('Réglages des notifications enregistrés'));
  const crypto = require('crypto');
  const ecdh = crypto.createECDH('prime256v1');
  ecdh.generateKeys();
  const b64u = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const fakeSub = { endpoint: 'http://127.0.0.1:7796/e2e/' + Date.now(), p256dh: b64u(ecdh.getPublicKey()), auth: b64u(crypto.randomBytes(16)) };
  const [nc, np] = await carCtx();
  await nc.grantPermissions(['notifications'], { origin: BASE });
  await nc.route(/\/sw\.js$/, (r) => r.fulfill({ contentType: 'application/javascript', body: "importScripts('" + BASE + "/widget/push-sw.js');" }));
  // le service d'envoi réel du navigateur (Google…) n'est pas joignable ici, et Chromium sans affichage refuse toujours les notifications :
  // accord du navigateur et abonnement simulés, avec les clés du test
  await np.addInitScript((sub) => {
    if (!window.PushManager || !window.Notification) { return; }
    let perm = 'default';
    Object.defineProperty(Notification, 'permission', { configurable: true, get: function () { return perm; } });
    Notification.requestPermission = function () { perm = 'granted'; return Promise.resolve(perm); };
    let current = null;
    PushManager.prototype.getSubscription = function () { return Promise.resolve(current); };
    PushManager.prototype.subscribe = function () {
      current = { endpoint: sub.endpoint, toJSON: function () { return { endpoint: sub.endpoint, keys: { p256dh: sub.p256dh, auth: sub.auth } }; },
        unsubscribe: function () { current = null; return Promise.resolve(true); } };
      return Promise.resolve(current);
    };
  }, fakeSub);
  await np.goto(BASE + '/boutique/plongee.html');
  await np.waitForSelector('.df-push', { timeout: 8000 });
  const invite = await np.textContent('.df-push');
  const hasClose = await np.locator('.df-push .df-push-x').count() === 1;
  await np.waitForTimeout(400); // fondu d'apparition
  await np.screenshot({ path: OUT + '/notifications-invitation.png' });
  await np.click('.df-push-yes');
  await np.waitForSelector('.df-push-thanks', { timeout: 8000 });
  await nc.close();
  await page.goto(BASE + '/admin/push/settings');
  check('notifications : invitation affichée (avec croix de fermeture), accord du navigateur, abonnement enregistré', hasClose && invite.includes('Ne ratez aucune bonne affaire')
    && /Abonnés\s*1(?!\d)/.test(await page.textContent('.cards')), invite);
  await page.goto(BASE + '/admin/push/edit');
  await page.fill('input[name=title]', 'Test e2e');
  await page.fill('textarea[name=body]', 'Message de test');
  check('notifications : aperçu du message et nombre d\'abonnés visés', (await page.textContent('[data-pe-out=title]')) === 'Test e2e'
    && (await page.textContent('[data-pe-count]')).startsWith('1 abonné'));
  await page.check('input[name=when][value=now]');
  await page.click('[data-pe-submit]');
  await confirmModal().catch(() => {});
  await page.waitForURL(/\/admin\/push$/, { timeout: 8000 });
  check('notifications : message programmé (envoi par la tâche planifiée)', (await page.textContent('body')).includes('envoi dans la minute à 1 abonné')
    && (await page.textContent('.table')).includes('Programmé'));
  await page.screenshot({ path: OUT + '/notifications-messages.png', fullPage: true });
  // Abonnés : liste masquée, filtre, pause puis réactivation
  await page.goto(BASE + '/admin/push/subscribers?device=desktop');
  const psbRow = await page.textContent('.psb-table tbody tr');
  check('notifications : liste des abonnés (appareil, adresse et clé masquées, statut)', psbRow.includes('Ordinateur') && psbRow.includes('****')
    && psbRow.includes('Actif') && !psbRow.includes(fakeSub.p256dh), psbRow.replace(/\s+/g, ' '));
  await Promise.all([page.waitForNavigation(), page.click('.psb-table tbody tr button:has-text("Mettre en pause")')]);
  const paused = (await page.textContent('.psb-table tbody tr')).includes('En pause');
  await Promise.all([page.waitForNavigation(), page.click('.psb-table tbody tr button:has-text("Réactiver")')]);
  check('notifications : abonné mis en pause puis réactivé', paused && (await page.textContent('.psb-table tbody tr')).includes('Actif') && page.url().includes('device=desktop'));
  // Groupe de test : appareil nommé, filtre, puis essai depuis la fiche d'un message (contenu gardé, message non enregistré)
  await page.fill('.psb-table tbody tr .psb-tester input[name=label]', 'Ordi e2e');
  await Promise.all([page.waitForNavigation(), page.click('.psb-table tbody tr button:has-text("Ajouter au groupe de test")')]);
  await page.goto(BASE + '/admin/push/subscribers?group=test');
  const grpRow = await page.textContent('.psb-table tbody tr');
  await page.goto(BASE + '/admin/push/subscribers');
  await page.screenshot({ path: OUT + '/notifications-abonnes.png', fullPage: true });
  await page.goto(BASE + '/admin/push/edit');
  await page.fill('input[name=title]', 'Essai groupe e2e');
  await page.fill('textarea[name=body]', 'Texte essai');
  await Promise.all([page.waitForNavigation(), page.click('[data-pe-test]')]);
  const grpFlash = await page.textContent('.flash');
  const grpKept = await page.inputValue('input[name=title]');
  await page.locator('.pe-test').screenshot({ path: OUT + '/notifications-groupe-test.png' });
  await page.goto(BASE + '/admin/push');
  check('notifications : groupe de test (appareil nommé, filtre, essai depuis un message sans l\'enregistrer)', grpRow.includes('Test') && grpRow.includes('Ordi e2e')
    && grpFlash.includes('Groupe de test') && grpKept === 'Essai groupe e2e' && !(await page.textContent('.table')).includes('Essai groupe e2e'),
    JSON.stringify([grpRow.replace(/\s+/g, ' '), grpFlash]));
  await page.goto(BASE + '/admin/push/subscribers?group=test');
  await Promise.all([page.waitForNavigation(), page.click('.psb-table tbody tr button:has-text("Retirer du groupe de test")')]);
  await page.goto(BASE + '/admin/push/settings');
  await page.uncheck('input[name=push_enabled]', { force: true });
  await Promise.all([page.waitForNavigation(), page.click('.ps-form .cr-foot button.btn')]);

  // Adapter les produits selon la page : adresse → conditions en plus, vérifiées dans la liste de contrôle, gardées à l'enregistrement
  await page.goto(BASE + '/admin/carousels/edit');
  await page.fill('[data-f=name]', 'Selon la page e2e');
  await page.fill('[data-f=selector]', '#reco');
  await page.click('[data-add-pf]');
  await page.fill('.ca-pf .pr-url-text', '/palmes');
  const pfInput = page.locator('.ca-pf .br-cond input:visible').first();
  await pfInput.fill('Palmes réglables');
  await pfInput.press('Enter');
  await page.fill('[data-preview-page]', '/fr/palmes/');
  await page.click('[data-preview-run]');
  await page.waitForFunction(() => /produit/.test(document.querySelector('[data-preview-note]').textContent), null, { timeout: 5000 });
  const pfNote = await page.textContent('[data-preview-note]');
  const pfCount = await page.locator('.ca-pcard').count();
  await page.locator('.ca-pf').screenshot({ path: OUT + '/carrousel-selon-la-page.png' });
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open)')]);
  await page.goto(BASE + '/admin/carousels');
  await page.click('.ca-table tbody tr[data-row] a[href*="carousels/edit?id="]');
  await page.waitForSelector('.ca-pf');
  check('carrousels : adapter selon la page (appliquée dans la liste de contrôle, gardée à l\'enregistrement)',
    pfNote.includes('Adaptation selon la page appliquée') && pfCount > 0 && await page.inputValue('.ca-pf .pr-url-text') === '/palmes'
    && (await page.textContent('.ca-pf .br-cond')).includes('Palmes réglables'), pfNote);
  await page.click('.cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);

  // Intégration › layer › Modèle de carte produit : lien de la carte rappelé autour du code, variable {{url}} listée
  // (layer de test créé puis supprimé : le reste du test garde l'affichage par défaut)
  await page.goto(BASE + '/admin/integration');
  const lyCsrf = await page.getAttribute('input[name=_csrf]', 'value');
  const lyResp = await page.request.post(BASE + '/admin/integration/layer/create', { form: { _csrf: lyCsrf, name: 'Layer e2e modèle', type: 'below' } });
  await page.goto(lyResp.url());
  const lyId = new URL(page.url()).searchParams.get('id');
  const tplTxt = await page.textContent('#lp-card-tpl');
  check('intégration : modèle de carte avec le lien {{url}} affiché et la variable listée', !!lyId && tplTxt.includes('<a class="df-card" href="') && tplTxt.includes('</a>')
    && await page.inputValue('#lp-card-tpl input[name=card_link]') === '{{url}}'
    && (await page.locator('#lp-card-tpl .lp-vars code', { hasText: '{{url}}' }).count()) === 1);
  await page.evaluate(() => document.getElementById('lp-card-tpl').showModal());
  await page.waitForTimeout(200);
  await page.screenshot({ path: OUT + '/modele-carte-url.png' });
  // lien de la carte : refusé (javascript:) puis accepté (paramètres), réaffiché après enregistrement
  await page.fill('#lp-card-tpl input[name=card_link]', 'javascript:alert(1)');
  await Promise.all([page.waitForNavigation(), page.click('#lp-card-tpl .modal-foot .btn:not(.btn-light)')]);
  const linkRefused = (await page.textContent('body')).includes('Lien de la carte refusé') && await page.inputValue('#lp-card-tpl input[name=card_link]') === '{{url}}';
  await page.evaluate(() => document.getElementById('lp-card-tpl').showModal());
  await page.fill('#lp-card-tpl input[name=card_link]', '{{url}}?utm_source=deepfind');
  await Promise.all([page.waitForNavigation(), page.click('#lp-card-tpl .modal-foot .btn:not(.btn-light)')]);
  check('intégration : lien de la carte modifiable (refusé si dangereux, enregistré sinon)', linkRefused
    && await page.inputValue('#lp-card-tpl input[name=card_link]') === '{{url}}?utm_source=deepfind');
  await page.request.post(BASE + '/admin/integration/layer/delete', { form: { _csrf: lyCsrf, id: lyId } });

  // Supervision : indicateurs et graphiques, données du site (requêtes faites par le test), changement de période
  await page.goto(BASE + '/admin/monitor');
  await page.waitForSelector('.mon-kpi');
  await page.waitForSelector('[data-chart=activity] svg path');
  const monReq = await page.evaluate(() => {
    const t = Array.prototype.find.call(document.querySelectorAll('.mon-kpi'), (k) => k.querySelector('.mon-kpi-label').textContent === 'Requêtes API');
    return t ? Number(t.querySelector('.mon-kpi-value').textContent.replace(/\s/g, '')) : -1;
  });
  const monKpis = await page.locator('.mon-kpi').count();
  const monCharts = await page.locator('.mon-chart svg').count();
  const monHeat = await page.locator('.mon-heat-c').count();
  await page.hover('[data-chart=activity] svg rect >> nth=-1', { position: { x: 300, y: 80 } });
  const monTip = await page.isVisible('.mon-tip');
  await page.screenshot({ path: OUT + '/supervision.png', fullPage: true });
  await page.click('[data-range="24h"]');
  await page.waitForFunction(() => document.querySelector('[data-step]').textContent.includes('15 min'));
  check('supervision : 9 indicateurs, 5 graphiques, carte 5 jours × 24 h, requêtes du site comptées, infobulle, période 24 h',
    monKpis === 9 && monCharts === 5 && monHeat === 120 && monReq > 0 && monTip);
  await page.click('[data-range="1h"]');

  // Popin en fréquence « toujours » : réaffichée après fermeture et rechargement, rien n'est mémorisé
  await page.goto(BASE + '/admin/popins/edit');
  await page.fill('[data-f=name]', 'Popin toujours');
  await page.selectOption('[data-f=mode]', 'code');
  await page.fill('[data-f=delay_seconds]', '0');
  await page.click('[data-wy-tab=code]');
  await page.fill('[data-f=html]', '<h2>Toujours</h2>');
  await page.check('input[name=pp_repeat][value=always]');
  const rememberHidden = await page.isHidden('.pp-remember');
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lp-open):not(.lv-open):not(.cr-delete-btn)')]);
  const savedAlways = (await page.textContent('body')).includes('Popin « Popin toujours » enregistrée') && await page.isChecked('input[name=pp_repeat][value=always]');
  const [pc4, pp4] = await popinCtx();
  await pp4.goto(BASE + '/boutique/plongee.html');
  await pp4.waitForSelector('.df-popin-html h2', { timeout: 5000 });
  await pp4.click('.df-popin-close');
  await pp4.reload();
  await pp4.waitForSelector('.df-popin-html h2', { timeout: 5000 }).catch(() => {});
  const again = await pp4.locator('.df-popin').count();
  const memo = await pp4.evaluate(() => Object.keys(localStorage).concat(Object.keys(sessionStorage)).filter((k) => k.indexOf('df-popin:') === 0).length);
  await pc4.close();
  check('popins : fréquence « toujours » (durée masquée, réaffichée après fermeture, rien de mémorisé)', rememberHidden && savedAlways && again === 1 && memo === 0);
  await page.click('.cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);

  // Paramètres › Variables : texte modifié (bouton des filtres, « dès »), variable personnalisée dans une popin, sur un faux site
  await page.goto(BASE + '/admin/variables');
  await page.fill('input[name="texts[filters]"]', 'Affiner');
  await page.fill('input[name="texts[from]"]', 'À partir de :');
  await page.click('[data-var-add]');
  await page.fill('[data-var-rows] tr:last-child input[name$="[code]"]', 'Livraison Été');
  const codeTyped = await page.inputValue('[data-var-rows] tr:last-child input[name$="[code]"]');
  await page.fill('[data-var-rows] tr:last-child input[name$="[value]"]', 'Livraison offerte dès 50 €');
  await Promise.all([page.waitForNavigation(), page.click('.var-form .cr-foot button.btn')]);
  const varsSaved = (await page.textContent('body')).includes('Variables enregistrées') && await page.inputValue('input[name="texts[filters]"]') === 'Affiner'
    && await page.locator('.var-text.is-changed').count() === 2;
  await page.screenshot({ path: OUT + '/variables.png', fullPage: true });
  await page.goto(BASE + '/admin/popins/edit');
  await page.fill('[data-f=name]', 'Popin variable');
  await page.selectOption('[data-f=mode]', 'code');
  await page.fill('[data-f=delay_seconds]', '0');
  await page.click('[data-wy-tab=code]');
  await page.fill('[data-f=html]', '<p class="var-test">{{livraison_ete}} – {{inconnue}}</p>');
  await Promise.all([page.waitForNavigation(), page.click('.cr-foot button.btn:not(.lv-open):not(.cr-delete-btn)')]);
  const [pc5, pp5] = await popinCtx();
  // un produit à prix variable (le catalogue de test n'en a pas) : « dès » / texte modifié affiché
  await pc5.route('**/api/v1/search?*', async (route) => {
    const resp = await route.fetch();
    const json = await resp.json();
    if (json.products && json.products[0]) { json.products[0].has_price_range = true; }
    await route.fulfill({ response: resp, json });
  });
  await pp5.goto(BASE + '/boutique/plongee.html');
  await pp5.waitForSelector('.df-popin .var-test', { timeout: 5000 });
  const popinVar = await pp5.textContent('.df-popin .var-test');
  await pp5.click('.df-popin-close');
  await pp5.fill('input[name=q]', 'palme');
  await pp5.waitForSelector('.df-card', { timeout: 5000 });
  const filtersTxt = await pp5.textContent('.df-filters-btn');
  const fromTxt = await pp5.evaluate(() => Array.prototype.some.call(document.querySelectorAll('.df-from'), (n) => n.textContent === 'À partir de :'));
  await pc5.close();
  check('variables : enregistrées (code nettoyé), texte modifié et variable personnalisée affichés sur le site',
    codeTyped === 'livraison_ete' && varsSaved && popinVar === 'Livraison offerte dès 50 € – {{inconnue}}' && filtersTxt === 'Affiner' && fromTxt);
  await page.click('.cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  await page.goto(BASE + '/admin/variables');
  await page.fill('input[name="texts[filters]"]', '');
  await page.fill('input[name="texts[from]"]', '');
  await page.click('[data-var-rows] tr:last-child [data-var-remove]');
  await Promise.all([page.waitForNavigation(), page.click('.var-form .cr-foot button.btn')]);

  // Flux protégé par mot de passe (.htaccess) : test de connexion, enregistrement, mot de passe jamais réaffiché
  const feedSrv = require('child_process').spawn('php', ['-S', '127.0.0.1:7797', 'tests/fake-feed.php'], { stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 500));
  await page.goto(BASE + '/admin/sources/edit?kind=products');
  await page.fill('input[name=code]', 'flux_protege');
  await page.fill('input[name=url]', 'http://127.0.0.1:7797/protected/feed.xml');
  await page.click('[data-src-check]');
  await page.waitForSelector('.src-result');
  check('flux : test sans identifiants → protégé (401)', (await page.textContent('.src-check-results')).includes('protégé par mot de passe'));
  await page.fill('input[name=auth_user]', 'flux');
  await page.fill('input[name=auth_password]', 's3cret');
  await page.click('[data-src-check]');
  await page.waitForFunction(() => document.querySelector('.src-result.is-ok'));
  check('flux : test avec identifiants → connexion réussie (HTTP 200)', (await page.textContent('.src-check-results')).includes('HTTP 200'));
  await Promise.all([page.waitForNavigation(), page.click('.actions button.btn:not(.btn-light)')]);
  const srcHtml = await page.content();
  check('flux : source enregistrée, mot de passe jamais réaffiché', srcHtml.includes('Source enregistrée') && !srcHtml.includes('s3cret')
    && (await page.getAttribute('input[name=auth_password]', 'placeholder')).includes('enregistré'));
  check('fiches : « Supprimer la source » sur la ligne des boutons, même hauteur', await page.evaluate(() => {
    const del = document.querySelector('.cr-delete-btn');
    const save = document.querySelector('.actions .btn:not(.btn-light)');
    if (!del || !save) { return false; }
    const a = del.getBoundingClientRect();
    const b = save.getBoundingClientRect();
    return Math.abs(a.top - b.top) < 2 && Math.abs(a.height - b.height) < 2;
  }));
  await page.click('[data-src-check]');
  await page.waitForSelector('.src-result');
  check('flux : nouveau test avec le mot de passe enregistré', (await page.textContent('.src-check-results')).includes('protection par mot de passe acceptée'));
  await page.click('form[action$="/admin/sources/delete"] button, .cr-delete-btn');
  await Promise.all([page.waitForNavigation(), confirmModal()]);
  feedSrv.kill();

  // Photo au survol d'un produit : activée par défaut, désactivable dans Paramètres › Widget sur le site
  const hoverTest = async () => {
    const hc = await browser.newContext({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
    await hc.route(/\/boutique\//, (r) => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><title>Boutique</title></head><body>'
      + '<input name="q"><script src="' + BASE + '/widget/deepfind.js?v=24" data-key="' + siteKey + '" data-lang="fr"></script></body></html>' }));
    // deuxième photo injectée dans la réponse (les produits de test n'en ont pas)
    await hc.route('**/api/v1/search?*', async (route) => {
      const resp = await route.fetch();
      const json = await resp.json();
      (json.products || []).forEach((p) => { p.image_hover = BASE + '/assets/demo/blog.svg'; });
      await route.fulfill({ response: resp, json });
    });
    const hp = await hc.newPage();
    await hp.goto(BASE + '/boutique/plongee.html');
    await hp.waitForFunction(() => window.DeepFind && window.DeepFind.isActive());
    await hp.fill('input[name=q]', 'palme');
    await hp.waitForSelector('.df-card img.df-img');
    const before = await hp.getAttribute('.df-card >> nth=0 >> img.df-img', 'src');
    await hp.hover('.df-card >> nth=0');
    await hp.waitForTimeout(200);
    const during = await hp.evaluate(() => document.querySelector('.df-card img.df-img').src);
    await hc.close();
    return [before, during];
  };
  const [hb1, hd1] = await hoverTest();
  check('widget : deuxième photo au survol (réglage activé)', hd1.endsWith('/assets/demo/blog.svg') && !String(hb1).endsWith('blog.svg'));
  await page.goto(BASE + '/admin/settings');
  await page.uncheck('input[type=checkbox][name=widget_hover_image]', { force: true });
  await Promise.all([page.waitForNavigation(), page.click('.st-foot button')]);
  const [, hd0] = await hoverTest();
  check('widget : photo fixe au survol (réglage désactivé)', !hd0.endsWith('/assets/demo/blog.svg'));
  await page.check('input[type=checkbox][name=widget_hover_image]', { force: true });
  await Promise.all([page.waitForNavigation(), page.click('.st-foot button')]);

  check('aucune erreur JavaScript', errors.length === 0);
  if (errors.length) { console.log(errors.join('\n')); }
  await browser.close();
  console.log(`\n${ok} réussi(s), ${fail.length} échec(s)`);
  process.exit(fail.length ? 1 : 0);
})();
