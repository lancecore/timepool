<?php
/** @var array $booking @var array $page @var string $viewTz */
$slot = booking_event_slot($booking);
$poll = booking_event_poll($page);
$cancelled = $booking['status'] === 'cancelled';
$manageUrl = absolute_url('/m/' . $booking['manage_token']);
$viewTz = $viewTz ?? (string)$page['tz'];
$tzq = '?tz=' . rawurlencode($viewTz);
?>
<div class="respond">
  <h1><?= e($page['title']) ?></h1>

  <?php if ($cancelled): ?>
    <div class="flash flash-error" role="status">This booking has been cancelled.</div>
  <?php endif; ?>

  <div class="card <?= $cancelled ? '' : 'confirmed' ?>">
    <h2><?= $cancelled ? 'Cancelled booking' : '✓ Booking confirmed' ?></h2>
    <p class="confirmed-when"><time><?= e(slot_label($slot, $viewTz)) ?></time>
      <span class="muted small">(<?= e($viewTz) ?>)</span></p>
    <p class="muted small">Booked by <?= e($booking['name']) ?> · <?= e($booking['email']) ?><?= (int)$booking['duration_min'] ? ' · ' . (int)$booking['duration_min'] . ' min' : '' ?></p>
    <?php if ($page['location']): ?><p class="muted small">📍 <?= e($page['location']) ?></p><?php endif; ?>
    <?php if (!empty($booking['note'])): ?><p class="muted small">Note: <?= e($booking['note']) ?></p><?php endif; ?>

    <?php if (!$cancelled): ?>
      <div class="cal-links">
        <a class="btn btn-sm" href="<?= e(gcal_link($poll, $slot)) ?>" target="_blank" rel="noopener">Add to Google</a>
        <a class="btn btn-sm" href="<?= e(outlook_link($poll, $slot)) ?>" target="_blank" rel="noopener">Add to Outlook</a>
        <a class="btn btn-sm" href="<?= e(url('/m/' . $booking['manage_token'] . '/ics')) ?>">Apple / .ics</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Manage your booking</h2>
    <p class="muted small">Bookmark this page — it's your link to this booking.</p>
    <div class="copy-row">
      <input type="text" readonly value="<?= e($manageUrl) ?>" data-copy-src aria-label="Manage link">
      <button type="button" class="btn btn-sm" data-copy>Copy</button>
    </div>
    <?php if (!$cancelled): ?>
      <p class="mt"><a class="btn btn-sm btn-danger" href="<?= url('/m/' . $booking['manage_token'] . '/cancel' . $tzq) ?>">Cancel this booking</a></p>
    <?php else: ?>
      <p class="mt"><a class="btn btn-sm" href="<?= url('/b/' . $page['public_token']) ?>">Book another time</a></p>
    <?php endif; ?>
  </div>
</div>
