<?php
/** @var array $poll @var array $slots @var array $participants @var array $responses @var array $tally @var array $activity @var array $invites */
$closed = poll_is_closed($poll);
$final = $poll['final_slot_id'] ? slot_by_id((int)$poll['final_slot_id']) : null;
$publicUrl = absolute_url('/p/' . $poll['public_token']);
?>
<div class="page-head">
  <div>
    <h1><?= e($poll['title']) ?></h1>
    <p class="poll-meta muted small">
      <?php if ($final): ?><span class="pill pill-ok">Confirmed</span>
      <?php elseif ($closed): ?><span class="pill">Closed</span>
      <?php else: ?><span class="pill pill-live">Open</span><?php endif; ?>
      · <?= $tally['total'] ?> response<?= $tally['total'] === 1 ? '' : 's' ?>
    </p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost btn-sm" href="<?= url('/polls/' . $poll['id'] . '/edit') ?>">Edit</a>
    <form method="post" action="<?= url('/polls/' . $poll['id'] . '/duplicate') ?>" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Duplicate</button></form>
    <form method="post" action="<?= url('/polls/' . $poll['id'] . '/close') ?>" class="inline">
      <?= csrf_field() ?><input type="hidden" name="closed" value="<?= $poll['closed'] ? '0' : '1' ?>">
      <button class="btn btn-ghost btn-sm"><?= $poll['closed'] ? 'Reopen' : 'Close' ?></button>
    </form>
    <form method="post" action="<?= url('/polls/' . $poll['id'] . '/delete') ?>" class="inline" data-confirm="Delete this poll and all its responses? This cannot be undone.">
      <?= csrf_field() ?><button class="btn btn-ghost btn-sm btn-danger">Delete</button>
    </form>
  </div>
</div>

<?php if ($poll['description']): ?><p class="lede"><?= nl2br(e($poll['description'])) ?></p><?php endif; ?>

<?php if ($final): ?>
  <div class="card confirmed">
    <h2>✓ Confirmed time</h2>
    <p class="confirmed-when"><time class="js-time"<?= time_attr($final) ?>><?= e(slot_label($final, $poll['organizer_tz'])) ?></time></p>
    <div class="cal-links">
      <a class="btn btn-sm" href="<?= e(gcal_link($poll, $final)) ?>" target="_blank" rel="noopener">Add to Google</a>
      <a class="btn btn-sm" href="<?= e(outlook_link($poll, $final)) ?>" target="_blank" rel="noopener">Add to Outlook</a>
      <a class="btn btn-sm" href="<?= e(url('/p/' . $poll['public_token'] . '/ics?slot=final')) ?>">Apple / .ics</a>
    </div>
    <form method="post" action="<?= url('/polls/' . $poll['id'] . '/finalize') ?>" class="inline mt">
      <?= csrf_field() ?><input type="hidden" name="slot_id" value="0">
      <button class="linklike">Clear final time</button>
    </form>
  </div>
<?php endif; ?>

<div class="grid-toolbar">
  <h2>Responses</h2>
  <?php include __DIR__ . '/_tzpicker.php'; ?>
</div>
<?php render_grid($poll, $slots, $participants, $responses, $tally); ?>

<?php if (!$final && $slots): ?>
  <form method="post" action="<?= url('/polls/' . $poll['id'] . '/finalize') ?>" class="card finalize stack">
    <?= csrf_field() ?>
    <h2>Pick the final time</h2>
    <label>Winning slot
      <select name="slot_id">
        <?php foreach ($slots as $s): $isBest = in_array((int)$s['id'], $tally['best'], true); ?>
          <option value="<?= (int)$s['id'] ?>" <?= $isBest ? 'selected' : '' ?>>
            <?= e(slot_label($s, $poll['organizer_tz'])) ?> — <?= $tally['counts'][(int)$s['id']]['yes'] ?> yes<?= $isBest ? ' ★' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn">Set final time &amp; notify</button>
  </form>
<?php endif; ?>

<div class="cols">
  <section class="card">
    <h2>Share</h2>
    <p class="muted small">Send this link to anyone — they don't need an account.</p>
    <div class="copy-row">
      <input type="text" readonly value="<?= e($publicUrl) ?>" data-copy-src aria-label="Public link">
      <button type="button" class="btn btn-sm" data-copy>Copy</button>
    </div>
    <p class="mt"><a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="muted small">Open the participant view ↗</a></p>

    <h3 class="mt">Invite by email</h3>
    <?php if (!mailer_configured()): ?><p class="muted small">Email isn't set up, so invites are saved but not sent — share the link above instead.</p><?php endif; ?>
    <form method="post" action="<?= url('/polls/' . $poll['id'] . '/invite') ?>" class="stack">
      <?= csrf_field() ?>
      <textarea name="emails" rows="2" placeholder="alice@example.org, bob@example.org"></textarea>
      <button class="btn btn-sm btn-ghost"><?= mailer_configured() ? 'Send invites' : 'Save invites' ?></button>
    </form>
    <?php if ($invites): ?>
      <p class="muted small mt"><?= count($invites) ?> invited: <?= e(implode(', ', array_slice(array_column($invites, 'email'), 0, 8))) ?><?= count($invites) > 8 ? '…' : '' ?></p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Activity</h2>
    <?php if (!$activity): ?><p class="muted small">No activity yet.</p><?php else: ?>
      <ul class="activity">
        <?php foreach ($activity as $a): ?>
          <li><span><?= e($a['message']) ?></span><time class="muted small" datetime="<?= gmdate('c', (int)$a['created_at']) ?>"><?= e(gmdate('M j, g:i a', (int)$a['created_at'])) ?> UTC</time></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
