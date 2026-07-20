<?php
/** @var ?array $page */
/** @var array $blocks (edit only) @var string $week @var array $weekWindows @var string $today (calendar edit only) */
$isEdit = !empty($page['id']);
$type = $isEdit ? (string)($page['type'] ?? 'weekly') : old('type', (string)($page['type'] ?? 'weekly'));
$isCalendar = $type === 'calendar';
$action = $isEdit ? url('/booking/' . $page['id'] . '/edit') : url('/booking/new');
$tz = $page['tz'] ?? setting('default_tz', 'UTC');
if ($tz === '') $tz = 'UTC';

// Availability may arrive as a JSON string (DB row) or an array (re-render after a
// validation error). Default a new page to Mon–Fri 09:00–17:00.
$availRaw = $page['availability'] ?? null;
if (is_array($availRaw)) {
    $avail = $availRaw;
} elseif ($isEdit || $availRaw !== null) {
    $avail = booking_availability($page);
} else {
    $avail = [1 => [['09:00', '17:00']], 2 => [['09:00', '17:00']], 3 => [['09:00', '17:00']], 4 => [['09:00', '17:00']], 5 => [['09:00', '17:00']]];
}

$dur = (int)($page['duration_min'] ?? 30);
$stdDur = in_array($dur, [15, 30, 45, 60], true);
$maxRanges = booking_max_ranges();
?>
<div class="page-head"><h1><?= $isEdit ? 'Edit booking page' : 'New booking page' ?></h1>
  <div class="head-actions">
    <?php if ($isEdit): ?><a class="btn btn-ghost btn-sm" href="<?= url('/booking/' . $page['id'] . '/calendar') ?>">Calendar</a><?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="<?= url('/booking') ?>">Back</a>
  </div>
</div>

