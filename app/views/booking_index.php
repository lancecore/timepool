<?php
/** @var array $pages @var array $bookings @var array $daysoff @var array $me */
$now = time();
$upcoming = $past = [];
foreach ($bookings as $b) {
    if ($b['status'] === 'active' && (int)$b['start_utc'] >= $now) $upcoming[] = $b;
    else $past[] = $b;
}
$upcoming = array_reverse($upcoming); // soonest first
?>
<div class="page-head">
  <h1>Booking pages</h1>
  <a class="btn" href="<?= url('/booking/new') ?>">+ New booking page</a>
</div>

<?php if (!$pages): ?>
  <div class="card empty">
    <h2>No booking pages yet</h2>
    <p class="muted">Create a booking page to let people pick a 1:1 time from your availability.</p>
    <a class="btn" href="<?= url('/booking/new') ?>">Create a booking page</a>
  </div>
<?php else: ?>
  <ul class="poll-list">
    <?php foreach ($pages as $p): $link = absolute_url('/b/' . $p['public_token']); ?>
      <li class="card poll-row">
        <div class="poll-row-main">
          <a class="poll-title" href="<?= url('/booking/' . $p['id'] . '/edit') ?>"><?= e($p['title']) ?></a>
          <div class="poll-meta muted small">
            <span><?= (int)$p['duration_min'] ?> min</span> ·
            <?php if (!empty($p['paused'])): ?><span class="pill">Paused</span><?php else: ?><span class="pill pill-live">Open</span><?php endif; ?>
            · <a href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($link) ?></a>
          </div>
        </div>
        <div class="head-actions">
          <a class="btn btn-ghost btn-sm" href="<?= url('/booking/' . $p['id'] . '/edit') ?>">Edit</a>
          <form method="post" action="<?= url('/booking/' . $p['id'] . '/pause') ?>" class="inline">
            <?= csrf_field() ?><input type="hidden" name="paused" value="<?= !empty($p['paused']) ? '0' : '1' ?>">
            <button class="btn btn-ghost btn-sm"><?= !empty($p['paused']) ? 'Resume' : 'Pause' ?></button>
          </form>
          <form method="post" action="<?= url('/booking/' . $p['id'] . '/delete') ?>" class="inline" data-confirm="Delete this booking page? Past and cancelled bookings are removed with it.">
            <?= csrf_field() ?><button class="btn btn-ghost btn-sm btn-danger">Delete</button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="cols">
  <section class="card">
    <h2>Days off</h2>
    <p class="muted small">Blocked dates apply to all your booking pages — no slots are offered on them.</p>
    <form method="post" action="<?= url('/booking/daysoff') ?>" class="copy-row">
      <?= csrf_field() ?>
      <input type="date" name="day" aria-label="Day off date" required>
      <button class="btn btn-sm btn-ghost">Add</button>
    </form>
    <?php if ($daysoff): ?>
      <ul class="activity mt">
        <?php foreach ($daysoff as $d): ?>
          <li>
            <span><?= e((DateTime::createFromFormat('Y-m-d', $d['day']) ?: new DateTime('now'))->format('l, M j, Y')) ?></span>
            <form method="post" action="<?= url('/booking/daysoff/' . $d['id'] . '/delete') ?>" class="inline">
              <?= csrf_field() ?><button class="linklike">Remove</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted small mt">No days off set.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Upcoming bookings</h2>
    <?php if (!$upcoming): ?>
      <p class="muted small">No upcoming bookings.</p>
    <?php else: ?>
      <ul class="booking-list">
        <?php foreach ($upcoming as $b): ?>
          <li class="booking-item">
            <div>
              <strong><?= e(booking_when($b)) ?></strong> <span class="muted small">(<?= e($b['page_tz']) ?>)</span>
              <div class="muted small"><?= e($b['page_title']) ?> · <?= e($b['name']) ?> · <?= e($b['email']) ?></div>
              <?php if (!empty($b['note'])): ?><div class="muted small">Note: <?= e($b['note']) ?></div><?php endif; ?>
            </div>
            <form method="post" action="<?= url('/booking/bookings/' . $b['id'] . '/cancel') ?>" class="inline" data-confirm="Cancel this booking? The slot will reopen.">
              <?= csrf_field() ?><button class="linklike">Cancel</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Past &amp; cancelled bookings</h2>
  <?php if (!$past): ?>
    <p class="muted small">Nothing here yet.</p>
  <?php else: ?>
    <ul class="booking-list">
      <?php foreach ($past as $b): ?>
        <li class="booking-item">
          <div>
            <strong><?= e(booking_when($b)) ?></strong> <span class="muted small">(<?= e($b['page_tz']) ?>)</span>
            <?php if ($b['status'] === 'cancelled'): ?><span class="pill">Cancelled</span><?php endif; ?>
            <div class="muted small"><?= e($b['page_title']) ?> · <?= e($b['name']) ?> · <?= e($b['email']) ?></div>
            <?php if (!empty($b['note'])): ?><div class="muted small">Note: <?= e($b['note']) ?></div><?php endif; ?>
          </div>
          <?php if ($b['status'] === 'active'): ?>
            <form method="post" action="<?= url('/booking/bookings/' . $b['id'] . '/cancel') ?>" class="inline" data-confirm="Cancel this booking?">
              <?= csrf_field() ?><button class="linklike">Cancel</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
