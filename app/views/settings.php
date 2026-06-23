<?php /** Settings (admin) */
$tz = setting('default_tz', 'UTC');
$hasLogo = (string)setting('logo_file', '') !== '';
?>
<div class="page-head"><h1>Settings</h1></div>

<form method="post" action="<?= url('/settings') ?>" enctype="multipart/form-data" class="card stack">
  <?= csrf_field() ?>

  <h2>Branding</h2>
  <div class="row-2">
    <label>Organization name
      <input type="text" name="org_name" value="<?= e(setting('org_name', 'Meeting Poll')) ?>" maxlength="80">
    </label>
    <label>Accent color
      <input type="color" name="accent" value="<?= e(accent()) ?>">
    </label>
  </div>
  <label>Logo <span class="muted small">(PNG, JPG, GIF or WEBP)</span>
    <input type="file" name="logo" accept="image/*">
  </label>
  <?php if ($hasLogo): ?><p class="muted small">Current: <img src="<?= url('/logo') ?>" alt="current logo" style="height:28px;vertical-align:middle"></p><?php endif; ?>

  <h2 class="mt">Defaults</h2>
  <div class="row-2">
    <label>Default timezone
      <select name="default_tz" data-tz-select>
        <?php foreach (common_timezones() as $z): ?><option value="<?= e($z) ?>" <?= $z === $tz ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
        <?php if (!in_array($tz, common_timezones(), true)): ?><option value="<?= e($tz) ?>" selected><?= e($tz) ?></option><?php endif; ?>
      </select>
    </label>
    <label>Max responses per poll
      <input type="number" name="max_participants" min="1" max="100000" value="<?= e(setting('max_participants', '500')) ?>">
    </label>
  </div>

  <h2 class="mt">Email (optional)</h2>
  <p class="muted small">The app works without email. Configure SMTP to send invites, response alerts, and confirmations.</p>
  <div class="row-2">
    <label>SMTP host <input type="text" name="smtp_host" value="<?= e(setting('smtp_host', '')) ?>" placeholder="smtp.example.org"></label>
    <label>Port <input type="number" name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>"></label>
  </div>
  <div class="row-2">
    <label>Username <input type="text" name="smtp_user" value="<?= e(setting('smtp_user', '')) ?>" autocomplete="off"></label>
    <label>Password <input type="password" name="smtp_pass" placeholder="<?= setting('smtp_pass') ? '•••••• (unchanged)' : '' ?>" autocomplete="new-password"></label>
  </div>
  <div class="row-2">
    <label>Encryption
      <select name="smtp_secure">
        <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= setting('smtp_secure', 'tls') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>From address <input type="email" name="smtp_from" value="<?= e(setting('smtp_from', '')) ?>" placeholder="noreply@example.org"></label>
  </div>
  <label>Send a test email to <span class="muted small">(optional — leave blank to just save)</span>
    <input type="email" name="test_email" placeholder="you@example.org" autocomplete="off">
  </label>

  <div class="form-actions"><button class="btn">Save settings</button></div>
</form>
