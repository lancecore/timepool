<?php /** @var int $code @var string $message */ ?>
<div class="card auth-card center">
  <h1 class="big"><?= e($code ?? 'Error') ?></h1>
  <p class="muted"><?= e($message ?? 'Something went wrong.') ?></p>
  <p><a class="btn" href="<?= url('/') ?>">Go home</a></p>
</div>
