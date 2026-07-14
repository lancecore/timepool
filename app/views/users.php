<?php /** @var array $users  Organizers (admin) */
$me = current_user();
?>
<div class="page-head"><h1>Organizers</h1></div>

<div class="cols">
  <section class="card">
    <h2>Team</h2>
    <ul class="user-list">
      <?php foreach ($users as $u): ?>
        <li class="user-row <?= $u['active'] ? '' : 'is-off' ?>">
          <div>
            <strong><?= e($u['name']) ?></strong> <span class="muted small"><?= e($u['email']) ?></span>
            <span class="pill <?= $u['role'] === 'admin' ? 'pill-ok' : '' ?>"><?= e($u['role']) ?></span>
            <?php if (!$u['active']): ?><span class="pill">disabled</span><?php endif; ?>
          </div>
          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
            <form method="post" action="<?= url('/users/' . $u['id'] . '/toggle') ?>" class="inline">
              <?= csrf_field() ?>
              <button class="btn btn-ghost btn-sm"><?= $u['active'] ? 'Disable' : 'Enable' ?></button>
            </form>
          <?php else: ?><span class="muted small">you</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Add an organizer</h2>
    <form method="post" action="<?= url('/users') ?>" class="stack">
      <?= csrf_field() ?>
      <label>Name <input type="text" name="name" value="<?= e(old('name')) ?>" required></label>
      <label>Email <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="off"></label>
      <label>Role
        <select name="role"><option value="organizer">Organizer</option><option value="admin" <?= old('role') === 'admin' ? 'selected' : '' ?>>Admin</option></select>
      </label>
      <button class="btn">Send invitation</button>
      <p class="muted small">They'll get an email with a link to set their own password.</p>
    </form>
  </section>
</div>
