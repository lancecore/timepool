# Meeting Poll

## Objective

A self-hostable PHP web app where a nonprofit's staff create group availability polls (propose several candidate time slots), share a public link, and let anyone mark which slots work (Yes/Maybe/No) without an account; the organizer sees a tallied grid, picks a winning time, and everyone gets add-to-calendar links — with per-participant timezone conversion and a one-file, browser-driven installer for non-technical users. Doodle-style group polling, not Calendly-style 1:1 booking.

## Requirements

1. **Organizer auth.** Installer creates the first admin. Admins can create and disable additional organizer accounts. Login, logout, and password reset (reset email if SMTP configured; otherwise a documented file/CLI reset). Single-tenant: one nonprofit per install.
2. **Poll creation.** Organizer dashboard lists that organizer's polls. Create a poll with title, description, organizer timezone, and a set of candidate slots supporting both **date+time** and **date-only (all-day)** options. Edit, duplicate, close, and delete a poll.
3. **Slot storage.** Date+time slots stored as absolute UTC instants derived from the organizer's IANA timezone (so wall-clock survives DST); date-only slots stored as plain dates.
4. **Public response (no account).** Anyone with the link responds with a display name, a Yes/Maybe/No mark per slot, and an optional comment. An explicit "none of these work" (all-No) response is allowed and is distinct from not-yet-responded.
5. **Edit own response.** Each responder gets an edit token (cookie + shareable edit link) to update or withdraw their response later without an account.
6. **Results grid.** A responsive availability grid with per-slot tallies; best slot(s) highlighted, with Maybe weighted below Yes when ranking.
7. **Blind responses (per-poll toggle).** When enabled, non-responders cannot see others' answers or tallies until they submit. The organizer always sees everything.
8. **Deadline + auto-close (per-poll, optional).** After the deadline the poll stops accepting responses and shows a closed state. If SMTP is configured, send a pre-deadline nudge to known non-responders.
9. **Finalize + calendar.** Organizer selects a winning slot; the poll shows the confirmed time and everyone gets one-click **Add to Google / Apple / Outlook** links plus a downloadable, valid **.ics**. The organizer can change or clear the final time later.
10. **Per-participant timezones.** All times render in the viewer's detected IANA timezone with a manual override; fall back to the organizer's timezone when the viewer's can't be detected.
11. **Optional email (SMTP).** When configured, send invites, new-response alerts to the organizer, deadline nudges, and final-time confirmations. The app is **fully functional without email** via link-sharing and an in-app activity feed.
12. **Installer.** User uploads a single `install.php`, which fetches and unpacks the app (or detects already-uploaded files), then runs a browser wizard: requirement checks (PHP version, write permissions), automatic zero-config SQLite database creation, admin-account creation, org name / logo / accent color / default timezone, and an optional SMTP test. Writes a lock so it cannot be re-run. Works at a subdomain root **or** in a subfolder.
13. **Branding.** Org name, logo, and accent color configurable in setup/settings and applied across public and admin UI.
14. **Polish / quality bar (verifiable).** Mobile-first responsive (≥320px), WCAG 2.1 AA, Lighthouse ≥90 performance & accessibility on key pages, light + dark mode, tasteful motion respecting `prefers-reduced-motion`, full keyboard navigation, no console errors.
15. **Security / abuse.** Input validation and output escaping at all boundaries, CSRF protection on forms, honeypot + per-IP rate limiting on the public response form, sanitized file paths, secrets stored outside the web root, and unguessable poll/admin tokens.
16. **Portability.** No server-side build step; relative / base-path-aware URLs so it runs at a subdomain root or under `/meet` unchanged; minimal/vendored dependencies; data and config stored outside web-accessible paths where the host allows.

## Out of Scope (v1)

- Personal 1:1 booking (Calendly-style) mode.
- Per-slot capacity limits / sign-up caps.
- Google Calendar OAuth sync or free/busy reading.
- Multi-tenant hosting, billing, SSO.
- Native mobile apps.
- Recurring polls.
- Shipped translations (UI strings centralized for future i18n, but English-only in v1).

## Constraints

- **Stack:** PHP (target 8.1+) on cheap shared hosting; **SQLite by default** (zero-config), MySQL not required for v1.
- **No required external services:** works with zero API keys; SMTP and calendar features are optional/standalone.
- **Single-tenant:** one nonprofit per install; admin can add more organizer accounts.
- **Deployment:** subdomain is primary; subfolder must also work. Deployable via FTP/cPanel with no terminal.
- **Lightweight & portable:** minimal dependencies, no build step on the server.

## Edge Cases

- **Duplicate / repeat names** — two people named "John," or one returning: each response is its own row identified by an edit token, not by name; editing requires the token/edit link.
- **DST shift between creation and meeting** — slots stored as UTC instants tied to the organizer's IANA zone so the wall-clock time stays correct; viewers see correct local conversion.
- **Viewer timezone undetectable** — fall back to the organizer's poll timezone with a visible manual picker.
- **Blind poll** — a participant who hasn't answered sees no other responses or tallies; the full grid appears only after they submit; organizer is exempt.
- **Deadline passed** — form disabled with a clear closed message; organizer can reopen or finalize.
- **Anonymous spam** — honeypot + per-IP rate limit + a per-poll participant cap stop floods without an external captcha.
- **Final time changed after being set** — add-to-calendar links/.ics reflect the latest; if SMTP is on, an update notice is sent.
- **Lost admin access** — password reset works via SMTP if configured, otherwise a documented file/CLI reset.
- **Installer re-run on an installed instance** — detected via lock/config presence and refused.
- **Failed requirement check** — wrong PHP version or no write permission blocks with a specific fix message before any database is created.
- **No-slot / all-No submission** — accepted as an explicit "none work" response, visually distinct from no-response.
- **Subfolder vs subdomain** — all URLs base-path-aware so it works under `/meet` or at root with no config edits.

## Definition of Done

- [ ] On a stock PHP shared host (or matching Docker image), uploading only `install.php` completes the wizard and reaches the dashboard with **no manual file edits**.
- [ ] An organizer creates a poll with multiple date+time slots, shares the public link, and three participants in **different browsers/timezones** submit Yes/Maybe/No without accounts; the grid tallies correctly and ranks the best slot.
- [ ] A blind poll hides all others' responses from a participant until they submit (verified by a not-yet-responded participant seeing no tallies).
- [ ] A poll with a past deadline auto-closes and disables the response form.
- [ ] Finalizing a time produces working Add-to-Google/Apple links and a `.ics` that imports cleanly into a calendar app.
- [ ] The same instant renders as the correct local wall-clock time for at least two distinct viewer timezones.
- [ ] Key pages score Lighthouse ≥90 performance and accessibility on mobile, support light + dark mode, are fully keyboard-navigable, and honor `prefers-reduced-motion`.
- [ ] Re-running `install.php` on an installed instance is blocked.
- [ ] The app runs identically installed at a subdomain root and in a `/subfolder`.
