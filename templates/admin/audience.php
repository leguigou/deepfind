<?php
/** @var int $days @var string $from @var string $to @var bool $custom @var array $periodQuery @var array $data @var bool $enabled @var array $tracks @var string $pages @var string $paths @var bool $pathsHome
 *  @var string $today @var int $dayHour @var ?array $dayHourNext @var bool $datalayer @var bool $journeys @var array $terms @var array $notCarted @var bool $hasCommerce */
use DeepFind\Admin\AudienceController;
use DeepFind\Logging\Audience;

$t = $data['total'];
$activeDays = count(array_filter($data['days'], static fn ($d) => $d['visitors'] > 0));
$pct = static fn (int $a, int $b) => $b > 0 ? fmt_n(100 * $a / $b, 1) . ' %' : '—';
$ratio = static fn (int $a, int $b) => $b > 0 ? fmt_n($a / $b, 1) : '—';
// Courbe : du premier jour mesuré à la veille (la journée en cours, incomplète, ferait croire à une chute)
$series = [];
foreach ($data['days'] as $day => $m) {
    if ($day === $today || ($series === [] && $m['visitors'] === 0)) {
        continue;
    }
    $series[] = ['d' => $day, 'v' => $m['visitors'], 's' => $m['searchers']];
}
$toggle = static fn (string $name, bool $on, string $label, string $help = '') => '<label class="toggle"><input type="checkbox" role="switch" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '>'
    . '<span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">' . e($label)
    . ($help !== '' ? '<small class="muted">' . e($help) . '</small>' : '') . '</span></label>';
?>
<div class="aud-head">
  <div class="inline-form aud-periods">
    Période :
    <?php foreach (AudienceController::PERIODS as $d => $l): ?>
      <a class="btn btn-sm <?= !$custom && $days === $d ? '' : 'btn-light' ?>" href="<?= e(url('/admin/audience', ['days' => $d])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <button type="button" class="btn btn-sm <?= $custom ? '' : 'btn-light' ?>" data-custom-toggle aria-expanded="<?= $custom ? 'true' : 'false' ?>" aria-controls="aud-custom">Personnalisé</button>
  </div>
  <a class="btn btn-sm btn-light" href="<?= e(url('/admin/audience/export', $periodQuery)) ?>">Exporter CSV</a>
</div>
<form method="get" action="<?= e(url('/admin/audience')) ?>" class="card form aud-custom" id="aud-custom"<?= $custom ? '' : ' hidden' ?>>
  <label>Du <input type="date" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>" required></label>
  <label>Au <input type="date" name="to" value="<?= e($to) ?>" max="<?= e($today) ?>" required></label>
  <button class="btn btn-sm">Appliquer</button>
  <span class="muted small">2 ans au plus.</span>
</form>
<p class="muted small aud-range">Du <?= e(date('d/m/Y', strtotime($from))) ?> au <?= e(date('d/m/Y', strtotime($to))) ?><?= $to === $today ? ' (journée en cours incluse)' : '' ?>.
  Une journée de mesure va de <?= (int) $dayHour ?> h à <?= (int) $dayHour ?> h le lendemain (heure de Paris) et porte la date de son début.</p>

<?php if (!$enabled): ?>
  <div class="flash flash-warn">La mesure d'audience est désactivée : aucune nouvelle donnée n'est collectée (réglages en bas de page).</div>
<?php elseif ($pages !== 'all' && $tracks['pageviews']): ?>
  <div class="flash flash-info">Pages vues mesurées sur <?= $pages === 'home' ? 'la page d\'accueil uniquement' : 'les pages choisies uniquement' ?> :
    les visiteurs qui ne passent pas par ces pages ne sont comptés que s'ils cherchent. La part de visiteurs qui cherchent est donc surestimée.</div>
<?php endif; ?>

