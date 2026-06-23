<?php /** Login form */ ?>
<div class="card auth-card">
  <div class="auth-brand"><?= brandmark() ?></div>
  <h1>Sign in</h1>
  <p class="muted">Organizer access for <?= e(setting('org_name', 'Meeting Poll')) ?>.</p>
  <form method="post" action="<?= url('/login') ?>" class="stack">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn btn-block">Sign in</button>
  </form>
  <p class="muted small"><a href="<?= url('/forgot') ?>">Forgot your password?</a></p>
</div>
