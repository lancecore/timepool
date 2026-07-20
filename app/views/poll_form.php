<?php
/** @var ?array $poll @var array $slotRows */
$isEdit = !empty($poll['id']);
$action = $isEdit ? url('/polls/' . $poll['id'] . '/edit') : url('/polls/new');
$tz = $poll['organizer_tz'] ?? setting('default_tz', 'UTC');
$deadlineDate = $deadlineTime = '';
if (!empty($poll['deadline_utc'])) {
    $d = (new DateTime('@' . $poll['deadline_utc']))->setTimezone(new DateTimeZone($tz));
    $deadlineDate = $d->format('Y-m-d'); $deadlineTime = $d->format('H:i');
}
?>
<div class="page-head"><h1><?= $isEdit ? 'Edit poll' : 'New poll' ?></h1></div>

<form method="post" action="<?= e($action) ?>" class="card stack form-poll" data-poll-form data-default-tz="<?= e(setting('default_tz', 'UTC')) ?>">
  <?= csrf_field() ?>

  <label>Title
    <input type="text" name="title" value="<?= e($poll['title'] ?? '') ?>" required maxlength="140" placeholder="e.g. Q3 Volunteer Planning" autofocus>
  </label>

  <label>Description <span class="muted small">(optional)</span>
    <textarea name="description" rows="2" maxlength="2000" placeholder="What's this meeting about?"><?= e($poll['description'] ?? '') ?></textarea>
  </label>

  <div class="row-2">
    <label>Location <span class="muted small">(optional)</span>
      <input type="text" name="location" value="<?= e($poll['location'] ?? '') ?>" maxlength="200" placeholder="Zoom link, room, address…">
    </label>
    <label>Your timezone
      <select name="organizer_tz" data-tz-select <?= $isEdit ? '' : 'data-autotz' ?>>
        <?php foreach (common_timezones() as $z): ?>
          <option value="<?= e($z) ?>" <?= $z === $tz ? 'selected' : '' ?>><?= e($z) ?></option>
        <?php endforeach; ?>
        <?php if (!in_array($tz, common_timezones(), true)): ?><option value="<?= e($tz) ?>" selected><?= e($tz) ?></option><?php endif; ?>
      </select>
    </label>
  </div>

  <fieldset class="slots">
    <legend>Proposed times</legend>
    <p class="muted small">Add the time options people will vote on. Times are entered in your timezone above; participants see them converted to theirs.</p>
    <div data-slot-list class="slot-list">
      <?php $rows = $slotRows ?: [['kind' => 'datetime', 'date' => '', 'time' => '', 'duration' => 60]];
      foreach ($rows as $r): ?>
        <div class="slot-row" data-slot-row>
          <select name="slot_kind[]" data-slot-kind aria-label="Slot type">
            <option value="datetime" <?= ($r['kind'] ?? '') !== 'date' ? 'selected' : '' ?>>Time</option>
            <option value="date" <?= ($r['kind'] ?? '') === 'date' ? 'selected' : '' ?>>All day</option>
          </select>
          <input type="date" name="slot_date[]" value="<?= e($r['date'] ?? '') ?>" aria-label="Date">
          <input type="time" name="slot_time[]" value="<?= e($r['time'] ?? '') ?>" aria-label="Time" <?= ($r['kind'] ?? '') === 'date' ? 'hidden' : '' ?>>
          <select name="slot_dur[]" aria-label="Duration" <?= ($r['kind'] ?? '') === 'date' ? 'hidden' : '' ?>>
            <?php foreach ([15 => '15m', 30 => '30m', 60 => '1h', 90 => '1.5h', 120 => '2h', 180 => '3h'] as $m => $lbl): ?>
              <option value="<?= $m ?>" <?= (int)($r['duration'] ?? 60) === $m ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="icon-btn" data-slot-remove aria-label="Remove time">✕</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" data-slot-add>+ Add time</button>
  </fieldset>

  <details class="adv" <?= ($isEdit && (!empty($poll['blind']) || !empty($poll['show_individual']) || $deadlineDate)) ? 'open' : '' ?>>
    <summary>Advanced options</summary>
    <div class="stack">
      <label class="check">
        <input type="checkbox" name="blind" value="1" <?= !empty($poll['blind']) ? 'checked' : '' ?>>
        <span>Hidden responses — participants can't see others' answers until they submit their own.</span>
      </label>
      <label class="check">
        <input type="checkbox" name="show_individual" value="1" <?= !empty($poll['show_individual']) ? 'checked' : '' ?>>
        <span>Show individual responses — participants can see each person's name and answers, not just the totals.</span>
      </label>
      <div class="row-2">
        <label>Response deadline <span class="muted small">(optional)</span>
          <input type="date" name="deadline_date" value="<?= e($deadlineDate) ?>">
        </label>
        <label>Deadline time
          <input type="time" name="deadline_time" value="<?= e($deadlineTime) ?>">
        </label>
      </div>
      <p class="muted small">After the deadline the poll closes automatically.</p>
    </div>
  </details>

  <div class="form-actions">
    <button type="submit" class="btn"><?= $isEdit ? 'Save changes' : 'Create poll' ?></button>
    <a class="btn btn-ghost" href="<?= $isEdit ? url('/polls/' . $poll['id']) : url('/dashboard') ?>">Cancel</a>
  </div>
</form>

<template data-slot-template>
  <div class="slot-row" data-slot-row>
    <select name="slot_kind[]" data-slot-kind aria-label="Slot type">
      <option value="datetime" selected>Time</option>
      <option value="date">All day</option>
    </select>
    <input type="date" name="slot_date[]" aria-label="Date">
    <input type="time" name="slot_time[]" aria-label="Time">
    <select name="slot_dur[]" aria-label="Duration">
      <option value="15">15m</option><option value="30">30m</option>
      <option value="60" selected>1h</option><option value="90">1.5h</option>
      <option value="120">2h</option><option value="180">3h</option>
    </select>
    <button type="button" class="icon-btn" data-slot-remove aria-label="Remove time">✕</button>
  </div>
</template>