<div class="cards aud-kpis">
  <div class="card kpi"><div class="kpi-label">Visiteurs par jour</div><div class="kpi-value"><?= $activeDays > 0 ? fmt_n($t['visitors'] / $activeDays) : '—' ?></div>
    <div class="muted small"><?= $activeDays > 0 ? 'moyenne sur ' . fmt_n($activeDays) . ' jour' . ($activeDays > 1 ? 's' : '') . ' mesuré' . ($activeDays > 1 ? 's' : '') : 'aucune donnée sur la période' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Visiteurs qui cherchent</div><div class="kpi-value"><?= $pct($t['searchers'], $t['visitors']) ?></div>
    <div class="muted small">les autres naviguent sans chercher</div></div>
  <div class="card kpi"><div class="kpi-label">Recherches par visiteur</div><div class="kpi-value"><?= $ratio($t['searches'], $t['searchers']) ?></div>
    <div class="muted small">parmi ceux qui cherchent</div></div>
  <div class="card kpi"><div class="kpi-label">Cliquent sur un résultat</div><div class="kpi-value"><?= $pct($t['clickers'], $t['searchers']) ?></div>
    <div class="muted small">des visiteurs qui cherchent</div></div>
  <div class="card kpi"><div class="kpi-label">Sur mobile</div><div class="kpi-value"><?= $pct($data['devices']['mobile']['visitors'], $t['visitors']) ?></div>
    <div class="muted small">tablette : <?= $pct($data['devices']['tablet']['visitors'], $t['visitors']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Pages vues par visiteur</div><div class="kpi-value"><?= $ratio($t['pageviews'], $t['visitors']) ?></div>
    <div class="muted small"><?= $tracks['pageviews'] ? 'pages mesurées seulement' : 'pages vues non mesurées' ?></div></div>
</div>

<div class="card aud-chart-card">
  <h3>Évolution quotidienne</h3>
  <?php if ($series === []): ?>
    <p class="muted">Pas encore de journée complète mesurée sur cette période (la journée en cours apparaît dans le tableau ci-dessous). Les premières statistiques apparaissent dès les premières visites du site (widget DeepFind en <code>?v=<?= (int) \DeepFind\Widget\WidgetConfig::WIDGET_VERSION ?></code>).</p>
  <?php else: ?>
    <ul class="aud-legend" aria-hidden="true">
      <li><span class="aud-swatch aud-s1"></span>Visiteurs</li>
      <li><span class="aud-swatch aud-s2"></span>Visiteurs qui ont cherché</li>
    </ul>
    <div class="aud-chart" data-audience-chart="<?= e(\DeepFind\Text::json($series)) ?>" role="img"
      aria-label="Visiteurs et visiteurs qui ont cherché, par jour, du <?= e(date('d/m/Y', strtotime($series[0]['d']))) ?> au <?= e(date('d/m/Y', strtotime(end($series)['d']))) ?> (détail dans le tableau ci-dessous)"></div>
    <p class="muted small">Journées complètes uniquement ; la journée en cours figure dans le tableau « Jour par jour ».</p>
  <?php endif; ?>
</div>

<div class="aud-split">
  <div class="card">
    <h3>Appareils</h3>
    <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Appareil</th><th class="num">Visiteurs</th><th class="num">Part</th><th class="num" title="Part des visiteurs de cet appareil qui ont cherché">Cherchent</th><th class="num" title="Recherches par visiteur qui cherche">Rech./vis.</th></tr></thead>
      <tbody>
      <?php foreach (Audience::DEVICES as $dev => $label): $m = $data['devices'][$dev]; ?>
        <tr><td><?= e($label) ?></td><td class="num"><?= fmt_n($m['visitors']) ?></td>
          <td class="num"><span class="aud-bar" style="--w: <?= $t['visitors'] > 0 ? round(100 * $m['visitors'] / $t['visitors']) : 0 ?>%"></span><?= $pct($m['visitors'], $t['visitors']) ?></td>
          <td class="num"><?= $pct($m['searchers'], $m['visitors']) ?></td><td class="num"><?= $ratio($m['searches'], $m['searchers']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="card">
    <h3>Nombre de recherches par visiteur</h3>
    <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Dans la journée</th><th class="num">Visiteurs</th><th class="num">Part</th></tr></thead>
      <tbody>
      <?php foreach (['searchers_1' => '1 recherche', 'searchers_2_3' => '2 ou 3 recherches', 'searchers_4p' => '4 recherches ou plus'] as $k => $label): ?>
        <tr><td><?= e($label) ?></td><td class="num"><?= fmt_n($t[$k]) ?></td>
          <td class="num"><span class="aud-bar" style="--w: <?= $t['searchers'] > 0 ? round(100 * $t[$k] / $t['searchers']) : 0 ?>%"></span><?= $pct($t[$k], $t['searchers']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small">Pendant la frappe, « pal », « palm », « palme » comptent pour une seule recherche.</p>
  </div>
</div>

<?php
$euro = static fn (int $cents) => fmt_n($cents / 100, 2) . ' €';
$others = max(0, $t['visitors'] - $t['searchers']);
$otherBuyers = max(0, $t['buyers'] - $t['searcher_buyers']);
$otherRevenue = max(0, $t['revenue'] - $t['searcher_revenue']);
$otherOrders = max(0, $t['orders'] - $t['searcher_orders']);
$funnel = [
    ['Visiteurs', $t['visitors']],
    ['Ont cherché', $t['searchers']],
    ['Ont cliqué sur un résultat', $t['clickers']],
    ['Ont cherché puis mis au panier', $t['searcher_carters']],
    ['Ont cherché puis commandé', $t['searcher_buyers']],
];
?>
<h2 id="parcours-achat">Parcours d'achat</h2>
<?php if (!$hasCommerce): ?>
  <div class="flash flash-info">Aucun ajout au panier ni commande reçu sur cette période : branchez le site (événements GA4 du <code>dataLayer</code>, lus automatiquement,
    ou <code>DeepFind.track()</code>). Voir l'aide : Analyse › Parcours d'achat. Les indicateurs de recherche ci-dessous fonctionnent déjà.</div>
<?php endif; ?>
<div class="cards aud-kpis">
  <div class="card kpi"><div class="kpi-label">Conversion des visiteurs qui cherchent</div><div class="kpi-value"><?= $pct($t['searcher_buyers'], $t['searchers']) ?></div>
    <div class="muted small">sans recherche : <?= $pct($otherBuyers, $others) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Chiffre d'affaires mesuré</div><div class="kpi-value"><?= $euro($t['revenue']) ?></div>
    <div class="muted small"><?= $pct($t['searcher_revenue'], $t['revenue']) ?> par des visiteurs qui ont cherché</div></div>
  <div class="card kpi"><div class="kpi-label">Panier moyen après recherche</div><div class="kpi-value"><?= $t['searcher_orders'] > 0 ? $euro(intdiv($t['searcher_revenue'], $t['searcher_orders'])) : '—' ?></div>
    <div class="muted small">sans recherche : <?= $otherOrders > 0 ? $euro(intdiv($otherRevenue, $otherOrders)) : '—' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Paniers sans commande</div><div class="kpi-value"><?= $pct($t['abandoners'], $t['carters']) ?></div>
    <div class="muted small"><?= fmt_n($t['searcher_abandoners']) ?> après une recherche</div></div>
  <div class="card kpi"><div class="kpi-label">Partis après « aucun résultat »</div><div class="kpi-value"><?= $pct($t['zero_leavers'], $t['zero_searchers']) ?></div>
    <div class="muted small"><?= fmt_n($t['zero_searchers']) ?> visiteur(s) sans résultat, puis ni clic ni panier</div></div>
  <div class="card kpi"><div class="kpi-label">Ferment la recherche sans cliquer</div><div class="kpi-value"><?= $pct($t['closers'], $t['searchers']) ?></div>
    <div class="muted small">des visiteurs qui cherchent · <?= fmt_n($t['carousel_clickers']) ?> ont cliqué un carrousel</div></div>
</div>
<div class="aud-split">
  <div class="card">
    <h3>Entonnoir de la recherche</h3>
    <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Étape</th><th class="num">Visiteurs</th><th class="num">Part des visiteurs</th></tr></thead>
      <tbody>
      <?php foreach ($funnel as [$label, $n]): ?>
        <tr><td><?= e($label) ?></td><td class="num"><?= fmt_n($n) ?></td>
          <td class="num"><span class="aud-bar" style="--w: <?= $t['visitors'] > 0 ? round(100 * $n / $t['visitors']) : 0 ?>%"></span><?= $pct($n, $t['visitors']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small">Dans la même journée de mesure. Un achat le lendemain d'une recherche n'est pas relié (le visiteur n'est reconnu qu'une journée).</p>
  </div>
  <div class="card">
    <h3>Recherches qui rapportent</h3>
    <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Recherche</th><th class="num">Recherches</th><th class="num">Paniers</th><th class="num">Commandes</th><th class="num">CA</th></tr></thead>
      <tbody>
      <?php foreach ($terms as $r): ?>
        <tr><td><?= e($r['term']) ?></td><td class="num"><?= fmt_n($r['searches']) ?></td><td class="num"><?= fmt_n($r['carts']) ?></td>
          <td class="num"><?= fmt_n($r['orders']) ?></td><td class="num"><?= $euro($r['revenue']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($terms === []): ?><tr><td colspan="5" class="muted">Pas encore de panier ni de commande après une recherche sur cette période.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small">Panier et commande attribués à la dernière recherche du visiteur dans la journée.</p>
  </div>
</div>
<div class="card">
  <h3>Produits cliqués mais jamais mis au panier</h3>
  <div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>Produit</th><th class="num">Clics (recherche et carrousels)</th></tr></thead>
    <tbody>
    <?php foreach ($notCarted as $r): ?>
      <tr><td><a href="<?= e(url('/admin/preview', ['q' => $r['name'] !== '' ? $r['name'] : $r['product_id']])) ?>"><?= e($r['name'] !== '' ? $r['name'] : $r['product_id']) ?></a></td><td class="num"><?= fmt_n($r['clicks']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($notCarted === []): ?><tr><td colspan="2" class="muted"><?= $hasCommerce ? 'Aucun produit cliqué au moins 3 fois sans ajout au panier.' : 'Disponible une fois les ajouts au panier reçus.' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">Cliqués au moins 3 fois sur la période, sans ajout au panier ni commande : prix, photos, disponibilité ou fiche à vérifier.</p>
</div>

<h2>Jour par jour</h2>
<div class="table-wrap">
<table class="table table-compact">
  <thead><tr><th>Jour</th><th class="num">Visiteurs</th><th class="num">Pages vues</th><th class="num">Ont cherché</th><th class="num">%</th><th class="num">Recherches</th><th class="num">Rech./vis.</th><th class="num">Clics</th><th class="num">Mobile</th></tr></thead>
  <tbody>
  <?php foreach (array_reverse($data['days'], true) as $day => $m): if ($m['visitors'] === 0) { continue; } ?>
    <tr><td><?= e(date('d/m/Y', strtotime($day))) ?><?= $day === $today ? ' <span class="muted small">(en cours)</span>' : '' ?></td>
      <td class="num"><?= fmt_n($m['visitors']) ?></td><td class="num"><?= fmt_n($m['pageviews']) ?></td><td class="num"><?= fmt_n($m['searchers']) ?></td>
      <td class="num"><?= $pct($m['searchers'], $m['visitors']) ?></td><td class="num"><?= fmt_n($m['searches']) ?></td><td class="num"><?= $ratio($m['searches'], $m['searchers']) ?></td>
      <td class="num"><?= fmt_n($m['clicks']) ?></td><td class="num"><?= $pct($data['mobile'][$day] ?? 0, $m['visitors']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($t['visitors'] === 0): ?><tr><td colspan="9" class="muted">Aucune donnée sur cette période.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<form method="post" action="<?= e(url('/admin/audience/settings')) ?>" class="card form aud-settings" id="aud-settings">
  <?= csrf_field() ?>
  <?php foreach ($periodQuery as $k => $v): ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
  <h3>Réglages de la mesure</h3>
  <p class="muted small">Aucun cookie, rien n'est stocké sur l'appareil du visiteur, aucune adresse IP enregistrée. Un visiteur est reconnu pendant une journée seulement,
    par une empreinte anonyme (adresse IP et navigateur, mélangés à une clé secrète changée chaque jour puis détruite). Seuls les totaux quotidiens sont conservés.</p>
  <?= $toggle('enabled', $enabled, 'Mesure d\'audience activée', 'Interrupteur général : désactivé, plus rien n\'est collecté (les statistiques déjà calculées restent visibles).') ?>
  <div class="aud-kinds">
    <?= $toggle('pageviews', $tracks['pageviews'], 'Pages vues', 'Le widget envoie un petit signal à chaque page mesurée : c\'est ce qui permet de compter les visiteurs qui ne cherchent pas. Le plus sollicitant pour le serveur.') ?>
    <?= $toggle('searches', $tracks['searches'], 'Recherches', 'Compte les recherches de chaque visiteur (aucune requête en plus : mesuré pendant la recherche).') ?>
    <?= $toggle('clicks', $tracks['clicks'], 'Clics sur les résultats', 'Compte les visiteurs qui ouvrent un résultat (aucune requête en plus).') ?>
  </div>
  <div class="aud-kinds">
    <?= $toggle('commerce', $tracks['commerce'], 'Panier et commandes', 'Ajouts au panier et commandes envoyés par le site : DeepFind.track(), ou événements GA4 du dataLayer ci-dessous. Montants en totaux uniquement.') ?>
    <?= $toggle('datalayer', $datalayer, 'Lire les événements GA4 du dataLayer', 'Le widget lit « add_to_cart » et « purchase » s\'ils sont déjà envoyés au dataLayer (Google Tag Manager, gtag). Rien à ajouter au site dans ce cas.') ?>
    <?= $toggle('journeys', $journeys, 'Parcours (Analyse › Parcours)', 'Chronologie datée des visiteurs qui cherchent ou cliquent sur un carrousel, pour la journée seulement, conservée ' . \DeepFind\Logging\Journeys::RETENTION_HOURS . ' h. À faire valider par le DPO avant d\'activer.') ?>
  </div>
  <label class="aud-hour">Heure de changement de la clé du jour (heure de Paris)
    <select name="day_hour">
      <?php $hSel = $dayHourNext['hour'] ?? $dayHour; for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $h === $hSel ? ' selected' : '' ?>><?= sprintf('%02d h', $h) ?></option><?php endfor; ?>
    </select>
    <small class="muted">Une journée de mesure va de cette heure à la même heure le lendemain : une visite à 1 h du matin compte avec la soirée de la veille.
      Un changement s'applique à la bascule suivante (la journée concernée est alors plus courte ou plus longue).
      <?php if ($dayHourNext): ?><strong>Changement programmé : <?= sprintf('%02d h', (int) $dayHourNext['hour']) ?> à partir du <?= e(date('d/m/Y à H:i', (int) $dayHourNext['at'])) ?>.</strong><?php endif; ?></small>
  </label>
  <fieldset class="aud-pages">
    <legend>Pages qui envoient le signal « page vue »</legend>
    <?php foreach (Audience::PAGES as $v => $label): ?>
      <label class="radio-line"><input type="radio" name="pages" value="<?= e($v) ?>"<?= $pages === $v ? ' checked' : '' ?>> <?= e($label) ?></label>
    <?php endforeach; ?>
    <div class="aud-paths">
      <label>Adresses qui commencent par (une par ligne)
        <textarea name="paths" rows="4" placeholder="/fr/plongee&#10;/fr/apnee"><?= e($paths) ?></textarea>
        <small class="muted">Chemin de la page sans le nom de domaine. <code>/fr/plongee</code> couvre <code>/fr/plongee.html</code> et <code>/fr/plongee/palmes.html</code>. Une adresse complète est aussi acceptée.</small></label>
      <?= $toggle('paths_home', $pathsHome, 'Inclure aussi la page d\'accueil') ?>
    </div>
    <p class="muted small">Page d'accueil : <code>/</code> ou la racine d'une langue (<code>/fr</code>). Moins de pages mesurées = moins de requêtes, mais des visiteurs qui ne cherchent pas sont alors oubliés.</p>
  </fieldset>
  <div class="form-foot"><button class="btn">Enregistrer</button></div>
</form>
<script src="<?= e(asset('/assets/audience.js')) ?>"></script>
