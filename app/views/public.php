<?php /** @var string $content @var string $title */
$org = setting('org_name', 'TimePool');
$hasLogo = (string)setting('logo_file', '') !== '';
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?></title>
<meta name="theme-color" content="<?= e(accent()) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='<?= rawurlencode(accent()) ?>'/%3E%3Cpath d='M8 17l5 5 11-12' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= asset_url('/assets/app.css') ?>">
<style>:root{--accent:<?= e(accent()) ?>}</style>
<script>(function(){try{var t=localStorage.getItem('tp-theme');if(t)document.documentElement.dataset.theme=t;else if(matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.dataset.theme='dark';}catch(e){}})();</script>
</head>
<body class="public">
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="wrap topbar-in">
    <span class="brand">
      <?php if ($hasLogo): ?><img src="<?= logo_url() ?>" alt="" class="brand-logo"><?php endif; ?>
      <span><?= e($org) ?></span>
    </span>
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">◐</button>
  </div>
</header>
<?php foreach (take_flash() as $f): ?>
  <div class="wrap"><div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div></div>
<?php endforeach; ?>
<main id="main" class="wrap narrow"><?= $content ?></main>
<footer class="foot"><div class="wrap">Made with <a href="https://github.com/lancecore/timepool">TimePool</a> • Prompted into existence by <img src="<?= asset_url('/assets/cascadiasouth.ico') ?>" alt="" class="foot-ico"> <a href="https://cascadiasouth.com">Cascadia South</a></div></footer>
<script src="<?= asset_url('/assets/app.js') ?>" defer></script>
</body>
</html>
