<?php
/** Aperçu direct d'une popin ou d'un carrousel : page fictive + vrai widget (mode render), piloté par la fiche (live-preview.js).
 *  @var string $type @var string $lang @var array $config */
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Aperçu</title>
<link rel="stylesheet" href="<?= e(asset('/assets/live-frame.css')) ?>">
</head>
<body data-config="<?= e(\DeepFind\Text::json($config)) ?>" data-type="<?= e($type) ?>">
  <header class="lf-header"><span class="lf-logo"></span><span class="lf-search"></span><span class="lf-icons"></span></header>
  <nav class="lf-nav"><span></span><span></span><span></span><span></span><span></span></nav>
  <main class="lf-main">
    <div class="lf-hero"><span>Page fictive : seul le contenu DeepFind est réel</span></div>
    <div id="df-preview-slot" class="lf-slot"></div>
    <div class="lf-grid"><span></span><span></span><span></span><span></span></div>
    <div class="lf-text"><span></span><span></span><span></span></div>
  </main>
  <script src="<?= e(asset('/widget/deepfind.js')) ?>" defer data-render="1" data-lang="<?= e($lang) ?>"></script>
  <script src="<?= e(asset('/assets/live-frame.js')) ?>" defer></script>
</body>
</html>
