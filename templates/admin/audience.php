<?php
/** @var int $days @var string $from @var string $to @var bool $custom @var array $periodQuery @var array $data @var bool $enabled @var array $tracks @var string $pages @var string $paths @var bool $pathsHome */
use DeepFind\Admin\AudienceController;
use DeepFind\Logging\Audience;

$t = $data['total'];
$activeDays = count(array_filter($data['days'], static fn ($d) => $d['visitors'] > 0));
$pct = static fn (int $a, int $b) => $b > 0 ? fmt_n(100 * $a / $b, 1) . ' %' : '—';
$ratio = static fn (int $a, int $b) => $b > 0 ? fmt_n($a / $b, 1) : '—';
// Courbe : du premier jour mesuré à la veille (la journée en cours, incomplète, ferait croire à une chute)
$series = [];
$today = date('Y-m-d');
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
  <label>Du <input type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" required></label>
  <label>Au <input type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" required></label>
  <button class="btn btn-sm">Appliquer</button>
  <span class="muted small">2 ans au plus.</span>
</form>
<p class="muted small aud-range">Du <?= e(date('d/m/Y', strtotime($from))) ?> au <?= e(date('d/m/Y', strtotime($to))) ?><?= $to === date('Y-m-d') ? ' (aujourd\'hui inclus, journée en cours)' : '' ?>.</p>

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

<h2>Jour par jour</h2>
<div class="table-wrap">
<table class="table table-compact">
  <thead><tr><th>Jour</th><th class="num">Visiteurs</th><th class="num">Pages vues</th><th class="num">Ont cherché</th><th class="num">%</th><th class="num">Recherches</th><th class="num">Rech./vis.</th><th class="num">Clics</th><th class="num">Mobile</th></tr></thead>
  <tbody>
  <?php foreach (array_reverse($data['days'], true) as $day => $m): if ($m['visitors'] === 0) { continue; } ?>
    <tr><td><?= e(date('d/m/Y', strtotime($day))) ?><?= $day === date('Y-m-d') ? ' <span class="muted small">(en cours)</span>' : '' ?></td>
      <td class="num"><?= fmt_n($m['visitors']) ?></td><td class="num"><?= fmt_n($m['pageviews']) ?></td><td class="num"><?= fmt_n($m['searchers']) ?></td>
      <td class="num"><?= $pct($m['searchers'], $m['visitors']) ?></td><td class="num"><?= fmt_n($m['searches']) ?></td><td class="num"><?= $ratio($m['searches'], $m['searchers']) ?></td>
      <td class="num"><?= fmt_n($m['clicks']) ?></td><td class="num"><?= $pct($data['mobile'][$day] ?? 0, $m['visitors']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($t['visitors'] === 0): ?><tr><td colspan="9" class="muted">Aucune donnée sur cette période.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<form method="post" action="<?= e(url('/admin/audience/settings')) ?>" class="card form aud-settings">
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
  <button class="btn">Enregistrer</button>
</form>
<script src="<?= e(asset('/assets/audience.js')) ?>"></script>
