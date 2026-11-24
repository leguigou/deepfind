<?php
/** @var array $ranges */
?>
<div class="mon-root" data-monitor data-url="<?= e(url('/admin/monitor/data')) ?>">
  <div class="mon-filters" role="group" aria-label="Période affichée">
    <div class="mon-chips" role="radiogroup" aria-label="Période">
      <?php foreach ($ranges as $k => [$label]): ?>
        <button type="button" class="mon-chip" role="radio" aria-checked="<?= $k === '1h' ? 'true' : 'false' ?>" data-range="<?= e($k) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <label class="mon-since">Depuis le <input type="datetime-local" data-since aria-label="Afficher depuis un instant précis"></label>
    <label class="toggle toggle-sm mon-live"><input type="checkbox" role="switch" data-live checked aria-label="Actualiser en direct"><span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
      <span class="toggle-text"><span class="mon-dot" aria-hidden="true"></span> En direct</span></label>
    <span class="muted small" data-updated role="status">Chargement…</span>
  </div>

  <div class="mon-kpis" data-kpis></div>

  <div class="mon-grid">
    <section class="card mon-card mon-wide">
      <div class="mon-card-head"><h2>Activité sur le site</h2><span class="muted small" data-step></span></div>
      <p class="muted small">Requêtes de recherche (une par frappe dans le champ), pages vues (mesure d'audience) et clics sur un produit, par pas de temps.</p>
      <div class="mon-chart" data-chart="activity"></div>
    </section>
    <section class="card mon-card">
      <div class="mon-card-head"><h2>Visiteurs actifs</h2></div>
      <p class="muted small">Personnes différentes ayant chargé une page ou cherché, en moyenne par minute.</p>
      <div class="mon-chart" data-chart="visitors"></div>
    </section>
    <section class="card mon-card">
      <div class="mon-card-head"><h2>Temps de réponse des recherches</h2></div>
      <p class="muted small">Moyenne et 95<sup>e</sup> percentile (95 % des recherches répondent plus vite), en millisecondes.</p>
      <div class="mon-chart" data-chart="latency"></div>
    </section>
    <section class="card mon-card">
      <div class="mon-card-head"><h2>Charge du serveur</h2></div>
      <p class="muted small">Processeur (charge rapportée au nombre de cœurs) et mémoire utilisée, en %.</p>
      <div class="mon-chart" data-chart="server"></div>
    </section>
    <section class="card mon-card">
      <div class="mon-card-head"><h2>Répartition des temps de réponse</h2></div>
      <p class="muted small">Nombre de recherches par tranche de durée, sur la période.</p>
      <div class="mon-chart" data-chart="histogram"></div>
    </section>
    <section class="card mon-card mon-wide">
      <div class="mon-card-head"><h2>Recherches heure par heure, 5 derniers jours</h2></div>
      <p class="muted small">Plus la case est foncée, plus il y a eu de requêtes de recherche dans l'heure.</p>
      <div class="mon-heat" data-chart="heatmap"></div>
    </section>
  </div>

  <details class="card mon-table">
    <summary>Voir les données sous forme de tableau</summary>
    <div class="table-scroll" data-table></div>
  </details>
  <p class="muted small">Mesuré sur l'API publique du widget (le site), sans donnée personnelle : compteurs par minute conservés 6 jours.
    Visiteurs repérés par l'empreinte du jour de la mesure d'audience (sans adresse IP). Les robots et requêtes refusées ne comptent pas comme visiteurs.</p>
</div>
<script src="<?= e(asset('/assets/monitor.js')) ?>"></script>
