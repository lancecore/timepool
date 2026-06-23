<?php /** @var array $polls @var array $me */ ?>
<div class="page-head">
  <h1>Your polls</h1>
  <a class="btn" href="<?= url('/polls/new') ?>">+ New poll</a>
</div>

<?php if (!$polls): ?>
  <div class="card empty">
    <h2>No polls yet</h2>
    <p class="muted">Create your first poll to start finding a time that works for everyone.</p>
    <a class="btn" href="<?= url('/polls/new') ?>">Create a poll</a>
  </div>
<?php else: ?>
  <ul class="poll-list">
    <?php foreach ($polls as $p):
      $slots = slots_for_poll((int)$p['id']);
      $count = response_count((int)$p['id']);
      $closed = poll_is_closed($p);
      $final = $p['final_slot_id'] ? slot_by_id((int)$p['final_slot_id']) : null;
    ?>
      <li class="card poll-row">
        <div class="poll-row-main">
          <a class="poll-title" href="<?= url('/polls/' . $p['id']) ?>"><?= e($p['title']) ?></a>
          <div class="poll-meta muted small">
            <span><?= count($slots) ?> slot<?= count($slots) === 1 ? '' : 's' ?></span> ·
            <span><?= $count ?> response<?= $count === 1 ? '' : 's' ?></span>
            <?php if ($final): ?> · <span class="pill pill-ok">Confirmed</span>
            <?php elseif ($closed): ?> · <span class="pill">Closed</span>
            <?php else: ?> · <span class="pill pill-live">Open</span><?php endif; ?>
          </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= url('/polls/' . $p['id']) ?>">Manage</a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
