<?php /** @var string $tail */
$issueBody = "**What happened?**\n\nDescribe what you were doing when the error appeared.\n\n"
    . "**Environment**\n- PHP " . PHP_VERSION . "\n\n"
    . "**Error log excerpt**\n\n```\nPaste the copied log lines here.\n```\n";
$issueUrl = 'https://github.com/lancecore/timepool/issues/new?body=' . rawurlencode($issueBody);
?>
<div class="page-head">
  <h1>Error log</h1>
  <div class="head-actions">
    <a class="btn btn-sm" href="<?= e($issueUrl) ?>" target="_blank" rel="noopener">Report an issue on GitHub</a>
  </div>
</div>

<?php if ($tail === ''): ?>
  <div class="card empty">
    <h2>No errors logged</h2>
    <p class="muted">When something goes wrong, the details are recorded here so you can include them in a bug report.</p>
  </div>
<?php else: ?>
  <div class="card stack">
    <p class="muted small">Newest entries are at the bottom. Copy the relevant lines into a GitHub issue —
      review them first, as they can include server file paths.</p>
    <textarea class="log" data-copy-src readonly rows="16" aria-label="Error log contents"><?= e($tail) ?></textarea>
    <div class="form-actions">
      <button type="button" class="btn btn-sm" data-copy>Copy log</button>
      <form method="post" action="<?= url('/errors/clear') ?>" class="inline" data-confirm="Clear the error log?">
        <?= csrf_field() ?><button class="btn btn-ghost btn-sm">Clear log</button>
      </form>
    </div>
  </div>
<?php endif; ?>
