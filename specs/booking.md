# TimePool 1:1 Booking

## Objective

Add Calendly / Microsoft-Bookings-style one-to-one scheduling to TimePool: an organizer defines recurring weekly availability for a bookable meeting type, shares a public link, and an invitee — no account — picks one open slot and books it, with automatic double-booking prevention, per-viewer timezone conversion, calendar links, and optional email notifications. This complements the existing group polls (which stay untouched) and reuses TimePool's existing auth, helpers, mailer, ICS, and view system.

## Requirements

1. **Booking pages.** A signed-in organizer can create, edit, pause/resume, and delete booking pages. Each page has: title (required); description and location (optional); meeting duration in minutes (15/30/45/60 choices plus a custom positive integer); an IANA timezone (defaults to the install's default-timezone setting, else UTC); weekly availability — per weekday, zero or more start–end time ranges in the page's wall-clock; booking horizon in days (how far ahead invitees may book, default 60); minimum notice in hours (default 4); buffer minutes applied around existing bookings (default 0); and an unguessable public URL `/b/<token>` (via `random_token()`).
2. **Days off.** An organizer can add and remove blocked dates (stored per organizer, applying to all of that organizer's pages). Blocked dates produce no slots on any page.
3. **Slot generation.** Open slots for a page are every duration-length start time, stepping by the duration, within each availability window on each date inside the horizon — converted date-by-date from the page's timezone to UTC so wall-clock times stay correct across DST — excluding: starts before now + minimum notice; blocked dates; and any slot that would overlap (buffer included) an existing non-cancelled booking belonging to the same organizer on **any** of their pages. Generation across DST transition dates must complete without error and yield strictly increasing, non-overlapping UTC starts.
4. **Public booking flow.** Anyone with the link sees the page title, description, duration, and open slots grouped by day, rendered in their auto-detected IANA timezone with a manual override (fallback: the page's timezone). The manual override must work with JavaScript disabled: a server-rendered timezone select that round-trips (GET) and re-renders slot labels and day grouping in the chosen zone; JS may enhance with auto-detection as poll pages do. They pick a slot and submit name (required), email (required, validated), and an optional note. On success they get a confirmation view showing the booked time in their timezone, a downloadable `.ics`, Add-to-Google and Outlook links, and a manage link to bookmark; the manage link is also emailed when SMTP is configured. Days with no open slots are omitted; if no slots exist in the whole horizon, a friendly "no times available" message is shown.
5. **Conflict safety.** When two submissions race for the same or overlapping slot, exactly one booking succeeds; the loser sees a clear "that time was just taken" message with refreshed slots. This is enforced at the database level (a constraint or equivalent atomic guard), not only by a pre-insert check.
6. **Cancellation.** The invitee can cancel from their manage link, behind an explicit confirmation step. The organizer can cancel any of their bookings from the admin side. A cancelled slot immediately becomes bookable again; the cancelled row is retained (status, not deletion) for the organizer's records. Cancelling an already-cancelled booking shows a friendly notice, not an error. If SMTP is configured, the other party is notified.
7. **Organizer view.** The admin area lists the organizer's booking pages (public link ready to copy, open/paused state) and their bookings — upcoming and past — with invitee name, email, note, and the time in the page's timezone. A booking stores its own start and duration, so edits to the page never alter existing bookings. Reachable from the existing dashboard/nav.
8. **Email (optional).** When the existing mailer is configured: invitee booking confirmation (time, manage link, calendar links), organizer new-booking alert, and cancellation notices to the other party. Every flow fully works without SMTP.
9. **Reuse + integration.** Uses the existing session auth (`require_login`), CSRF helpers, honeypot + `rate_ok()` on the public booking form, `random_token()`, the ICS/Google/Outlook builders (extended only if strictly needed), `view()`/layouts, `url()` for base-path-aware links (subfolder and no-mod_rewrite installs work), `keep_input()`/`old()` on validation failures, and `CREATE TABLE IF NOT EXISTS` migrations for **new tables only** — no changes to existing tables and no behavior change to polls.
10. **Quality bar.** Matches the existing app: responsive ≥320px, light + dark mode via the existing CSS variables, keyboard-navigable, all output escaped with `e()`, and the public booking page usable without JavaScript (slots visible in the page timezone, manual tz picker, plain form submit); JS may progressively enhance timezone rendering as poll pages do.
11. **Tests.** `php tests/run.php` is extended with booking coverage: DST-correct slot generation (winter vs summer UTC offsets), minimum-notice and horizon exclusion, buffer exclusion, cross-page conflict blocking, database-level double-booking rejection, cancellation reopening a slot, and blocked-date exclusion. The entire suite (old + new) passes.

## Out of Scope

- External calendar sync: Google/Outlook OAuth, free-busy reading, iCal feed subscriptions.
- Reschedule-in-place (cancel + rebook covers it).
- Group/round-robin/collective events, per-slot capacity, payments, SMS, custom intake questions beyond the note field.
- Multiple durations per page, slot-step increments different from the duration, recurring bookings.
- A week-grid calendar UI — a grouped day/time list is the intended UI.
- Any change to the group-poll feature set.

## Constraints

- PHP 8.1+ on cheap shared hosting; SQLite via the existing PDO singleton; no new dependencies; no build step.
- Follow the existing architecture exactly: function-based controllers in `app/controllers/`, route entries in `index.php`, model functions in `app/` (new `app/booking.php` is the expected home), views in `app/views/`, tests appended to `tests/run.php`.
- Files stay under 500 lines.
- All decisions above marked "default" are deliberate defaults chosen for autonomy; each is per-page configurable except where stated.

## Edge Cases

- **DST boundary:** Mon 09:00–17:00 in `America/New_York` yields 14:00 UTC starts in winter and 13:00 UTC in summer; generation across both transition dates neither crashes nor emits overlapping/duplicate UTC starts.
- **Concurrent booking:** two simultaneous submissions of the same slot — one succeeds, one gets the friendly "just taken" message; no duplicate row can exist (DB-level guard).
- **Stale slot at submit:** the slot was taken, the day was blocked, or the page was paused between render and submit — server re-validates, refuses with a friendly message and fresh slots, creates nothing.
- **Paused page:** public page shows a clear "not currently accepting bookings" state (not a 404); booking POSTs are refused.
- **Deleting a page with upcoming bookings:** refused with a message telling the organizer to cancel those bookings first; deletion succeeds once none remain (past/cancelled bookings don't block and are removed with the page).
- **Page edited after bookings exist:** existing bookings keep their stored start/duration; new slot generation honors the new settings while still avoiding the old bookings.
- **Invalid availability input:** end ≤ start, or overlapping ranges within one weekday → validation error, form repopulated via `keep_input()`, nothing saved.
- **Invalid invitee email:** validation error with input kept.
- **Unknown/wrong manage token:** friendly not-found page; no information leak about the booking.
- **Viewer timezone undetectable or JS off:** times shown in the page's timezone with a visible manual picker.
- **Bot spam:** honeypot field + per-IP rate limit on the public booking POST, as on poll responses.
- **Repeat cancel:** hitting an already-used cancel link shows "already cancelled", not an error.

## Definition of Done

- [ ] An organizer creates a 30-minute page (Mon–Fri 09:00–17:00, `America/New_York`, 4 h notice) and `/b/<token>` lists the correct open slots; switching the viewer timezone picker shows correct converted wall-clock times.
- [ ] Booking a slot with name + email produces a confirmation with a valid `.ics` (imports cleanly) and Google/Outlook links, and the slot disappears from the public page.
- [ ] Re-submitting the same slot (stale form) is rejected with a friendly message and no second booking row; the schema contains a constraint that makes a duplicate active booking impossible.
- [ ] A slot overlapping an existing booking on a *different* page of the same organizer is never offered.
- [ ] Cancelling via the invitee manage link (with confirm step) reopens the slot; repeating the cancel is a friendly no-op; the organizer still sees the cancelled record.
- [ ] A blocked date produces no slots on any of that organizer's pages.
- [ ] `php tests/run.php` passes: all pre-existing tests plus the new booking tests listed in Requirement 11.
- [ ] Booking routes work with pretty URLs off (`?r=` fallback) and in a subfolder install.
- [ ] The public booking page is fully usable with JavaScript disabled.
- [ ] Poll pages and flows behave exactly as before (no existing test regressions, no existing-table schema changes).

## Open Questions

None — interview waived by the requester's directive to proceed autonomously; all defaults are stated inline and are cheap to change later.
