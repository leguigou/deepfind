<?php
/** Recommandations › Campagnes › modifier : nom, période, objectif, produits (un par un et par règles).
 *  @var array $c @var list<array> $items @var list<array> $fields @var ?array $rule */
?>
<p><?php if ($c['id']): ?><a href="<?= e(url('/admin/campaigns/results', ['id' => $c['id']])) ?>" class="cr-back">‹ Retour aux résultats</a>
  <?php else: ?><a href="<?= e(url('/admin/campaigns')) ?>" class="cr-back">‹ Retour aux campagnes</a><?php endif; ?></p>

<form method="post" action="<?= e(url('/admin/campaigns/save')) ?>" class="card form cp-form" data-campaign-form data-rule-root data-items="<?= e(\DeepFind\Text::json($items)) ?>"
      data-search-url="<?= e(url('/admin/custom-results/search')) ?>" data-fields="<?= e(\DeepFind\Text::json($fields)) ?>" data-values-url="<?= e(url('/admin/boost-rules/values')) ?>"
      data-rules="<?= e(\DeepFind\Text::json(['match_all' => $c['match_all'], 'conditions' => $c['conditions']])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
  <input type="hidden" name="items" value="" data-items-field>
  <input type="hidden" name="conditions" value="" data-conds-field>
  <div class="grid2">
    <label>Nom <input name="name" maxlength="190" required value="<?= e($c['name']) ?>" placeholder="ex. Soldes d'été – palmes"></label>
    <label class="toggle cp-active"><input type="checkbox" role="switch" name="active" value="1"<?= $c['active'] ? ' checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Campagne active</span></label>
    <label>Début <small class="muted">(vide : date de création)</small><input type="date" name="starts_at" value="<?= e((string) $c['starts_at']) ?>"></label>
    <label>Fin <small class="muted">(vide : sans fin)</small><input type="date" name="ends_at" value="<?= e((string) $c['ends_at']) ?>"></label>
  </div>
  <p class="muted small cp-when" data-cp-when aria-live="polite"></p>
  <label>Objectif <small class="muted">(facultatif)</small><textarea name="objective" rows="2" maxlength="500" placeholder="ex. écouler le stock de palmes 2025 avant l'arrivée de la nouvelle collection"><?= e($c['objective']) ?></textarea></label>
  <strong class="ca-block-title">Produits à pousser, un par un</strong>
  <div class="ca-picker">
    <input type="search" data-item-search placeholder="Rechercher un produit (nom, SKU, marque…)" aria-label="Rechercher un produit à ajouter" autocomplete="off">
    <div class="ca-results" data-item-results hidden></div>
  </div>
  <ol class="ca-items" data-items-list></ol>
  <details class="cp-bulk"><summary>Coller une liste de SKU</summary>
    <textarea rows="3" data-bulk placeholder="Un SKU par ligne, ou séparés par des virgules"></textarea>
    <button type="button" class="btn btn-light btn-sm" data-bulk-add>Ajouter ces SKU</button>
  </details>
  <div class="cp-rules">
    <strong class="ca-block-title">… et/ou par règles <small class="muted">(comme les boosts : marque, catégorie, promotion, prix…)</small></strong>
    <p class="muted small">Tous les produits du catalogue qui remplissent les règles sont suivis, en plus de ceux choisis un par un. La liste suit le catalogue
      (un nouveau produit de la marque entre dans la campagne). Exemple : Marque est « Ecoflow » et Catégorie contient « panneau solaire ».</p>
    <label class="check"><input type="radio" name="cp_match" value="all"><span>remplissent <strong>toutes</strong> les conditions</span></label>
    <label class="check"><input type="radio" name="cp_match" value="any"><span>remplissent <strong>au moins une</strong> condition</span></label>
    <div class="br-conds" data-conds></div>
    <button type="button" class="btn btn-light br-add-cond" data-add-cond>+ Ajouter une condition</button>
    <?php if ($rule !== null): $ru = $rule; ?>
      <p class="muted small"><?= $ru['error'] !== null ? e($ru['error']) : 'Aujourd\'hui : ' . fmt_n($ru['total']) . ' produit(s) correspondent' . ($ru['total'] > \DeepFind\Rules\Campaigns::RULE_LIMIT ? ' (suivi limité aux ' . \DeepFind\Rules\Campaigns::RULE_LIMIT . ' premiers : précisez les règles)' : '') . '.' ?></p>
    <?php endif; ?>
  </div>
  <div class="cp-highlight">
    <strong class="ca-block-title">Mettre en avant pendant la campagne</strong>
    <p class="muted small">Du jour de début à 0 h au jour de fin à 23 h 59 (heure de Paris), puis tout redevient normal, sans rien à défaire.
      Seuls les produits <strong>en stock</strong> sont mis en avant.</p>
    <div class="cp-hl-row">
      <label class="toggle"><input type="checkbox" role="switch" name="highlight_search" value="1"<?= $c['highlight_search'] ? ' checked' : '' ?> data-cp-hl="search"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Dans la recherche</span></label>
      <label class="cp-hl-opt" data-cp-hl-opt="search">Force
        <select name="search_boost"><?php foreach (\DeepFind\Rules\Campaigns::BOOSTS as $v => $l): ?><option value="<?= e($v) ?>"<?= abs($c['search_boost'] - (float) $v) < 0.01 ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <p class="muted small">Les produits de la campagne remontent dans les résultats <strong>quand ils correspondent à la recherche</strong> (comme une règle de boost) :
      « gilet » fait remonter les gilets de la campagne, « tente » ne les fait pas apparaître.</p>
    <div class="cp-hl-row">
      <label class="toggle"><input type="checkbox" role="switch" name="highlight_carousels" value="1"<?= $c['highlight_carousels'] ? ' checked' : '' ?> data-cp-hl="carousels"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Dans les carrousels</span></label>
      <label class="cp-hl-opt" data-cp-hl-opt="carousels">Places en tête
        <input type="number" name="carousel_slots" min="1" max="<?= \DeepFind\Rules\Campaigns::MAX_SLOTS ?>" value="<?= (int) $c['carousel_slots'] ?>"></label>
    </div>
    <p class="muted small">Dans chaque carrousel automatique, les premières places vont aux produits de la campagne <strong>les plus susceptibles d'être vendus</strong>
      (ventes, puis clics des 30 derniers jours, puis score métier), <strong>seulement là où ils ont leur place</strong> : ils doivent remplir les conditions du carrousel
      et de la page (ex. un carrousel « Pêche » ne montre pas un produit de nautisme) et, pour « produits similaires », ressembler au produit de la page.
      Les carrousels de produits choisis à la main, du panier et de l'historique du visiteur ne changent pas.</p>
  </div>
  <div class="cr-foot">
    <?php if ($c['id']): ?><button class="btn btn-danger cr-delete-btn" form="cp-delete-form" data-confirm="Supprimer la campagne « <?= e($c['name']) ?> » ?"><?= admin_icon('trash') ?> Supprimer cette campagne</button><?php endif; ?>
    <a class="btn btn-light" href="<?= e(url($c['id'] ? '/admin/campaigns/results' : '/admin/campaigns', $c['id'] ? ['id' => $c['id']] : [])) ?>">Annuler</a>
    <button class="btn">Enregistrer</button>
  </div>
</form>
<?php if ($c['id']): ?>
  <form method="post" action="<?= e(url('/admin/campaigns/delete')) ?>" id="cp-delete-form" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"></form>
<?php endif; ?>
<script src="<?= e(asset('/assets/conditions.js')) ?>"></script>
<script src="<?= e(asset('/assets/campaigns.js')) ?>"></script>
