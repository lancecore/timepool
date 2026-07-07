<?php
declare(strict_types=1);

/** Render a view inside a layout. */
function view(string $name, array $data = [], string $layout = 'layout'): void {
    $data['__route'] = current_route();
    extract($data, EXTR_SKIP);
    ob_start();
    include APP_DIR . "/views/$name.php";
    $content = ob_get_clean();
    $title = isset($data['title']) ? ($data['title'] . ' · ' . setting('org_name', 'TimePool')) : setting('org_name', 'TimePool');
    include APP_DIR . "/views/{$layout}.php";
}

/** Accent colour chosen at install/settings, applied as a CSS variable. */
function accent(): string {
    $c = (string)setting('accent', '#4f46e5');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#4f46e5';
}

/** Org logo if set, else an accent-colored check mark. */
function brandmark(): string {
    $logo = (string)setting('logo_file', '');
    if ($logo !== '') return '<img src="' . e(url('/logo')) . '" alt="" height="48">';
    return '<svg viewBox="0 0 32 32" width="48" height="48" aria-hidden="true">'
        . '<rect width="32" height="32" rx="7" fill="' . e(accent()) . '"/>'
        . '<path d="M8 17l5 5 11-12" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/** data-* attributes so app.js can re-render a time in the viewer's timezone. */
function time_attr(array $slot): string {
    if ($slot['kind'] === 'date') return ' data-date="' . e($slot['date']) . '"';
    return ' data-utc="' . ((int)$slot['start_utc'] * 1000) . '" data-dur="' . (int)$slot['duration_min'] . '"';
}

function choice_class(string $c): string {
    return ['yes' => 'c-yes', 'maybe' => 'c-maybe', 'no' => 'c-no'][$c] ?? 'c-no';
}
function choice_mark(string $c): string {
    return ['yes' => '✓', 'maybe' => '~', 'no' => '✕'][$c] ?? '✕';
}

/** Shared availability grid (organizer view + post-response results). */
function render_grid(array $poll, array $slots, array $participants, array $responses, array $tally): void {
    if (!$slots) { echo '<p class="muted">No time slots yet.</p>'; return; }
    $best = $tally['best'];
    $counts = $tally['counts'];
    ?>
    <div class="grid-scroll">
      <table class="grid">
        <caption class="sr-only">Availability grid. Rows are participants, columns are proposed times. ✓ means yes, ~ means if need be, ✕ means no.</caption>
        <thead>
          <tr>
            <th scope="col" class="grid-name">Participant</th>
            <?php foreach ($slots as $s): $isBest = in_array((int)$s['id'], $best, true); ?>
              <th scope="col" class="<?= $isBest ? 'is-best' : '' ?>">
                <time class="js-time"<?= time_attr($s) ?>><?= e(slot_label($s, $poll['organizer_tz'])) ?></time>
                <?php if ($isBest): ?><span class="best-badge" title="Leading time">★</span><?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($participants as $p): ?>
            <tr>
              <th scope="row" class="grid-name"><?= e($p['name']) ?>
                <?php if (!empty($p['comment'])): ?><span class="cmt" title="<?= e($p['comment']) ?>">💬</span><?php endif; ?>
              </th>
              <?php foreach ($slots as $s): $c = $responses[(int)$p['id']][(int)$s['id']] ?? 'no'; ?>
                <td class="cell <?= choice_class($c) ?>"><span aria-label="<?= e($c) ?>"><?= choice_mark($c) ?></span></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" class="grid-name">Totals</th>
            <?php foreach ($slots as $s): $c = $counts[(int)$s['id']]; $isBest = in_array((int)$s['id'], $best, true); ?>
              <td class="totals <?= $isBest ? 'is-best' : '' ?>">
                <span class="t-yes" title="Yes"><?= $c['yes'] ?></span><?php if ($c['maybe']): ?> <span class="t-maybe" title="If need be">(<?= $c['maybe'] ?>)</span><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php
}
