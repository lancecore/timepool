<?php
/** @var array $booking @var array $page @var string $viewTz */
$slot = booking_event_slot($booking);
$already = $booking['status'] === 'cancelled';
$viewTz = $viewTz ?? (string)$page['tz'];
$tzq = '?tz=' . rawurlencode($viewTz);
?>
<div class="respond">
  <h1>Cancel booking</h1>
  <div class="card">
    <?php if ($already): ?>
      <p>This booking is already cancelled — nothing to do.</p>
      <p class="mt"><a class="btn btn-ghost" href="<?= url('/m/' . $booking['manage_token'] . $tzq) ?>">Back to booking</a></p>
    <?php else: ?>
      <p>Are you sure you want to cancel your booking for <strong><?= e($page['title']) ?></strong> at
        <strong><time><?= e(slot_label($slot, $viewTz)) ?></time></strong>
        (<?= e($viewTz) ?>)?</p>
      <p class="muted small">This frees the time for someone else and cannot be undone.</p>
      <div class="form-actions">
        <form method="post" action="<?= url('/m/' . $booking['manage_token'] . '/cancel' . $tzq) ?>" class="inline">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-danger">Yes, cancel my booking</button>
        </form>
        <a class="btn btn-ghost" href="<?= url('/m/' . $booking['manage_token'] . $tzq) ?>">Keep my booking</a>
      </div>
    <?php endif; ?>
  </div>
</div>
