<?php
/** Paramètres › Notifications : invitation à s'abonner (forme, appareils, contenu, style), installation du sw.js.
 *  @var array $v @var string $vapid @var array $counts @var array $stats @var string $base */
use DeepFind\Push\Push;

$toggle = static fn (string $name, bool $on, string $label, string $help = '') => '<label class="toggle"><input type="checkbox" role="switch" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '>'
    . '<span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">' . e($label)
    . ($help !== '' ? '<small class="muted">' . e($help) . '</small>' : '') . '</span></label>';
$pct = static fn (int $a, int $b) => $b > 0 ? fmt_n(100 * $a / $b, 1) . ' %' : '—';
$swContent = "importScripts('" . $base . "/widget/push-sw.js');";
?>
<p class="muted">Invitez les visiteurs à recevoir les notifications du site (promotions, retours en stock…) sur leur téléphone ou leur ordinateur, sans application.
  Le visiteur clique d'abord sur votre invitation, puis le navigateur lui demande son accord : cette deuxième fenêtre n'est pas modifiable et, s'il la refuse,
  elle ne peut plus être reproposée. Les messages s'envoient depuis <a href="<?= e(url('/admin/push')) ?>">Recommandations › Envoi de notifications</a>.</p>

<div class="cards">
  <div class="card kpi"><div class="kpi-label">Abonnés</div><div class="kpi-value"><?= fmt_n($counts['total']) ?></div>
    <div class="muted small">téléphones <?= fmt_n($counts['phone']) ?> · tablettes <?= fmt_n($counts['tablet']) ?> · ordinateurs <?= fmt_n($counts['desktop']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Invitations (30 j)</div><div class="kpi-value"><?= fmt_n($stats['iv']) ?></div>
    <div class="muted small">« Oui » : <?= $pct($stats['ia'], $stats['iv']) ?> · « Plus tard » : <?= $pct($stats['il'], $stats['iv']) ?></div></div>
  <div class="card kpi"><div class="kpi-label">Accord du navigateur (30 j)</div><div class="kpi-value"><?= $pct($stats['ig'], $stats['ig'] + $stats['id']) ?></div>
    <div class="muted small"><?= fmt_n($stats['ig']) ?> autorisé(s) · <?= fmt_n($stats['id']) ?> bloqué(s)</div></div>
  <div class="card kpi"><div class="kpi-label">Nouveaux abonnés (30 j)</div><div class="kpi-value"><?= fmt_n($stats['sub']) ?></div>
    <div class="muted small"><?= fmt_n($stats['uns']) ?> désabonnement(s)</div></div>
</div>

<form method="post" action="<?= e(url('/admin/push/settings/save')) ?>" class="card form ps-form" data-push-upload="<?= e(url('/admin/push/upload')) ?>" data-csrf="<?= e(\DeepFind\Admin\Auth::csrf()) ?>" data-push-settings>
  <?= csrf_field() ?>
  <?= $toggle('push_enabled', $v['push_enabled'] === '1', 'Notifications activées', 'Désactivées : plus d\'invitation sur le site (les abonnés restent et peuvent recevoir les messages).') ?>

  <h3>1. Forme de l'invitation</h3>
  <div class="ps-forms">
    <?php foreach (Push::FORMS as $k => $label): ?>
      <label class="check"><input type="radio" name="push_form" value="<?= e($k) ?>"<?= $v['push_form'] === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="grid2">
    <label data-ps-show="popin_corner bell">Position
      <select name="push_position"><?php foreach (Push::POSITIONS as $k => $l): ?><option value="<?= e($k) ?>"<?= $v['push_position'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label data-ps-show="block">Sélecteur CSS du bloc <input name="push_selector" value="<?= e($v['push_selector']) ?>" placeholder="#deepfind-notifications" spellcheck="false">
      <small class="muted">L'invitation est ajoutée à la fin de ce bloc, sur les pages où il existe.</small></label>
  </div>
  <div data-ps-show="bell">
    <label class="toggle"><input type="checkbox" role="switch" name="push_bell_hide" value="1"<?= $v['push_bell_hide'] === '1' ? ' checked' : '' ?>><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span><span class="toggle-text">Cacher la cloche une fois le visiteur abonné</span></label>
    <p class="muted small">Activé : la cloche n'apparaît que pour les visiteurs pas encore abonnés, et disparaît dès qu'ils acceptent. Ils peuvent toujours se désabonner
      depuis les réglages du navigateur (icône à gauche de l'adresse du site › Notifications). Désactivé : la cloche reste, avec un point vert, et propose « Ne plus les recevoir ».
      Les autres formes (bandeau, popins, bloc) ne sont de toute façon plus proposées à un visiteur abonné.</p>
  </div>

  <h3>2. Appareils</h3>
  <div class="grid2">
    <label>Proposer sur
      <select name="push_devices"><?php foreach (Push::DEVICES as $k => $l): ?><option value="<?= e($k) ?>"<?= $v['push_devices'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <small class="muted">D'après la taille de l'écran et l'écran tactile.</small></label>
    <div><?= $toggle('push_exclude_ios', $v['push_exclude_ios'] === '1', 'Exclure les iPhone et iPad', 'Sur iPhone et iPad, les notifications ne fonctionnent que si le site a été ajouté à l\'écran d\'accueil (iOS 16.4 ou plus récent) : sinon l\'invitation ne servirait à rien.') ?></div>
  </div>

  <h3>3. Quand l'afficher</h3>
  <div class="grid2">
    <label>Délai après l'arrivée sur la page (secondes) <input type="number" name="push_delay" min="0" max="600" value="<?= e($v['push_delay']) ?>"></label>
    <label>À partir de la N<sup>e</sup> page vue de la visite <input type="number" name="push_min_pages" min="1" max="20" value="<?= e($v['push_min_pages']) ?>">
      <small class="muted">1 : dès la première page. 2 ou 3 : visiteurs déjà intéressés.</small></label>
    <label>Après « Plus tard », reproposer au bout de (jours) <input type="number" name="push_remember_days" min="0" max="365" value="<?= e($v['push_remember_days']) ?>"></label>
    <label>Pages sans invitation (débuts d'adresse, un par ligne) <textarea name="push_exclude_paths" rows="3"><?= e($v['push_exclude_paths']) ?></textarea></label>
  </div>

  <h3>4. Contenu</h3>
  <div class="grid2">
    <label>Titre <input name="push_title" maxlength="190" value="<?= e($v['push_title']) ?>"></label>
    <label>Bouton d'acceptation <input name="push_accept" maxlength="60" value="<?= e($v['push_accept']) ?>"></label>
    <label>Texte <textarea name="push_text" rows="2" maxlength="400"><?= e($v['push_text']) ?></textarea></label>
    <label>Bouton « plus tard » <input name="push_later" maxlength="60" value="<?= e($v['push_later']) ?>"></label>
    <label>Message après l'abonnement <input name="push_thanks" maxlength="190" value="<?= e($v['push_thanks']) ?>"></label>
    <div><label>Image de l'invitation <small class="muted">(facultatif, popins)</small><input name="push_image" value="<?= e($v['push_image']) ?>" placeholder="https://… ou importez un fichier"></label>
      <?= push_upload_button('push_image') ?></div>
  </div>
  <p class="muted small">Variables personnalisées acceptées (<code>{{code}}</code>, Paramètres › Variables).</p>

  <h3>5. Style</h3>
  <div class="ps-colors">
    <label>Fond <input type="color" name="push_color_bg" value="<?= e($v['push_color_bg']) ?>"></label>
    <label>Texte <input type="color" name="push_color_text" value="<?= e($v['push_color_text']) ?>"></label>
    <label>Bouton et cloche <input type="color" name="push_color_button" value="<?= e($v['push_color_button']) ?>"></label>
  </div>
  <label>CSS personnalisé <small class="muted">(facultatif : <code>.df-push</code> invitation, <code>.df-push-bell</code> cloche, <code>.df-push-yes</code> bouton…)</small>
    <textarea name="push_css" rows="4" class="code" spellcheck="false"><?= e($v['push_css']) ?></textarea></label>
  <p><button type="button" class="btn btn-light lv-open" data-live-preview><?= admin_icon('eye') ?> Aperçu ordinateur / mobile</button></p>

  <h3>6. Installation sur le site</h3>
  <p class="muted small">Une seule fois : déposer sur le serveur du site un fichier <strong>sw.js</strong> contenant uniquement la ligne ci-dessous
    (le vrai code est chargé depuis DeepFind et se met à jour tout seul). Il doit être sur le domaine du site ; à la racine (recommandé) ou dans un dossier.</p>
  <div class="ps-code"><code data-copy-src><?= e($swContent) ?></code> <button type="button" class="btn btn-sm btn-light" data-copy>Copier</button></div>
  <div class="grid2">
    <label>Adresse du fichier sur le site <input name="push_sw_path" value="<?= e($v['push_sw_path']) ?>" placeholder="/sw.js" spellcheck="false">
      <small class="muted">Ex. <code>/sw.js</code> (racine) ou <code>/static/frontend/Cabesto/theme/fr_FR/js/sw.js</code>.</small></label>
    <div><label>Icône des notifications <small class="muted">(carré, 192 px conseillé)</small><input name="push_icon" value="<?= e($v['push_icon']) ?>" placeholder="https://www.example.com/icon-192.png ou importez un fichier"></label>
      <?= push_upload_button('push_icon') ?></div>
  </div>
  <p class="muted small">Clé publique de l'installation (VAPID) : <code><?= e(substr($vapid, 0, 24)) ?>…</code> — créée automatiquement ; ne pas la changer, sinon les abonnés existants seraient perdus.</p>

  <div class="cr-foot"><button class="btn">Enregistrer</button></div>
</form>
<script src="<?= e(asset('/assets/live-preview.js')) ?>"></script>
<script src="<?= e(asset('/assets/push-admin.js')) ?>"></script>
