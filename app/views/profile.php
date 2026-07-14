<?php $me = current_user(); ?>
<div class="page-head"><h1>Your profile</h1></div>

<div class="cols">
  <section class="card">
    <h2>Profile</h2>
    <form method="post" action="<?= url('/profile') ?>" class="stack">
      <?= csrf_field() ?>
      <label>Name <input type="text" name="name" value="<?= e(old('name', $me['name'])) ?>" required></label>
      <label>Email <input type="email" value="<?= e($me['email']) ?>" disabled></label>
      <button class="btn">Save</button>
    </form>
  </section>

  <section class="card">
    <h2>Change password</h2>
    <form method="post" action="<?= url('/profile') ?>" class="stack">
      <?= csrf_field() ?>
      <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
      <label>New password <input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
      <button class="btn">Change password</button>
    </form>
  </section>
</div>
