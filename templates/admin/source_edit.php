<?php use DeepFind\Admin\Auth; ?>
<form method="post" action="<?= e(url('/admin/sources/save')) ?>" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
  <input type="hidden" name="kind" value="<?= e($source['kind']) ?>">
  <div class="grid2">
    <label>Code de la source <input name="code" value="<?= e($source['code']) ?>" placeholder="magento_fr" required pattern="[a-z0-9_\-]+"></label>
    <label>Langue
      <select name="lang"><?php foreach (Auth::langs() as $l): ?><option <?= $source['lang'] === $l ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <label>Flux complet : URL http(s) ou chemin local (dossiers autorisés : FEED_LOCAL_DIRS)
    <input name="url" value="<?= e($source['url']) ?>" placeholder="https://www.cabesto.com/media/feeds/search_fr.xml.gz" required></label>
  <?php if ($source['kind'] === 'products'): ?>
  <label>Flux delta (stock / prix / suppressions), facultatif
    <input name="delta_url" value="<?= e($source['delta_url']) ?>" placeholder="https://www.cabesto.com/media/feeds/search_fr_delta.xml"></label>
  <?php endif; ?>
  <label>En-têtes HTTP d'authentification (un par ligne), facultatif
    <textarea name="auth_header" rows="2" placeholder="Authorization: Bearer xxxxx"><?= e($source['auth_header']) ?></textarea>
    <small class="muted">Le flux contient des données confidentielles (marge) : protégez son URL côté Magento (jeton, filtrage IP).</small></label>
  <label class="toggle toggle-sm"><input type="checkbox" role="switch" name="active" <?= $source['active'] ? 'checked' : '' ?><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">> Source active (les imports planifiés ne tournent que si la source est active)</span></label>

  <fieldset class="card">
    <legend>Planification des imports</legend>
    <p class="muted">Heures du serveur (<?= e(date_default_timezone_get()) ?>). Le planificateur doit tourner chaque minute
      (<code>* * * * * php bin/console schedule:run</code>, voir README). Format libre : cron standard « minute heure jour mois jour-semaine ».</p>
    <?php
    $schedRows = ['cron_full' => ['Import complet', \DeepFind\Import\Cron::PRESETS_FULL]];
    if ($source['kind'] === 'products') {
        $schedRows['cron_delta'] = ['Import delta (stock / prix)', \DeepFind\Import\Cron::PRESETS_DELTA];
    }
    foreach ($schedRows as $col => [$label, $presets]):
        $cur = (string) ($source[$col] ?? '');
        $isPreset = array_key_exists($cur, $presets);
        $next = \DeepFind\Import\Cron::nextRuns($cur, 3); ?>
      <div class="grid3 sched-row">
        <label><?= e($label) ?>
          <select data-cron-preset="<?= e($col) ?>">
            <?php foreach ($presets as $expr => $pl): ?><option value="<?= e($expr) ?>" <?= $isPreset && $cur === $expr ? 'selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?>
            <option value="__custom" <?= $isPreset ? '' : 'selected' ?>>Personnalisé…</option>
          </select>
        </label>
        <label>Expression cron
          <input name="<?= e($col) ?>" id="<?= e($col) ?>" value="<?= e($cur) ?>" placeholder="(désactivé)" class="code" spellcheck="false">
        </label>
        <div class="muted sched-next"><?php if ($cur === ''): ?>Aucune exécution automatique.<?php elseif ($next): ?>Prochaines exécutions :<br><?= e(implode(' · ', array_map(static fn ($d) => $d->format('d/m H:i'), $next))) ?>
          <?php else: ?><span class="ko">Expression invalide ou sans exécution dans l'année.</span><?php endif; ?>
          <?php if ($col === 'cron_delta' && trim((string) $source['delta_url']) === '' && $cur !== ''): ?><br><span class="ko">Aucune URL de flux delta : la planification delta est ignorée.</span><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <?php if ($source['id']): ?>
  <div class="flash flash-info">Le plus simple : le <a href="<?= e(url('/admin/sources/mapping', ['id' => $source['id']])) ?>"><strong>mapping visuel</strong></a> analyse le flux et propose ses champs dans des listes déroulantes. Le JSON ci-dessous est le mode avancé.</div>
  <?php else: ?>
  <div class="flash flash-info">Enregistrez d'abord la source (code + URL) : le mapping visuel analysera ensuite le flux.</div>
  <?php endif; ?>
  <label>Mapping (JSON, mode avancé) : correspondance entre votre flux et les champs internes
    <textarea name="mapping" rows="26" class="code" spellcheck="false"><?= e($source['mapping']) ?></textarea></label>
  <div class="actions">
    <button class="btn">Enregistrer</button>
    <button class="btn btn-light" name="to_mapping" value="1">Enregistrer et ouvrir le mapping visuel</button>
    <?php if ($source['id']): ?>
      <button class="btn btn-light" formaction="<?= e(url('/admin/sources/test', ['id' => $source['id']])) ?>">Tester ce mapping (sans enregistrer)</button>
    <?php endif; ?>
  </div>
</form>

<?php if ($source['id']): ?>
<form method="post" action="<?= e(url('/admin/sources/delete')) ?>" class="danger-zone">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $source['id'] ?>">
  <button class="btn btn-danger btn-sm" data-confirm="Supprimer définitivement cette source ?">Supprimer la source</button>
</form>
<?php endif; ?>

<details class="help" open>
  <summary>Aide : champs internes disponibles</summary>
  <p>Chaque champ interne se mappe avec <code>{"path": "chemin", "transform": ["…"]}</code>. Chemins : <code>name</code>, <code>prices/final</code>,
    <code>@id</code> (attribut), <code>category/@path</code>, <code>.</code> (texte), ou XPath (<code>g:price</code>, <code>attribute[@code='color']</code>).
    Valeur fixe : <code>{"value": "simple"}</code>.</p>
  <p>Transformations : <code>trim, lowercase, uppercase, ucfirst, strip_html, to_float, to_int, to_bool, divide:100, multiply:N, default:X, split:|, prefix:X, suffix:X, replace:A|B, truncate:N, date_iso</code>.</p>
  <table class="table compact">
    <thead><tr><th>Champ</th><th>Type</th></tr></thead>
    <tbody><?php foreach ($fields as $f => $t): ?><tr><td><code><?= e($f) ?></code></td><td><?= e($t) ?></td></tr><?php endforeach; ?></tbody>
  </table>
  <?php if ($source['kind'] === 'products'): ?>
  <p><strong>Variantes</strong> : <code>"variant_mode": "nested"</code> (enfants dans <code>&lt;variants&gt;</code>) ou <code>"flat"</code>
    (enfants sur leurs propres lignes avec <code>parent_id</code> ; <code>"parent_match": "id"</code> ou <code>"sku"</code>).
    Champs de variante : <?= e(implode(', ', array_keys($variantFields))) ?>.</p>
  <p><strong>Attributs filtrables</strong> : bloc <code>attributes</code> (liste générique code/libellé/valeur) et/ou
    <code>extra_attributes</code> : <code>{"couleur": {"path": "color", "label": "Couleur", "filterable": true}}</code>.</p>
  <?php endif; ?>
</details>
