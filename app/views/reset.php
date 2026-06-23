<?php /** @var string $token */ ?>
<div class="card auth-card">
  <div class="auth-brand"><?= brandmark() ?></div>
  <h1>Choose a new password</h1>
  <form method="post" action="<?= url('/reset') ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>New password
      <input type="password" name="password" autocomplete="new-password" minlength="8" required autofocus>
    </label>
    <button type="submit" class="btn btn-block">Update password</button>
  </form>
</div>
