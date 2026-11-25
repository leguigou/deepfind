<?php
/** Analyse › Parcours › un parcours : chronologie datée.
 *  @var array $j @var list<array> $events @var string $back */
use DeepFind\Logging\Audience;
use DeepFind\Logging\Journeys;

$euro = static fn (int $cents) => fmt_n($cents / 100, 2) . ' €';
$backUrl = str_starts_with($back, '/admin/journeys') ? url($back) : url('/admin/journeys');
$dur = max(0, strtotime($j['last_at']) - strtotime($j['started_at']));
$icons = ['page' => 'file-search', 'bar' => 'search', 'search' => 'search', 'click' => 'external-link', 'close' => 'x', 'carousel_click' => 'carousel',
    'popin' => 'app-window', 'cart' => 'shopping-cart', 'order' => 'check'];
/** Texte d'un événement (tout est échappé par e() à l'affichage). @return array{0:string, 1:string} [titre, détail] */
$describe = static function (array $ev) use ($euro): array {
    $d = $ev['data'];
    return match ($ev['type']) {
        'page' => [($d['title'] ?? '') !== '' ? $d['title'] : 'Page vue', $d['path'] ?? ''],
        'bar' => ['Barre de recherche ouverte', ''],
        'search' => ['Recherche « ' . ($d['q'] ?? '') . ' »', ((int) ($d['ap'] ?? 0) > 0 ? 'aucun résultat exact · ' . fmt_n((int) $d['ap']) . ' approchant' . ((int) $d['ap'] > 1 ? 's' : '')
            : fmt_n((int) ($d['n'] ?? 0)) . ' résultat' . ((int) ($d['n'] ?? 0) > 1 ? 's' : '')) . (($d['f'] ?? '') !== '' ? ' · filtres : ' . $d['f'] : '')],
        'click' => ['Clic sur « ' . (($d['name'] ?? '') !== '' ? $d['name'] : ($d['id'] ?? '')) . ' »', 'position ' . (int) ($d['pos'] ?? 0) . (($d['q'] ?? '') !== '' ? ' · recherche « ' . $d['q'] . ' »' : '')],
        'close' => ['Fenêtre de recherche fermée sans clic', ($d['q'] ?? '') !== '' ? 'dernière recherche « ' . $d['q'] . ' »' : ''],
        'carousel_click' => ['Clic dans le carrousel « ' . ($d['carousel'] ?? '') . ' »', ($d['name'] ?? '') !== '' ? $d['name'] : ($d['id'] ?? '')],
        'popin' => [($d['e'] ?? '') === 'c' ? 'Clic sur la popin « ' . ($d['popin'] ?? '') . ' »' : 'Popin « ' . ($d['popin'] ?? '') . ' » fermée', ''],
        'cart' => ['Ajout au panier : ' . (($d['name'] ?? '') !== '' ? $d['name'] : ($d['sku'] ?? '')), '× ' . (int) ($d['qty'] ?? 1) . ((int) ($d['value'] ?? 0) > 0 ? ' · ' . $euro((int) $d['value']) : '') . (($d['sku'] ?? '') !== '' ? ' · SKU ' . $d['sku'] : '')],
        'order' => ['Commande : ' . $euro((int) ($d['value'] ?? 0)), (int) ($d['items'] ?? 0) > 0 ? (int) $d['items'] . ' article(s)' : ''],
        default => [Journeys::TYPES[$ev['type']] ?? $ev['type'], ''],
    };
};
?>
<p><a href="<?= e($backUrl) ?>" class="cr-back">‹ Retour aux parcours</a></p>
<div class="cards jr-kpis">
  <div class="card kpi"><div class="kpi-label">Journée de mesure</div><div class="kpi-value"><?= e(date('d/m/Y', strtotime($j['day']))) ?></div>
    <div class="muted small"><?= e(date('H:i', strtotime($j['started_at']))) ?> → <?= e(date('H:i', strtotime($j['last_at']))) ?> · <?= $dur < 60 ? $dur . ' s' : intdiv($dur, 60) . ' min' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Appareil</div><div class="kpi-value"><?= e(Audience::DEVICES[$j['device']] ?? $j['device']) ?></div>
    <div class="muted small">entrée : <?= e($j['entry_path'] !== '' ? $j['entry_path'] : '—') ?></div></div>
  <div class="card kpi"><div class="kpi-label">Recherches</div><div class="kpi-value"><?= fmt_n((int) $j['searches']) ?></div>
    <div class="muted small"><?= (int) $j['zero_searches'] > 0 ? fmt_n((int) $j['zero_searches']) . ' sans résultat' : 'toutes avec résultats' ?></div></div>
  <div class="card kpi"><div class="kpi-label">Clics</div><div class="kpi-value"><?= fmt_n((int) $j['clicks'] + (int) $j['carousel_clicks']) ?></div>
    <div class="muted small">dont <?= fmt_n((int) $j['carousel_clicks']) ?> dans un carrousel</div></div>
  <div class="card kpi"><div class="kpi-label">Commande</div><div class="kpi-value"><?= (int) $j['orders'] > 0 ? $euro((int) $j['revenue']) : '—' ?></div>
    <div class="muted small"><?= (int) $j['carts'] > 0 ? fmt_n((int) $j['carts']) . ' ajout(s) au panier · ' . $euro((int) $j['cart_value']) : 'aucun ajout au panier' ?></div></div>
</div>

<ol class="jr-timeline">
  <?php foreach ($events as $ev): [$evTitle, $detail] = $describe($ev); ?>
    <li class="jr-ev jr-<?= e($ev['type']) ?>">
      <time datetime="<?= e(str_replace(' ', 'T', $ev['at'])) ?>"><?= e(substr($ev['at'], 11, 8)) ?></time>
      <span class="jr-dot" aria-hidden="true"><?= admin_icon($icons[$ev['type']] ?? 'activity') ?></span>
      <div class="jr-body"><span class="jr-type"><?= e(Journeys::TYPES[$ev['type']] ?? $ev['type']) ?></span>
        <strong><?= e($evTitle) ?></strong><?php if ($detail !== ''): ?><small class="muted"><?= e($detail) ?></small><?php endif; ?></div>
    </li>
  <?php endforeach; ?>
</ol>
<p class="muted small">Heures de Paris. « <?= e($j['label']) ?> » n'est qu'un repère : l'empreinte change chaque jour et ne permet pas de retrouver la personne.
  Parcours supprimé <?= (int) Journeys::RETENTION_HOURS ?> h après son dernier événement.</p>
