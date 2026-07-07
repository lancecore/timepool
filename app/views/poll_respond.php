<?php
/** @var array $poll @var array $slots @var ?array $viewer @var bool $blindHide @var bool $closed
 *  @var array $participants @var array $responses @var array $tally @var ?array $final */
$viewerChoices = $viewer ? ($responses[(int)$viewer['id']] ?? []) : [];
$showForm = !$closed && !$final;
?>
<div class="respond">
  <h1><?= e($poll['title']) ?></h1>
  <?php if ($poll['description']): ?><p class="lede"><?= nl2br(e($poll['description'])) ?></p><?php endif; ?>
  <?php if ($poll['location']): ?><p class="muted small">📍 <?= e($poll['location']) ?></p><?php endif; ?>

  <?php if ($final): ?>
    <div class="card confirmed">
      <h2>✓ Confirmed time</h2>
      <p class="confirmed-when"><time class="js-time"<?= time_attr($final) ?>><?= e(slot_label($final, $poll['organizer_tz'])) ?></time></p>
      <div class="cal-links">
        <a class="btn btn-sm" href="<?= e(gcal_link($poll, $final)) ?>" target="_blank" rel="noopener">Add to Google</a>
        <a class="btn btn-sm" href="<?= e(outlook_link($poll, $final)) ?>" target="_blank" rel="noopener">Add to Outlook</a>
        <a class="btn btn-sm" href="<?= e(url('/p/' . $poll['public_token'] . '/ics?slot=final')) ?>">Apple / .ics</a>
      </div>
    </div>
  <?php elseif ($closed): ?>
    <div class="flash flash-error" role="status">This poll is closed and no longer accepts responses.</div>
  <?php endif; ?>

  <?php if ($showForm): ?>
    <form method="post" action="<?= url('/p/' . $poll['public_token']) ?>" class="card stack" data-respond-form>
      <?= csrf_field() ?>
      <?php if ($viewer): ?><p class="flash flash-success" role="status">You're editing your saved response.</p><?php endif; ?>

      <label>Your name
        <input type="text" name="name" value="<?= e(old('name', $viewer['name'] ?? '')) ?>" required maxlength="80" placeholder="e.g. Alex Rivera" autocomplete="name">
      </label>

      <div class="hp" aria-hidden="true">
        <label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div class="grid-toolbar">
        <h2>Which times work for you?</h2>
        <?php include __DIR__ . '/_tzpicker.php'; ?>
      </div>

      <div class="resp-slots">
        <?php foreach ($slots as $s): $cur = old('slot_' . (int)$s['id'], $viewerChoices[(int)$s['id']] ?? ''); ?>
          <div class="resp-slot">
            <div class="resp-when"><time class="js-time"<?= time_attr($s) ?>><?= e(slot_label($s, $poll['organizer_tz'])) ?></time></div>
            <div class="seg" role="radiogroup" aria-label="Availability for <?= e(slot_label($s, $poll['organizer_tz'])) ?>">
              <?php foreach (['yes' => 'Yes', 'maybe' => 'If need be', 'no' => 'No'] as $val => $lbl): ?>
                <input type="radio" id="s<?= (int)$s['id'] ?>_<?= $val ?>" name="slot_<?= (int)$s['id'] ?>" value="<?= $val ?>" <?= $cur === $val ? 'checked' : '' ?>>
                <label for="s<?= (int)$s['id'] ?>_<?= $val ?>" class="seg-<?= $val ?>"><?= $lbl ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <label>Comment <span class="muted small">(optional)</span>
        <textarea name="comment" rows="2" maxlength="500" placeholder="Anything the organizer should know?"><?= e(old('comment', $viewer['comment'] ?? '')) ?></textarea>
      </label>

      <button type="submit" class="btn btn-block"><?= $viewer ? 'Update my response' : 'Submit availability' ?></button>
      <p class="muted small center">No account needed. Bookmark this page to edit your answer later.</p>
    </form>
  <?php endif; ?>

  <div class="grid-toolbar mt">
    <h2>Results</h2>
    <?php if (!$blindHide): ?><?php include __DIR__ . '/_tzpicker.php'; ?><?php endif; ?>
  </div>
  <?php if ($blindHide): ?>
    <div class="card empty"><p class="muted">Responses are hidden until you submit yours. Add your availability above to reveal the results.</p></div>
  <?php else: ?>
    <?php render_grid($poll, $slots, $participants, $responses, $tally); ?>
  <?php endif; ?>
</div>
