<?php
/** @var array $page @var array $days @var bool $hasSlots @var string $viewTz @var bool $tzRequested @var bool $tzResolved */
$zone = new DateTimeZone($viewTz);
$tzOptions = common_timezones();
foreach ([(string)$page['tz'], $viewTz] as $z) {
    if (!in_array($z, $tzOptions, true)) $tzOptions[] = $z;
}
// HEX_TAG|HEX_AMP: defense-in-depth so a value can never break out of the inline <script>.
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<?php if ($hasSlots && $tzRequested && $tzResolved): ?>
  <?php /* Landed on a resolved zone (manual pick OR the auto-redirect below). Remember it — keyed
          'tp-tz' to match the poll pages — so a later bare-link revisit reopens in it. An
          unresolvable ?tz fell back to page-tz and is deliberately NOT stored (degrades like detection). */ ?>
  <script>try{localStorage.setItem('tp-tz',<?= json_encode($viewTz, $jsonFlags) ?>);}catch(e){}</script>
<?php elseif ($hasSlots && !$tzRequested): $autoData = ['base' => url('/b/' . $page['public_token']), 'sep' => pretty_urls() ? '?' : '&', 'page' => (string)$page['tz']]; ?>
  <?php /* Pre-paint localization: no tz param yet, so pick the viewer's zone and hand off to the
          server round-trip BEFORE first paint. Prefer a remembered choice ('tp-tz') over fresh
          detection, and skip entirely when it already equals the page tz. location.replace leaves no
          history entry (Back not trapped) and pre-paint turns the reload into an ordinary redirect.
          Emitted only when the request had NO tz param, so a zone the server can't resolve renders as
          page-tz with no script and cannot loop. CSP allows inline script ('unsafe-inline'). */ ?>
  <script>(function(){try{if(/[?&]tz=/.test(location.search))return;var d=<?= json_encode($autoData, $jsonFlags) ?>;var z;try{z=localStorage.getItem('tp-tz');}catch(e){}if(!z)z=(Intl.DateTimeFormat().resolvedOptions()||{}).timeZone;if(z&&z!==d.page)location.replace(d.base+d.sep+'tz='+encodeURIComponent(z));}catch(e){}})();</script>
<?php endif; ?>
<div class="respond">
  <h1><?= e($page['title']) ?></h1>
  <p class="muted small"><?= (int)$page['duration_min'] ?>-minute meeting<?php if ($page['location']): ?> · 📍 <?= e($page['location']) ?><?php endif; ?></p>
  <?php if ($page['description']): ?><p class="lede"><?= nl2br(e($page['description'])) ?></p><?php endif; ?>

  <?php if (!empty($page['paused'])): ?>
    <div class="flash flash-error" role="status">This page is not currently accepting bookings. Please check back later.</div>
  <?php elseif (!$hasSlots): ?>
    <div class="card empty"><p class="muted">No times are available right now. Please check back later.</p></div>
  <?php else: ?>
    <div class="grid-toolbar">
      <h2>Pick a time</h2>
      <?php /* Server-rendered manual override: works without JS by round-tripping via GET. The
              select, chips, and day headers are always one consistent server-rendered zone. JS
              auto-detection is handled by the pre-paint script above, not here — so the two paths
              can never both fire. */ ?>
      <form method="get" action="<?= url('/b/' . $page['public_token']) ?>" class="tz-form">
        <?php if (!pretty_urls()): ?><input type="hidden" name="r" value="/b/<?= e($page['public_token']) ?>"><?php endif; ?>
        <label class="tz-pick muted small">Show times in
          <select name="tz" aria-label="Display timezone">
            <?php foreach ($tzOptions as $z): ?>
              <option value="<?= e($z) ?>" <?= $z === $viewTz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $z)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn-sm btn-ghost">Update</button>
      </form>
    </div>

    <form method="post" action="<?= url('/b/' . $page['public_token']) ?>" class="card stack" data-respond-form>
      <?= csrf_field() ?>
      <input type="hidden" name="tz" value="<?= e($viewTz) ?>">
      <div class="hp" aria-hidden="true">
        <label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div class="booking-days">
        <?php foreach ($days as $grp): ?>
          <div class="booking-day">
            <h3 class="booking-day-h"><?= e($grp['label']) ?></h3>
            <div class="slot-choices">
              <?php foreach ($grp['slots'] as $s):
                $rid = 't' . (int)$s['start_utc'];
                $checked = old('start') === (string)$s['start_utc'];
                $lbl = (new DateTime('@' . $s['start_utc']))->setTimezone($zone)->format('g:i A'); ?>
                <input type="radio" class="slot-radio" id="<?= $rid ?>" name="start" value="<?= (int)$s['start_utc'] ?>" <?= $checked ? 'checked' : '' ?> required>
                <?php /* Server-rendered in $viewTz — deliberately not js-time, so app.js can't rewrite a
                        chip out of sync with its day header or the select. Zone changes go through GET. */ ?>
                <label for="<?= $rid ?>" class="slot-chip"><time><?= e($lbl) ?></time></label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="row-2">
        <label>Your name
          <input type="text" name="name" value="<?= e(old('name')) ?>" required maxlength="80" placeholder="e.g. Alex Rivera" autocomplete="name">
        </label>
        <label>Email
          <input type="email" name="email" value="<?= e(old('email')) ?>" required maxlength="200" placeholder="you@example.org" autocomplete="email">
        </label>
      </div>
      <label>Note <span class="muted small">(optional)</span>
        <textarea name="note" rows="2" maxlength="500" placeholder="Anything the organizer should know?"><?= e(old('note')) ?></textarea>
      </label>

      <button type="submit" class="btn btn-block">Book this time</button>
      <p class="muted small center">No account needed. You'll get a link to manage your booking.</p>
    </form>
  <?php endif; ?>
</div>
