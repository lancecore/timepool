# TimePool Booking — Calendar Pages & Per-Page Blocked Dates

## Objective

Add a second booking page type to TimePool's 1:1 booking: **calendar pages**, where availability is placed on specific dates (week by week) instead of a recurring weekly template — for organizers whose schedules differ from one week to the next. Alongside it, add **per-page blocked dates** so any single page can make a day unbookable without affecting other pages. Weekly pages, organizer-wide days off, and the group-poll feature set are unchanged.

## Requirements

1. **Page type at creation.** The new-booking-page form offers two types: *weekly* (exactly today's behavior, untouched) and *calendar*. The type is chosen at creation and cannot be changed afterwards (v1).
2. **Calendar availability.** A calendar page has no weekly template. Its availability is date-specific windows only: a date plus one or more start–end ranges in the page's timezone. Any date without windows is unbookable. Slot generation uses the same engine rules as weekly pages — step by duration inside each window, per-date timezone→UTC conversion (DST-safe), minimum notice and buffers applied, and exclusion of any slot overlapping an active booking on **any** of the organizer's pages, either type. The horizon setting does not apply to calendar pages (the placed dates define reach) and is hidden on their form.
3. **Week-by-week editor.** The calendar page's edit screen presents windows grouped by week with week navigation. Organizers add, edit, and remove windows per date. The editor is operable with JavaScript disabled (plain forms); JS may enhance. Dates in the past cannot receive windows (validation error, input kept). Within-date validation matches weekly rules: end ≤ start or overlapping ranges are rejected.
4. **Copy previous week.** One action stamps the previous week's windows onto the displayed week, **replacing** that week's existing windows, behind an explicit confirm step. Copying from a week that has no windows is refused with a friendly notice and never touches the target. When some target dates are already in the past, windows land only on the future dates; past dates get nothing, without error.
5. **Per-page blocked dates (both types).** On any page's edit screen, the organizer adds/removes blocked dates scoped to that page. A blocked date produces no slots on that page while other pages still offer it. Blocking hides — it does not delete — a calendar page's windows for that date; unblocking restores them unchanged.
6. **Days off unchanged.** The organizer-wide days-off feature keeps working exactly as today. The slot engine excludes a date when **either** an organizer-wide day off **or** the page's own block applies.
7. **Bookings invariant.** Existing bookings survive every availability edit, clear, block, or copy (a booking keeps its stored start and duration). New slot generation honors current settings while still avoiding all active bookings. The database-level double-booking guard is unchanged and spans both page types.
8. **Public page parity.** Calendar pages use the same public booking, manage, and cancel flows as weekly pages: day-grouped slots, timezone handling including the no-JS `?tz=` round-trip and rename aliases, honeypot + rate limit + CSRF, `.ics` and calendar links, manage/cancel tokens. A calendar page with no future windows shows the existing "no times available" state.
9. **Zero-step upgrade.** Schema changes apply automatically and idempotently on the first request after updating the code (the app's existing upgrade promise). Existing installs keep every weekly page, booking, and day off with no data loss and no manual steps. Poll tables are untouched.
10. **Reuse + integration.** Same architecture and helpers as `specs/booking.md` (function-based controllers, `view()`, `url()`, CSRF, `keep_input()`/`old()`, `rate_ok()`, `random_token()`); files under 500 lines; PHP 7.4-compatible; no new dependencies; existing responsive/dark-mode/keyboard/no-JS quality bar.
11. **Wider main layout.** The app's main content container is currently too narrow to show all 7 days of a week properly. Widen it so the week-by-week editor (and the weekly availability grid) presents a full 7-day week comfortably on desktop, and degrade gracefully on smaller screens — days stack or scroll cleanly down to 320 px, never a crushed grid. Existing pages (dashboard, poll forms/manage, settings) must still render correctly at the new width — widening is a shared-layout change and is regression-checked, not booking-only.
12. **Tests.** `php tests/run.php` extended and fully green: calendar slot generation only on placed dates; notice/buffer on calendar pages; copy-week replace semantics including the empty-source refusal and past-date skipping; past-date and invalid-range validation; per-page block hiding without deleting windows (and unblock restoring them); either-block exclusion (day off OR page block); cross-type conflict blocking; migration idempotency (running migrations twice is safe).

## Out of Scope

- Converting a page between weekly and calendar types after creation.
- Stamping a saved weekly pattern onto calendar weeks (interview considered and rejected; copy-previous-week is the only bulk tool).
- A clickable mini-calendar UI for organizer-wide days off (considered and rejected).
- A month-grid visual editor — the v1 editor is a week-by-week list.
- Recurring exceptions ("every second Tuesday"), external calendar sync, and everything already out of scope in `specs/booking.md`.

## Constraints

- Stack, patterns, and file layout per `specs/booking.md`; new code extends `app/booking.php`, `app/controllers/booking.php`, and the `booking_*` views.
- Local test/lint runner is PHP 7.4 — no PHP 8-only syntax.
- The existing `migrate()` pattern is `CREATE TABLE IF NOT EXISTS` only; whatever schema shape is chosen (new tables and/or guarded alterations to `booking_*` tables), migration must be automatic, idempotent, and safe to run repeatedly on live data. Poll tables must not change.
- Weekly pages' data, behavior, and tests must pass unchanged.

## Edge Cases

- **Copy onto a non-empty week:** explicit confirm, then full replacement of the target week's windows.
- **Copy from an empty week:** refused with a notice; the target week is untouched.
- **Copy when target dates are partly past:** future dates receive windows; past dates are skipped silently.
- **Window on a past date (direct entry):** validation error with input kept; nothing saved.
- **End ≤ start or overlapping ranges on one date:** validation error, as on weekly pages.
- **Blocking a date that has calendar windows:** slots disappear; windows are retained and reappear on unblock.
- **Editing/clearing/blocking a date with an active booking:** the booking survives untouched; freed time becomes bookable per the current windows.
- **Day off and page block on the same date:** both exclude independently; removing one leaves the other's exclusion in force.
- **DST transition inside a placed window:** same DST-safe per-date generation as weekly pages — no crash, no duplicate or overlapping UTC starts.
- **Calendar page with zero future windows:** public page shows the friendly "no times available" state (not an error).
- **One weekly + one calendar page, same organizer:** an active booking on either blocks overlapping slots on the other.
- **Upgrade of an existing install:** first request migrates; nothing else required; re-running migrations is harmless.

## Definition of Done

- [ ] Create a calendar page and place different windows across two consecutive weeks (week A trimmed by blockers, week B fuller); the public page offers exactly the placed, unblocked, non-conflicting future slots in both weeks, and a booking succeeds in each week.
- [ ] Copy-previous-week replaces the target week's windows behind a confirm; copying from an empty week is refused with a notice.
- [ ] A per-page blocked date removes that page's slots for the date (works on a weekly page and a calendar page) while another page still offers the same date; unblocking a calendar page's date restores its windows unchanged.
- [ ] An organizer-wide day off still blocks the date on every page of both types.
- [ ] A booking on a weekly page hides the overlapping calendar-page slot and vice versa; the DB-level duplicate-active guard still holds.
- [ ] The week editor rejects past dates and invalid ranges with input kept, and is fully usable with JavaScript disabled.
- [ ] The week editor shows all 7 days of a week without cramping on a desktop viewport, and stacks/scrolls cleanly at 320 px; existing pages render correctly in the widened layout.
- [ ] `php tests/run.php` is fully green: all pre-existing tests plus the new coverage listed in Requirement 12.
- [ ] After a simulated upgrade (run migrations on a database created by the current release, twice), existing weekly pages, bookings, days off, and polls behave exactly as before.

## Open Questions

None — all model, workflow, and semantics decisions were settled in the interview (two page types; copy-previous-week with replace-behind-confirm; per-page blocks added alongside the unchanged organizer-wide days off).
