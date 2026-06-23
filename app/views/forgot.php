<?php /** Forgot password */ ?>
<div class="card auth-card">
  <div class="auth-brand"><?= brandmark() ?></div>
  <h1>Reset password</h1>
  <p class="muted">Enter your email and we'll send a reset link (if email is configured on this install).</p>
  <form method="post" action="<?= url('/forgot') ?>" class="stack">
    <?= csrf_field() ?>
    <label>Email
      <input type="email" name="email" autocomplete="username" required autofocus>
    </label>
    <button type="submit" class="btn btn-block">Send reset link</button>
  </form>
  <p class="muted small"><a href="<?= url('/login') ?>">Back to sign in</a></p>
</div>
