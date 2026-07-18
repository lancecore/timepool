<?php
/** @var array $page @var string $week @var string $src */
$weekLabel = (new DateTime($week . ' 00:00:00'))->format('M j, Y');
$srcLabel = (new DateTime($src . ' 00:00:00'))->format('M j, Y');
?>
<div class="page-head"><h1>Copy previous week</h1>
  <a class="btn btn-ghost btn-sm" href="<?= url('/booking/' . $page['id'] . '/edit?week=' . $week) ?>">Back</a>
</div>
<div class="card">
  <p>Copy the availability from the week of <strong><?= e($srcLabel) ?></strong> onto the week of
    <strong><?= e($weekLabel) ?></strong>?</p>
  <p class="muted small">This replaces the target week's current windows. Dates already in the past are skipped.</p>
  <div class="form-actions">
    <form method="post" action="<?= url('/booking/' . $page['id'] . '/copy-week') ?>" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="week" value="<?= e($week) ?>">
      <button type="submit" class="btn">Yes, replace this week</button>
    </form>
    <a class="btn btn-ghost" href="<?= url('/booking/' . $page['id'] . '/edit?week=' . $week) ?>">Cancel</a>
  </div>
</div>
