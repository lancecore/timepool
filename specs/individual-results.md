# Hide Individual Poll Results by Default

## Objective

On the public poll page (`/p/<token>`), the results grid currently shows every respondent's name, per-slot Yes/Maybe/No marks, and comment to anyone who can view results. Change this so **individual responses are hidden by default** — the public results show only per-slot tallies (totals, best-slot highlighting) — with a **per-poll option the organizer can turn on** to show individual responses again. The organizer always sees the full grid on their manage page.

## Background (verified against current code)

- `app/views/poll_respond.php` renders results via `render_grid()` (`app/view.php:51`), which prints one `<tbody>` row per participant (name, comment icon, per-slot marks) plus a `<tfoot>` totals row.
- `public_poll()` (`app/controllers/public.php:16`) loads `$participants` and `$responses` and passes them to the view; the existing per-poll `blind` flag empties them until the viewer has responded.
- The organizer manage page (`poll_manage.php:52`) calls the same `render_grid()`.
- Poll settings flow: `polls` table (`app/db.php`) → `collect_poll_data()` (`app/controllers/polls.php:35`) → `create_poll()`/`update_poll()`/`duplicate_poll()` (`app/poll.php`) → checkbox UI in `app/views/poll_form.php` "Advanced options".
- Migration pattern for adding a column to an existing table: PRAGMA-guarded `ALTER TABLE` (see `booking_pages.type`, `app/db.php:173-182`).

## Requirements

1. **Schema.** Add `show_individual INTEGER NOT NULL DEFAULT 0` to the `polls` table: in the `CREATE TABLE IF NOT EXISTS polls (...)` statement for fresh installs, **and** a PRAGMA-guarded `ALTER TABLE` (exact same pattern as `booking_pages.type`) so existing installs upgrade with zero steps. Default `0` means all existing polls and all new polls hide individual responses until an organizer opts in.
2. **Per-poll option.** A checkbox in the poll form's "Advanced options" section, next to the existing "Hidden responses" (blind) checkbox: name `show_individual`, label along the lines of "Show individual responses — participants can see each person's name and answers, not just the totals." Unchecked by default on new polls; reflects the stored value on edit. The `<details class="adv">` open-condition includes this flag so editing a poll with it on shows the section expanded.
3. **Persistence.** `collect_poll_data()` reads the checkbox (same `param(...) ? 1 : 0` idiom as `blind`); `create_poll()` and `update_poll()` write the column; `duplicate_poll()` carries the value to the copy.
4. **Public page behavior.** In `public_poll()`, when `show_individual` is `0`, do not pass **other participants'** identities or per-person responses to the view — `$participants = []`, and `$responses` limited to at most the current viewer's own row (defense in depth: hidden data never reaches the template). The tally is still computed and passed, so `render_grid()` renders the slot header row and the totals row with best-slot highlighting, just no participant rows. No extra explanatory note is shown; the totals-only grid (with its accurate caption/header, below) speaks for itself.
   **Viewer's own pre-fill must keep working:** `poll_respond.php:4` builds `$viewerChoices` from `$responses[$viewer['id']]` to pre-select the returning participant's saved Yes/Maybe/No radios. When hiding rows and a `$viewer` exists (valid edit token), `$responses` must still contain that one participant's own choices — `[viewer_id => [slot_id => choice]]` — and nothing else. `$participants` stays empty, so `render_grid()` never renders the viewer's row in the grid; the data feeds only the form pre-fill. (Without this, a returning editor sees blank radios and can silently wipe their answers to all-No — a regression over current behavior, where responses are always loaded whenever a viewer exists.)
   **Testable choke point:** the participants/responses assembly (blind gate + individual-hiding + viewer carve-out) lives in one small named function in `app/controllers/public.php` — signature along the lines of `public_poll_rows(array $poll, ?array $viewer): array` returning `[$participants, $responses]` — which `public_poll()` calls. Tests exercise that real function, never a mirrored copy of its logic.
   **Accurate empty-grid semantics:** when `render_grid()` receives an empty `$participants`, the first column header must not read "Participant" (render it empty) and the sr-only `<caption>` must describe per-slot totals rather than claiming "Rows are participants". When participants are present, header and caption stay exactly as today. This also improves the organizer's view of a poll with zero responses.
