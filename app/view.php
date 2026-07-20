<?php
declare(strict_types=1);

/** Render a view inside a layout. */
function view(string $name, array $data = [], string $layout = 'layout'): void {
    $data['__route'] = current_route();
    extract($data, EXTR_SKIP);
    ob_start();
    include APP_DIR . "/views/$name.php";
    $content = ob_get_clean();
    unset($_SESSION['old_input']); // old() stash is one-shot: consumed by this render
    $title = isset($data['title']) ? ($data['title'] . ' · ' . setting('org_name', 'TimePool')) : setting('org_name', 'TimePool');
    include APP_DIR . "/views/{$layout}.php";
}

/** Accent colour chosen at install/settings, applied as a CSS variable. */
function accent(): string {
    $c = (string)setting('accent', '#4f46e5');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#4f46e5';
}

/** Versioned logo URL: a replaced logo shows instantly despite long-lived caching. */
function logo_url(): string {
    $path = DATA_DIR . '/uploads/' . basename((string)setting('logo_file', ''));
    return url('/logo?v=' . (is_file($path) ? filemtime($path) : 0));
}

/** Org logo if set, else an accent-colored check mark. */
function brandmark(): string {
    $logo = (string)setting('logo_file', '');
    if ($logo !== '') return '<img src="' . e(logo_url()) . '" alt="" height="48">';
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
    // Transposed: one row per proposed time, so results grow down the page instead of sideways.
    // With no participants (results hidden, or zero responses) it's a two-column totals list.
    $hasRows = !empty($participants);
    ?>
    <div class="grid-scroll">
      <table class="grid">
        <caption class="sr-only"><?= $hasRows ? 'Availability grid. Rows are proposed times, columns are participants. ✓ means yes, ~ means if need be, ✕ means no.' : 'Availability totals. Rows are proposed times; the Totals column tallies responses.' ?></caption>
        <thead>
          <tr>
            <th scope="col" class="grid-name">Time</th>
            <?php foreach ($participants as $p): ?>
              <th scope="col"><?= e($p['name']) ?><?php if (!empty($p['comment'])): ?> <span class="cmt" title="<?= e($p['comment']) ?>">💬</span><?php endif; ?></th>
            <?php endforeach; ?>
            <th scope="col">Totals</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($slots as $s): $sid = (int)$s['id']; $isBest = in_array($sid, $best, true); $c = $counts[$sid]; ?>
            <tr<?= $isBest ? ' class="is-best"' : '' ?>>
              <th scope="row" class="grid-name">
                <time class="js-time"<?= time_attr($s) ?>><?= e(slot_label($s, $poll['organizer_tz'])) ?></time>
                <?php if ($isBest): ?><span class="best-badge" title="Leading time">★</span><?php endif; ?>
              </th>
              <?php foreach ($participants as $p): $ch = $responses[(int)$p['id']][$sid] ?? 'no'; ?>
                <td class="cell <?= choice_class($ch) ?>"><span aria-label="<?= e($ch) ?>"><?= choice_mark($ch) ?></span></td>
              <?php endforeach; ?>
              <td class="totals">
                <span class="t-yes" title="Yes"><?= $c['yes'] ?></span><?php if ($c['maybe']): ?> <span class="t-maybe" title="If need be">(<?= $c['maybe'] ?>)</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}
