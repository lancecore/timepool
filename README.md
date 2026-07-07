# TimePool

A free group availability poll you can host yourself. Think Doodle, minus the subscription. Built for nonprofits and small teams who already pay for cheap shared hosting and don't have a developer on call.

The flow: you propose a few possible meeting times and share a link. Anyone with the link marks each time Yes, If-need-be, or No. No accounts. Answers land in a grid, you pick the winning time, and everyone gets one-click Add to Calendar links. Times show in each person's own timezone automatically.

> TimePool finds one time that works for a whole *group*, Doodle-style. A personal 1:1 booking page (the Calendly job) is a different tool.

---

## Table of Contents

- [Key Features](#key-features)
- [Tech Stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Quick Start (Local)](#quick-start-local)
- [Installation (Production / Shared Hosting)](#installation-production--shared-hosting)
- [Architecture](#architecture)
  - [Directory Structure](#directory-structure)
  - [Request Lifecycle & Routing](#request-lifecycle--routing)
  - [URL Strategy (subdomain, subfolder, no mod_rewrite)](#url-strategy)
  - [Data Model](#data-model)
  - [Timezone Handling](#timezone-handling)
  - [Blind Polls](#blind-polls)
  - [Security Model](#security-model)
- [Configuration](#configuration)
- [Routes Reference](#routes-reference)
- [Scripts & Commands](#scripts--commands)
- [Testing](#testing)
- [Deployment](#deployment)
- [Backup & Restore](#backup--restore)
- [Updating](#updating)
- [Troubleshooting](#troubleshooting)
- [Out of Scope (v1)](#out-of-scope-v1)
- [License](#license)

---

## Key Features

- **Group polls.** Propose timed slots or all-day options. Participants vote Yes / If-need-be / No.
- **No accounts for participants.** They respond from a public link with just a name, and can come back later to change their answer.
- **Timezones handled.** Slots are stored as absolute UTC instants and shown in each viewer's local time. There's a manual override if the auto-detect guesses wrong.
- **Best-slot ranking.** The leading time gets a star. If-need-be always ranks below Yes.
- **Blind polls** (optional, per poll). Participants can't see anyone else's answers until they submit their own.
- **Deadlines.** Set a response deadline and the poll closes itself.
- **Finalize + calendar.** Pick the winning time. Everyone gets Add to Google / Outlook links and a downloadable .ics file.
- **Email is optional.** With SMTP configured, TimePool sends invites, new-response alerts, deadline reminders, and confirmations. Without it, you share links by hand and everything still works.
- **One-file installer.** A browser wizard sets everything up. No terminal, no separate database server.
- **Your branding.** Organization name, logo, and accent color.
- **The UI holds up.** Responsive, light and dark mode, keyboard-navigable, respects reduced motion.

---

## Tech Stack

- **Language:** PHP 7.4+ (8.1+ recommended)
- **Database:** SQLite via PDO. No MySQL, no database server to set up.
- **Frontend:** Server-rendered PHP templates, vanilla JavaScript, hand-written CSS
- **Build step:** None. No Composer, npm, bundler, or compile step. Upload and run.
- **Email (optional):** A built-in minimal SMTP client (PLAIN/LOGIN auth, STARTTLS/SSL). No third-party mail library.
- **Server:** Any Apache/PHP host: shared hosting, cPanel, a VPS, even a Raspberry Pi. Works with or without `mod_rewrite`.

---

## Prerequisites

To run it, the host needs:

- PHP 7.4 or newer with the `pdo_sqlite` extension (nearly every host has it)
- A writable install folder, so the SQLite database can be created
- Optional: `mod_rewrite` for clean URLs. Without it, the app falls back to query-string URLs on its own.
- Optional: SMTP credentials, if you want email notifications

To develop locally you also want the PHP CLI (`php -v`) and, for the HTTP end-to-end test, `curl`.

---

## Quick Start (Local)

```bash
# 1. From the project root, start PHP's built-in server
php -S localhost:8099 -t .

# 2. Open the installer in your browser
open http://localhost:8099/install.php   # macOS (or just visit the URL)
```

Fill in the wizard: organization name, your admin login, timezone. The SQLite database is created for you, and you land on the dashboard.

To reset and start over, stop the server and delete the generated data folder:

```bash
rm -rf data
```

> PHP's built-in dev server has no `mod_rewrite`, so the app installs with clean URLs off and uses `index.php?r=/…` links. That's normal and fully functional. On a real Apache host, the included `.htaccess` turns clean URLs on automatically.

---

## Installation (Production / Shared Hosting)

The whole path, start to finish, no terminal needed. (A shorter version lives in [`docs/INSTALL.md`](docs/INSTALL.md).)

1. **Create a subdomain** in your hosting control panel (cPanel → *Subdomains*), such as `meet.yourorg.org`. Note its document root folder. A subfolder like `yourorg.org/meet` also works, with no config changes.
2. **Upload the files** into that document root using File Manager or FTP. Upload the whole project's contents, so `index.php` and `install.php` sit at the subdomain root.
3. **Open `https://meet.yourorg.org/install.php`** in a browser.
4. **Follow the wizard.**
   - It checks the server first: PHP version, SQLite, writable folder.
   - You set the organization name, logo, accent color, default timezone, and the admin account.
   - Email (SMTP) is optional. Skip it now and add it later under **Settings**.
   - It creates the SQLite database, writes the config, and locks itself so it can't run again.
5. **Delete `install.php`** afterward. Re-running it is already blocked, but tidy is tidy.

That's it. Create a poll and share its link.

---

## Architecture

A small, deliberately boring front-controller app. No framework. The goal is code that runs on any PHP host and needs zero build tooling.

### Directory Structure

```
.
├── index.php              # Front controller: routes every request
├── install.php            # Single-file setup wizard (+ optional fetch/unpack)
├── .htaccess              # Routes to index.php, hardens, denies DB files
├── assets/
│   ├── app.css            # All styling: theme tokens, light/dark, responsive, grid
│   └── app.js             # Timezone conversion, theme toggle, slot builder, copy, confirm
├── app/                   # Application code (denied from web by app/.htaccess)
│   ├── bootstrap.php       # Loads config, error handling, security headers, session, DB
│   ├── helpers.php         # Escaping, base-path/URL builders, CSRF, flash, polyfills
│   ├── db.php              # PDO/SQLite connection + schema migration + settings
│   ├── auth.php            # Users, login, sessions
│   ├── poll.php            # Poll/slot/response model, tally/ranking, timezone, rate limit
│   ├── ics.php             # .ics generation + Google/Outlook add-to-calendar links
│   ├── mailer.php          # Minimal SMTP client (optional email)
│   ├── notify.php          # Invites, alerts, reminders, confirmations (+ absolute URLs)
│   ├── view.php            # Template renderer, brand mark, availability grid
│   ├── controllers/
│   │   ├── auth.php         # login/logout/forgot/reset, healthz
│   │   ├── polls.php        # dashboard, create/edit/manage/finalize/delete/invite
│   │   ├── public.php       # public respond page, submit, .ics, logo
│   │   └── settings.php     # branding, SMTP, organizer management
│   └── views/              # PHP templates (layout, public, login, poll_form, grid, …)
├── data/                  # CREATED BY INSTALLER (gitignore this)
│   ├── config.php          # Generated config (db path, secret, pretty-URL flag)
│   ├── app.sqlite          # The database
│   ├── uploads/            # Logo
│   └── .htaccess           # Denies all web access
├── docs/INSTALL.md        # Non-technical install guide
├── specs/timepool.md      # The product spec (source of truth)
└── tests/run.php          # Self-check suite
```

> **Web-root note:** the project root *is* the web docroot. Application code lives in `app/` and data in `data/`. Both are protected by their own `.htaccess`, so sensitive files are never served directly.

### Request Lifecycle & Routing

1. Apache rewrites all non-file requests to `index.php` (via `.htaccess`). On hosts without `mod_rewrite`, links use `index.php?r=/path` instead. Same handler either way.
2. `app/bootstrap.php` loads `data/config.php`. If the app isn't installed yet, it redirects to `install.php`.
3. Bootstrap sets the production error handler (visitors get a friendly 500 page, never a stack trace), sends security headers, starts the session, and opens the database. The schema migrates itself if needed.
4. `index.php` matches the request method and path against a flat route table and calls a controller function.
5. The controller does its work, then either `redirect()`s or renders a template inside a layout with `view()`.

### URL Strategy

The app is base-path aware and handles three situations with no configuration:

| Situation | Example link produced |
| --- | --- |
| Subdomain root + `mod_rewrite` | `/dashboard` |
| Subfolder + `mod_rewrite` | `/meet/dashboard` |
| No `mod_rewrite` (any location) | `/meet/index.php?r=%2Fdashboard` |

The installer checks for `mod_rewrite` by requesting `/healthz`, then stores a `pretty` flag in `config.php`. `url()` in `app/helpers.php` reads that flag and keeps query strings (like `?token=` and `?slot=`) intact in every mode.

### Data Model

SQLite schema (auto-created by `app/db.php`):

```
users          id, email (unique), password_hash, name, role (admin|organizer),
               active, reset_token, reset_expires, created_at

polls          id, user_id, public_token (unique), title, description, location,
               organizer_tz, blind, deadline_utc, closed, final_slot_id,
               nudged_at, created_at, updated_at

slots          id, poll_id, kind (datetime|date), start_utc, date,
               duration_min, sort

participants   id, poll_id, name, comment, edit_token, ip, created_at, updated_at

responses      id, participant_id, slot_id, choice (yes|maybe|no)

invites        id, poll_id, email, created_at

activity       id, poll_id, message, created_at        # in-app activity feed

settings       key, value                              # org name, logo, accent, SMTP, …

rate           ip, ts                                  # per-IP rate-limit ledger
```

**Identity without accounts.** Each participant row gets an unguessable `edit_token`. It lives in a cookie and doubles as a shareable edit link. Two people named John stay separate rows, and anyone can come back later to change their own answer.

### Timezone Handling

- Organizers enter slots in their own timezone. Timed slots are converted to absolute UTC instants (`slots.start_utc`), so a 2pm meeting stays 2pm across daylight-saving changes.
- The server renders a fallback label in the organizer's timezone. Then `assets/app.js` re-renders every `<time data-utc>` element in the viewer's detected timezone. A manual picker ("Show times in …") is saved in `localStorage`.
- All-day slots are stored as plain dates (`slots.date`) and look the same everywhere.

### Blind Polls

When a poll has `blind = 1`, a participant who hasn't responded sees no other answers and no tallies. After they submit (a cookie marks them), the full grid appears. The organizer always sees everything from the manage view.

### Security Model

- **CSRF tokens** on every state-changing form.
- **Output escaping** (`e()` / `htmlspecialchars`) at every boundary.
- **Honeypot field, per-IP rate limiting, and a per-poll response cap** on the public form.
- **Login throttling** (per IP).
- **Content-Security-Policy** plus `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` headers. They're sent in PHP, so they apply even without `mod_headers`.
- **Secrets and the database live in `data/`**, denied from the web by `.htaccess`. `.sqlite` files are also blocked at the root.
- **Unguessable tokens** for poll links and edit links. Passwords are hashed with `password_hash()`, and the session ID is regenerated on login.

---

## Configuration

There is no `.env` file. Configuration splits between a generated PHP file and a database table.

**`data/config.php`** (written by the installer; do not commit it):

| Key | Meaning |
| --- | --- |
| `installed` | `true` once setup completes; gates the installer |
| `db` | Absolute path to the SQLite file |
| `secret` | Random app secret |
| `pretty` | Whether clean URLs (mod_rewrite) are available |
| `created` | Install timestamp |

**`settings` table** (edited in-app under **Settings**, admin only):

| Key | Meaning |
| --- | --- |
| `org_name`, `logo_file`, `accent` | Branding |
| `default_tz` | Default timezone for new polls |
| `max_participants` | Per-poll response cap (default 500) |
| `app_url` | Absolute base URL (used in emails) |
| `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_secure`, `smtp_from` | Optional email |

> **Single-file install option:** `install.php` defines a `RELEASE_URL` constant (empty by default). If you host a `.zip` of the app and set this constant, a user can upload *only* `install.php` and the wizard will download and unpack the rest before setup. Otherwise, upload the full package.

---

## Routes Reference

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/` | Redirect to dashboard or login |
| GET | `/healthz` | Plain-text probe (used to detect clean-URL support) |
| GET/POST | `/login`, `/logout` | Auth |
| GET/POST | `/forgot`, `/reset` | Password reset |
| GET | `/dashboard` | Organizer's polls |
| GET/POST | `/polls/new` | Create a poll |
| GET | `/polls/{id}` | Manage a poll (grid, share, finalize, activity) |
| GET/POST | `/polls/{id}/edit` | Edit a poll |
| POST | `/polls/{id}/duplicate` `…/close` `…/finalize` `…/delete` `…/invite` | Poll actions |
| GET/POST | `/settings` | Branding + email (admin) |
| GET/POST | `/users`, `/users/{id}/toggle` | Manage organizers (admin) |
| GET | `/logo` | Serve the uploaded logo |
| GET | `/p/{token}` | Public respond page |
| POST | `/p/{token}` | Submit / edit a response |
| GET | `/p/{token}/ics?slot=final` | Download the .ics |

---

## Scripts & Commands

There's no build system, just PHP. Common tasks:

| Command | Description |
| --- | --- |
| `php -S localhost:8099 -t .` | Run the app locally |
| `php tests/run.php` | Run the unit self-checks |
| `php -l <file>.php` | Lint a PHP file for syntax errors |
| `rm -rf data` | Reset a local install (deletes DB + config) |

---

## Testing

Two layers. Both run with just PHP and curl.

### Unit self-checks

```bash
php tests/run.php
```

This covers the logic most likely to break quietly: UTC storage across DST, best-slot ranking (If-need-be below Yes), edit-token de-duplication, deadline auto-close, ICS generation, and base-path/query-string URL building. Expected output ends with `23 passed, 0 failed`.

### End-to-end (HTTP)

Start the server, then drive the full flow with `curl`: install, log in, create a poll, respond anonymously, check the tally, finalize, download the app's own `.ics` link, and confirm blind-poll hiding. A reference script pattern:

```bash
php -S 127.0.0.1:8099 -t . &       # start server
# ... curl the install POST, log in, create a poll, respond, finalize, fetch .ics ...
```

The flow exercises CSRF, sessions, the no-rewrite fallback URLs, security headers, and the login throttle.

---

## Deployment

**Primary target: shared hosting on a subdomain** (see [Installation](#installation-production--shared-hosting)). The deploy is literally "upload the files, run the wizard." No pipeline.

Notes for any host:

- Point the subdomain's document root at the uploaded folder. A subfolder works too.
- Make the folder writable so `data/` can be created (typically `0755`).
- Keep HTTPS on. The session cookie is marked `Secure` automatically when served over HTTPS.
- On a VPS with Nginx instead of Apache, route all non-file requests to `index.php` and deny `/app` and `/data`. Example location block:

  ```nginx
  location / { try_files $uri $uri/ /index.php?$query_string; }
  location ~ ^/(app|data)/ { deny all; }
  location ~ \.sqlite { deny all; }
  ```

  (Or skip the rewrite rules entirely and let the app run with clean URLs off. It works without them.)

---

## Backup & Restore

Everything that matters lives in **`data/`**.

- **Back up:** copy the entire `data/` folder. It contains `app.sqlite`, `config.php`, and `uploads/`. For a consistent SQLite copy, do it while the site is idle, or run `sqlite3 data/app.sqlite ".backup data/backup.sqlite"`.
- **Restore or move to a new host:** upload the app files, then drop your saved `data/` folder in place. Do **not** re-run the installer. The app picks it up immediately.

---

## Updating

1. Back up `data/` (see above).
2. Replace the app files (`app/`, `assets/`, `index.php`, `.htaccess`) with the new version. **Leave `data/` untouched.**
3. Load any page. `app/db.php` runs its `CREATE TABLE IF NOT EXISTS` migrations automatically, and running them twice is safe.

---

## Troubleshooting

**Clean URLs 404 (e.g. `/dashboard` not found).**
The host lacks `mod_rewrite`. The app already falls back to `index.php?r=/…` links if it detected this at install time. If `mod_rewrite` was enabled *after* install, edit `data/config.php` and set `'pretty' => true`.

**"Writable folder" check fails in the installer.**
Make the install folder writable (e.g. `chmod 755`) so `data/` and the SQLite file can be created.

**Logo or assets not loading.**
Check that `assets/` was uploaded and is web-readable. The logo is served via the `/logo` route from `data/uploads/`.

**Email isn't sending.**
Email is optional. Configure SMTP under **Settings → Email** and use the *test email* field. STARTTLS uses port 587; SSL/TLS uses 465. Without email, the app still works fully by sharing links.

**Forgot the admin password and email isn't configured.**
Run the documented file-based reset from the install folder (shown in [`docs/INSTALL.md`](docs/INSTALL.md)):

```bash
php -r 'require "app/helpers.php"; $GLOBALS["config"]=require "data/config.php"; require "app/db.php"; require "app/auth.php"; db()->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash("NEW_PASSWORD", PASSWORD_DEFAULT), "you@example.org"]);'
```

**"Database is locked" under load.**
SQLite runs in WAL mode here, which handles typical poll traffic fine. If you expect heavy concurrency, host on a faster disk or consider a different backend (out of scope for v1).

**Re-running `install.php` says "Already installed."**
That's the safety lock. To genuinely reinstall, remove `data/config.php`. That deletes your config, so back up `data/` first.

---

## Out of Scope (v1)

Left out on purpose: Calendly-style 1:1 booking, per-slot capacity caps, Google Calendar OAuth sync, multi-tenant hosting and billing, native mobile apps, recurring polls, and shipped translations (UI strings are centralized for future i18n, but the app is English-only today). See [`specs/timepool.md`](specs/timepool.md) for the full contract.

---

## License

MIT. See [`LICENSE`](LICENSE). Any nonprofit (or anyone else) can self-host, modify, and share TimePool freely, as long as the copyright notice stays. If you'd rather require that hosted or modified versions stay open source, swap to AGPL-3.0.