5. **Blind interaction unchanged.** The existing `blind` behavior is untouched and composes: blind + not yet responded → results fully hidden (current message); after responding (or blind off) → totals-only when `show_individual` is 0, full grid when 1. The blind message text stays as is.
6. **Organizer unaffected.** `poll_manage()` (admin page) continues to show the full grid with names regardless of the flag. No change to that code path.
7. **No other leak paths.** Individual names/choices must not be exposed on any other public route. (Verified in current code: the activity feed and invites render only on the organizer page; the only public renderer of responses is `poll_respond.php`.) The change must not add any new public output of participant data when the flag is 0.
8. **Tests.** Extend `php tests/run.php` (same `ok()` style, appended in a clearly labelled section):
   - New poll defaults to `show_individual = 0`; a poll created with `show_individual = 1` stores 1.
   - `update_poll()` flips the flag both ways; `duplicate_poll()` carries it.
   - Migration: simulate a legacy install (create a `polls` table without the column in a fresh temp DB, or drop/recreate), run `migrate()`, confirm the column exists with default 0 and re-running `migrate()` is a no-op (mirror the existing `booking_pages.type` migration tests).
   - Rendering: include `app/view.php`, capture `render_grid()` output with an empty `$participants` (the hidden case) and assert it contains the totals but **no participant name**, that the header cell does not read "Participant", and that the caption does not claim rows are participants; capture with real participants and assert the name, the "Participant" header, and the original caption all appear. Guard: participant name string must not appear anywhere in the hidden-case output.
   - Returning-viewer pre-fill: with `show_individual = 0`, call the real assembly function (`public_poll_rows()` or equivalent) for a viewer with a valid edit token, and assert `$participants` is empty and `$responses` contains exactly that viewer's own choices (their saved `yes` present) and no other participant's entry; call it with `$viewer = null` and assert both come back empty; call it on a `show_individual = 1` poll and assert full participants/responses.
   - Entire suite (old + new) passes: `php tests/run.php` → 0 failed.

## Out of Scope

- Any change to the `blind` feature's semantics or copy.
- Hiding comments separately (comments live on participant rows; they hide/show with them).
- Per-participant self-row visibility ("show only my row") — the respondent sees their own choices in the pre-filled form already.
- Any change to the 1:1 booking feature, organizer pages, emails, or the installer.
- An install-wide default setting; the toggle is per-poll only.

## Constraints

- Match existing architecture exactly: function-based controllers, model functions in `app/poll.php`, schema in `app/db.php` `migrate()`, views in `app/views/`, tests appended to `tests/run.php`.
- **PHP 7.4-compatible syntax** (local CLI/tests run PHP 7.4; no PHP 8-only features: no `match`, named args, constructor promotion, `str_contains`, etc.). Follow the file's existing idioms.
- All new output escaped with `e()`; files stay under 500 lines; no new dependencies; no changes to poll behavior other than described.
- Keep the diff minimal: reuse `render_grid()` with no new parameters and no second renderer; the only permitted change to it is the empty-`$participants` cosmetic branch required by Requirement 4 (header cell + caption), keyed off the already-passed `$participants` argument.

## Edge Cases

- **Legacy DB upgrade:** an existing install's `polls` table lacks the column → first request adds it via the guarded ALTER; re-running `migrate()` is a no-op; existing polls behave as `show_individual = 0` (hidden).
- **Blind + hidden combined:** non-responder on a blind poll sees the current "hidden until you submit" card (no totals either); after submitting they see totals-only.
- **Zero responses:** totals-only view with all-zero tallies renders without errors or weird empty-state (the existing "No time slots yet" / totals row handles it).
- **Validation-failure re-render:** poll form re-renders with the checkbox state preserved (it reads from the merged `$poll` array like `blind` does).
- **Duplicate poll:** copy keeps the source poll's setting.
- **Returning editor, flag 0:** opening the poll via a saved edit link pre-selects their previously saved radios exactly as before this change; submitting without touching anything preserves their answers.
- **Finalized/closed polls:** totals-only view still renders on the public page exactly like an open poll's results.

## Definition of Done

- [ ] `php tests/run.php` passes with 0 failures, including all new tests listed in Requirement 8, on PHP 7.4.
- [ ] Fresh DB: creating a poll and visiting `/p/<token>` after two participants respond shows a results table with slot headers + totals row, and **no participant names anywhere in the HTML**.
- [ ] Turning the new checkbox on (edit poll) makes the same page show the full grid with names.
- [ ] A returning participant (edit link) on a flag-0 poll sees their saved choices pre-selected in the form.
- [ ] A blind poll still hides everything from a non-responder, flag regardless.
- [ ] Organizer manage page shows names in every combination of the two flags.
- [ ] Legacy-DB migration test proves zero-step upgrade.
- [ ] `php -l` clean on every touched file; no view/controller file crosses 500 lines.