<?php if ($isEdit): $link = absolute_url('/b/' . $page['public_token']); ?>
  <div class="card">
    <h2>Public booking link</h2>
    <p class="muted small">Share this with anyone — they don't need an account.</p>
    <div class="copy-row">
      <input type="text" readonly value="<?= e($link) ?>" data-copy-src aria-label="Public booking link">
      <button type="button" class="btn btn-sm" data-copy>Copy</button>
    </div>
    <p class="mt"><a href="<?= e($link) ?>" target="_blank" rel="noopener" class="muted small">Open the booking page ↗</a></p>
  </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="card stack" data-poll-form>
  <?= csrf_field() ?>

  <fieldset class="page-types">
    <legend>Page type</legend>
    <?php if ($isEdit): ?>
      <p class="muted small"><?= $isCalendar
        ? 'Calendar page — availability is placed on specific dates below.'
        : 'Weekly page — the same recurring availability every week.' ?>
        <span>(the type can't be changed after creation)</span></p>
    <?php else: ?>
      <label class="type-opt"><input type="radio" name="type" value="weekly" data-type-select <?= $isCalendar ? '' : 'checked' ?>>
        <span><strong>Weekly</strong> — the same times every week</span></label>
      <label class="type-opt"><input type="radio" name="type" value="calendar" data-type-select <?= $isCalendar ? 'checked' : '' ?>>
        <span><strong>Calendar</strong> — specific dates, placed week by week</span></label>
    <?php endif; ?>
  </fieldset>

  <label>Title
    <input type="text" name="title" value="<?= e(old('title', $page['title'] ?? '')) ?>" required maxlength="140" placeholder="e.g. Intro call" autofocus>
  </label>

  <label>Description <span class="muted small">(optional)</span>
    <textarea name="description" rows="2" maxlength="2000" placeholder="What's this meeting about?"><?= e(old('description', $page['description'] ?? '')) ?></textarea>
  </label>

  <div class="row-2">
    <label>Location <span class="muted small">(optional)</span>
      <input type="text" name="location" value="<?= e(old('location', $page['location'] ?? '')) ?>" maxlength="200" placeholder="Zoom link, phone, address…">
    </label>
    <label>Timezone
      <select name="tz" data-tz-select <?= $isEdit ? '' : 'data-autotz' ?>>
        <?php foreach (common_timezones() as $z): ?>
          <option value="<?= e($z) ?>" <?= $z === $tz ? 'selected' : '' ?>><?= e($z) ?></option>
        <?php endforeach; ?>
        <?php if (!in_array($tz, common_timezones(), true)): ?><option value="<?= e($tz) ?>" selected><?= e($tz) ?></option><?php endif; ?>
      </select>
    </label>
  </div>

  <div class="row-2">
    <label>Meeting duration
      <select name="duration">
        <?php foreach ([15 => '15 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '60 minutes'] as $m => $lbl): ?>
          <option value="<?= $m ?>" <?= ($stdDur && $dur === $m) ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
        <option value="custom" <?= $stdDur ? '' : 'selected' ?>>Custom (minutes) →</option>
      </select>
    </label>
    <label>Custom duration <span class="muted small">(minutes; used when "Custom" is selected)</span>
      <input type="number" name="duration_custom" min="1" max="1440" value="<?= e($stdDur ? '' : (string)$dur) ?>" placeholder="e.g. 25">
    </label>
  </div>

  <div class="row-2">
    <label>Minimum notice <span class="muted small">(hours)</span>
      <input type="number" name="min_notice_hours" min="0" max="8760" value="<?= e(old('min_notice_hours', (string)($page['min_notice_hours'] ?? 4))) ?>">
    </label>
    <label>Buffer <span class="muted small">(minutes)</span>
      <input type="number" name="buffer_min" min="0" max="1440" value="<?= e(old('buffer_min', (string)($page['buffer_min'] ?? 0))) ?>">
    </label>
  </div>

  <?php if (!$isEdit || !$isCalendar): ?>
    <div data-weekly-only<?= (!$isEdit && $isCalendar) ? ' hidden' : '' ?>>
      <fieldset class="slots">
        <legend>Weekly availability</legend>
        <p class="muted small">Weekly pages only. Times are in the page timezone above. Leave a day blank for no availability. Up to <?= $maxRanges ?> ranges per day.</p>
        <div class="avail-grid">
          <?php foreach (booking_weekdays() as $wd): $w = $wd['w']; $ranges = $avail[$w] ?? []; ?>
            <div class="avail-day">
              <span class="avail-label"><?= e($wd['label']) ?></span>
              <div class="avail-ranges">
                <?php for ($i = 0; $i < $maxRanges; $i++):
                  $s = $ranges[$i][0] ?? ''; $en = $ranges[$i][1] ?? ''; ?>
                  <span class="avail-range">
                    <input type="time" name="avail[<?= $w ?>][start][]" value="<?= e($s) ?>" aria-label="<?= e($wd['label']) ?> start">
                    <span class="avail-dash">–</span>
                    <input type="time" name="avail[<?= $w ?>][end][]" value="<?= e($en) ?>" aria-label="<?= e($wd['label']) ?> end">
                  </span>
                <?php endfor; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <label class="mt">Booking horizon <span class="muted small">(days ahead)</span>
        <input type="number" name="horizon_days" min="1" max="365" value="<?= e(old('horizon_days', (string)($page['horizon_days'] ?? 60))) ?>">
      </label>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create booking page' ?></button>
    <a class="btn btn-ghost" href="<?= url('/booking') ?>">Cancel</a>
  </div>
</form>

<?php if ($isEdit && $isCalendar):
  $weekDates = booking_week_dates($week);
  $prev = booking_shift_date($week, -7);
  $next = booking_shift_date($week, 7);
  $thisWeek = booking_week_monday($today);
  $weekLabel = (new DateTime($week . ' 00:00:00'))->format('M j, Y');
?>
<section class="card">
  <h2>Availability by date</h2>
  <p class="muted small">Place specific start–end times on specific dates, in the page timezone. Empty a date to clear it. Dates in the past can't be edited.</p>
  <div class="week-bar">
    <a class="btn btn-sm btn-ghost" href="<?= url('/booking/' . $page['id'] . '/edit?week=' . $prev) ?>">← Previous</a>
    <span class="week-label">Week of <?= e($weekLabel) ?></span>
    <a class="btn btn-sm btn-ghost" href="<?= url('/booking/' . $page['id'] . '/edit?week=' . $next) ?>">Next →</a>
  </div>
  <div class="week-tools">
    <a class="btn btn-sm btn-ghost" href="<?= url('/booking/' . $page['id'] . '/copy-week?week=' . $week) ?>">Copy previous week</a>
    <?php if ($week !== $thisWeek): ?><a class="btn btn-sm btn-ghost" href="<?= url('/booking/' . $page['id'] . '/edit?week=' . $thisWeek) ?>">This week</a><?php endif; ?>
  </div>

  <form method="post" action="<?= url('/booking/' . $page['id'] . '/windows') ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="week" value="<?= e($week) ?>">
    <div class="week-grid">
      <?php foreach ($weekDates as $d):
        $past = $d < $today;
        $ranges = $weekWindows[$d] ?? [];
        $dt = new DateTime($d . ' 00:00:00'); ?>
        <div class="week-day<?= $past ? ' is-past' : '' ?>">
          <div class="week-day-h"><?= e($dt->format('D')) ?> <span class="muted small"><?= e($dt->format('M j')) ?></span></div>
          <?php if ($past): ?>
            <p class="muted small week-past">Past</p>
          <?php else: for ($i = 0; $i < $maxRanges; $i++):
            $s = $ranges[$i][0] ?? ''; $en = $ranges[$i][1] ?? ''; ?>
            <div class="week-range">
              <input type="time" name="win[<?= e($d) ?>][start][]" value="<?= e($s) ?>" aria-label="<?= e($dt->format('M j')) ?> start <?= $i + 1 ?>">
              <input type="time" name="win[<?= e($d) ?>][end][]" value="<?= e($en) ?>" aria-label="<?= e($dt->format('M j')) ?> end <?= $i + 1 ?>">
            </div>
          <?php endfor; endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn">Save this week</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($isEdit): ?>
<section class="card">
  <h2>Blocked dates for this page</h2>
  <p class="muted small">These block only this page — your other pages still offer these dates. Organizer-wide days off (set on the Booking home) apply on top of these.</p>
  <form method="post" action="<?= url('/booking/' . $page['id'] . '/blocks') ?>" class="copy-row">
    <?= csrf_field() ?>
    <input type="date" name="day" aria-label="Block a date on this page" required>
    <button class="btn btn-sm btn-ghost">Block</button>
  </form>
  <?php if (!empty($blocks)): ?>
    <ul class="activity mt">
      <?php foreach ($blocks as $bl): ?>
        <li>
          <span><?= e((DateTime::createFromFormat('Y-m-d', $bl['day']) ?: new DateTime('now'))->format('l, M j, Y')) ?></span>
          <form method="post" action="<?= url('/booking/' . $page['id'] . '/blocks/' . $bl['id'] . '/delete') ?>" class="inline">
            <?= csrf_field() ?><button class="linklike">Unblock</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted small mt">No blocked dates on this page.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
