<?php
/** @var array $page @var string $month @var array $weeks @var string $today
 *  @var array $booked @var array $openCount @var array $blockedOrg @var array $blockedPage */
$m1 = new DateTime($month . '-01 00:00:00');
$label = $m1->format('F Y');
$prev = (clone $m1)->modify('-1 month')->format('Y-m');
$next = (clone $m1)->modify('+1 month')->format('Y-m');
$thisMonth = substr($today, 0, 7);
$calUrl = function (string $m) use ($page): string { return url('/booking/' . $page['id'] . '/calendar?month=' . $m); };
?>
<div class="page-head">
  <h1><?= e($page['title']) ?></h1>
  <div class="head-actions">
    <a class="btn btn-ghost btn-sm" href="<?= url('/booking/' . $page['id'] . '/edit') ?>">Edit page</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('/booking') ?>">Back</a>
  </div>
</div>

<section class="card">
  <div class="week-bar">
    <a class="btn btn-sm btn-ghost" href="<?= e($calUrl($prev)) ?>">← Previous</a>
    <span class="week-label"><?= e($label) ?></span>
    <a class="btn btn-sm btn-ghost" href="<?= e($calUrl($next)) ?>">Next →</a>
  </div>
  <?php if ($month !== $thisMonth): ?>
    <div class="week-tools"><a class="btn btn-sm btn-ghost" href="<?= e($calUrl($thisMonth)) ?>">This month</a></div>
  <?php endif; ?>
  <p class="muted small">All times in <?= e($page['tz']) ?>. Booked slots are shown on their day; the open count is what visitors can still pick (it already accounts for bookings on your other pages).</p>

  <div class="cal">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $h): ?>
      <div class="cal-h"><?= $h ?></div>
    <?php endforeach; ?>
    <?php foreach ($weeks as $wk): foreach ($wk as $d):
      $cls = 'cal-day';
      if (substr($d, 0, 7) !== $month) $cls .= ' is-out';
      if ($d < $today) $cls .= ' is-past';
      if ($d === $today) $cls .= ' is-today';
      $dayBookings = $booked[$d] ?? [];
      $open = $openCount[$d] ?? 0;
      $blocked = isset($blockedOrg[$d]) ? 'Day off' : (isset($blockedPage[$d]) ? 'Blocked' : '');
    ?>
      <div class="<?= $cls ?>">
        <div class="cal-date"><?= (int)substr($d, 8, 2) ?></div>
        <?php if ($blocked !== ''): ?><div class="cal-blocked"><?= $blocked ?></div><?php endif; ?>
        <?php foreach ($dayBookings as $x): $b = $x['b']; ?>
          <div class="cal-booked" title="<?= e($b['name'] . ' <' . $b['email'] . '>' . (($b['note'] ?? '') !== '' ? ' — ' . $b['note'] : '')) ?>">
            <?= e($x['time']) ?> · <?= e($b['name']) ?>
          </div>
        <?php endforeach; ?>
        <?php if ($open > 0): ?><div class="cal-open"><?= $open ?> open</div><?php endif; ?>
      </div>
    <?php endforeach; endforeach; ?>
  </div>
</section>
