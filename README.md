# Meeting Poll

A free, self-hostable **group availability poll** — a lightweight Doodle alternative for finding a meeting time that works for everyone. Built for nonprofits and small teams who want to run it on the cheap shared hosting they already have, with no developer required.

Organizers propose a few candidate times; anyone with the link marks **Yes / If‑need‑be / No** (no account needed); the organizer sees a tallied grid, picks the winning time, and everyone gets one‑click **Add to Calendar** links. Times are shown in each participant's own timezone automatically.

> **Doodle‑style, not Calendly‑style.** This finds one time that works for a *group*. It is not a personal 1:1 booking page.

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

- **Group availability polls** — propose timed slots or all‑day options; participants vote Yes / If‑need‑be / No.
- **No accounts for participants** — they respond from a public link with just a name and can edit their answer later.
- **Per‑participant timezones** — slots stored as absolute UTC instants and rendered in each viewer's local timezone (with a manual override).
- **Best‑slot ranking** — the leading time is highlighted; *Maybe* is always ranked below *Yes*.
- **Blind responses** (optional, per poll) — participants can't see others' answers until they submit their own.
- **Response deadlines** with automatic close.
- **Finalize + calendar** — pick the winning time and everyone gets **Add to Google / Outlook** links and a downloadable **.ics**.
- **Optional email (SMTP)** — invites, new‑response alerts, deadline reminders, and confirmations. The app works fully **without** email by sharing links.
- **One‑file installer** — a browser wizard sets everything up; no terminal, no separate database server.
- **Branding** — organization name, logo, and accent color.
- **Polished UI** — responsive, light/dark mode, keyboard‑navigable, reduced‑motion aware.

---

## Tech Stack

- **Language:** PHP 7.4+ (8.1+ recommended)
- **Database:** SQLite via PDO (zero‑config; MySQL not required)
- **Frontend:** Server‑rendered PHP templates + vanilla JavaScript + hand‑written CSS
- **Build step:** **None.** No Composer, npm, bundler, or compile step — upload and run.
- **Email (optional):** Built‑in minimal SMTP client (PLAIN/LOGIN, STARTTLS/SSL) — no third‑party mail library.
- **Server:** Any Apache/PHP host (shared hosting, cPanel, VPS, even a Raspberry Pi). Works with or without `mod_rewrite`.

---

## Prerequisites

To **run** it, the host needs only:

- **PHP 7.4 or newer** with the **`pdo_sqlite`** extension (standard on virtually all hosts).
- A writable install folder (so the SQLite database can be created).
- Optional: `mod_rewrite` for clean URLs (the app falls back to query‑string URLs automatically if absent).
- Optional: SMTP credentials if you want email notifications.

To **develop** locally you additionally want a PHP CLI (`php -v`) and, for the HTTP end‑to‑end test, `curl`.

---

## Quick Start (Local)

```bash
# 1. From the project root, start PHP's built-in server
php -S localhost:8099 -t .

# 2. Open the installer in your browser
open http://localhost:8099/install.php   # macOS (or just visit the URL)
```

Fill in the wizard (organization name, your admin login, timezone — SQLite is created automatically), and you'll land on the dashboard.

To reset and start over locally, stop the server and delete the generated data folder:

```bash
rm -rf data
```

> On PHP's built‑in dev server there is no `mod_rewrite`, so the app installs with clean URLs **off** and uses `index.php?r=/…` links. That's normal and fully functional. On a real Apache host the included `.htaccess` enables clean URLs automatically.

---

## Installation (Production / Shared Hosting)

The non‑technical path, start to finish. (A condensed version lives in [`docs/INSTALL.md`](docs/INSTALL.md).)

1. **Create a subdomain** in your hosting control panel (e.g. cPanel → *Subdomains*), such as `meet.yourorg.org`. Note its document root folder. *(A subfolder like `yourorg.org/meet` also works — no config changes needed.)*
2. **Upload the files** into that document root using File Manager or FTP. Upload the whole project **contents** (so `index.php` and `install.php` sit at the subdomain root).
3. **Open `https://meet.yourorg.org/install.php`** in a browser.
4. **Follow the wizard:**
   - It runs a **server check** (PHP version, SQLite, writable folder).
   - You set organization name, logo, accent color, default timezone, and the **admin account**.
   - Email (SMTP) is optional — skip it now and add it later under **Settings**.
   - It creates the SQLite database, writes config, and **locks itself** so it can't be re‑run.
5. **Delete `install.php`** afterward for tidiness (re‑running it is already blocked once installed).

You're done — create a poll and share its link.

---

## Architecture

A small, deliberately boring front‑controller app. No framework: the goal is maximum portability and zero build tooling.

### Directory Structure

```
.
├── index.php              # Front controller — routes every request
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
├── specs/meeting-poll.md  # The product spec (source of truth)
└── tests/run.php          # Self-check suite
```

> **Web‑root note:** the project root *is* the web docroot. Application code lives in `app/` and data in `data/`, both protected by their own `.htaccess`. Sensitive files are never served directly.

### Request Lifecycle & Routing

1. Apache rewrites all non‑file requests to `index.php` (via `.htaccess`). On hosts without `mod_rewrite`, links use `index.php?r=/path` instead — same handler.
2. `app/bootstrap.php` loads `data/config.php`. **If not installed, it redirects to `install.php`.**
3. Bootstrap sets the production error handler (friendly 500 page, no leaked stack traces), sends security headers, starts the session, and opens the database (auto‑migrating the schema if needed).
4. `index.php` matches the request method + path against a flat route table and dispatches to a controller function.
5. The controller does its work and either `redirect()`s or `view()`s a template wrapped in a layout.

### URL Strategy

The app is **base‑path aware** and works in three situations with no configuration:

| Situation | Example link produced |
| --- | --- |
| Subdomain root + `mod_rewrite` | `/dashboard` |
| Subfolder + `mod_rewrite` | `/meet/dashboard` |
| No `mod_rewrite` (any location) | `/meet/index.php?r=%2Fdashboard` |

The installer probes for `mod_rewrite` (by requesting `/healthz`) and stores a `pretty` flag in `config.php`. `url()` in `app/helpers.php` honors it and preserves query strings (e.g. `?token=`, `?slot=`) in every mode.

### Data Model

SQLite schema (auto‑created by `app/db.php`):

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

**Identity without accounts:** each participant row is keyed by an unguessable `edit_token`, stored in a cookie and shareable as an edit link — so two people named "John" are distinct rows, and anyone can return to edit their own response.

### Timezone Handling

- Organizers enter slots in **their** timezone; timed slots are converted to **absolute UTC instants** (`slots.start_utc`) so wall‑clock times survive DST.
- The server renders a fallback label in the organizer's timezone; `assets/app.js` re‑renders every `<time data-utc>` element in the **viewer's** detected timezone, with a manual picker (`Show times in …`) persisted in `localStorage`.
- All‑day slots are stored as plain dates (`slots.date`) and shown identically everywhere.

### Blind Polls

When a poll has `blind = 1`, a participant who hasn't responded sees no other answers or tallies. After they submit (cookie set), the full grid is revealed. The organizer always sees everything from the manage view.

### Security Model

- **CSRF tokens** on every state‑changing form.
- **Output escaping** (`e()` / `htmlspecialchars`) at every boundary.
- **Honeypot field + per‑IP rate limiting + per‑poll response cap** on the public form.
- **Login throttling** (per IP).
- **Content‑Security‑Policy** + `X‑Frame‑Options`, `X‑Content‑Type‑Options`, `Referrer‑Policy` headers (sent in PHP so they apply even without `mod_headers`).
- **Secrets and the database live in `data/`**, denied from the web by `.htaccess`; `.sqlite` files are additionally blocked at the root.
- **Unguessable tokens** for poll links and edit links; passwords hashed with `password_hash()`; session regenerated on login.

---

## Configuration

There is no `.env` file. Configuration is split between a generated PHP file and a database table.

**`data/config.php`** (written by the installer — do not commit):

| Key | Meaning |
| --- | --- |
| `installed` | `true` once setup completes; gates the installer |
| `db` | Absolute path to the SQLite file |
| `secret` | Random app secret |
| `pretty` | Whether clean URLs (mod_rewrite) are available |
| `created` | Install timestamp |

**`settings` table** (edited in‑app under **Settings**, admin only):

| Key | Meaning |
| --- | --- |
| `org_name`, `logo_file`, `accent` | Branding |
| `default_tz` | Default timezone for new polls |
| `max_participants` | Per‑poll response cap (default 500) |
| `app_url` | Absolute base URL (used in emails) |
| `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_secure`, `smtp_from` | Optional email |

> **Single‑file install option:** `install.php` defines a `RELEASE_URL` constant (empty by default). If you host a `.zip` of the app and set this constant, a user can upload *only* `install.php` and the wizard will download and unpack the rest before setup. Otherwise, upload the full package.

---

## Routes Reference

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/` | Redirect to dashboard or login |
| GET | `/healthz` | Plain‑text probe (used to detect clean‑URL support) |
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

There's no build system — just PHP. Common tasks:

| Command | Description |
| --- | --- |
| `php -S localhost:8099 -t .` | Run the app locally |
| `php tests/run.php` | Run the unit self‑checks |
| `php -l <file>.php` | Lint a PHP file for syntax errors |
| `rm -rf data` | Reset a local install (deletes DB + config) |

---

## Testing

Two layers, both runnable with just PHP/curl.

### Unit self‑checks

```bash
php tests/run.php
```

Covers the non‑trivial logic: UTC storage across DST, best‑slot ranking (Maybe below Yes), edit‑token de‑duplication, deadline auto‑close, ICS generation, and base‑path/query‑string URL building. Expected output ends with `23 passed, 0 failed`.

### End‑to‑end (HTTP)

Start the server, then drive the full flow with `curl` (install → login → create poll → anonymous respond → tally → finalize → download the app's own `.ics` link → blind‑poll hiding). A reference script pattern:

```bash
php -S 127.0.0.1:8099 -t . &       # start server
# ... curl the install POST, log in, create a poll, respond, finalize, fetch .ics ...
```

The flow exercises CSRF, sessions, the no‑rewrite fallback URLs, security headers, and the login throttle.

---

## Deployment

**Primary target: shared hosting on a subdomain** (see [Installation](#installation-production--shared-hosting)). The deploy is literally "upload the files, run the wizard." No pipeline required.

General notes for any host:

- Point the subdomain's document root at the uploaded folder (or use a subfolder — both work).
- Ensure the folder is writable so `data/` can be created (typically `0755`).
- Keep HTTPS on — the session cookie is marked `Secure` automatically when served over HTTPS.
- For a VPS with Nginx instead of Apache, route all non‑file requests to `index.php` and deny `/app` and `/data`. Example location block:

  ```nginx
  location / { try_files $uri $uri/ /index.php?$query_string; }
  location ~ ^/(app|data)/ { deny all; }
  location ~ \.sqlite { deny all; }
  ```

  (Or simply let the app run with clean URLs off — it works without any rewrite rules.)

---

## Backup & Restore

Everything that matters lives in **`data/`**.

- **Back up:** copy the entire `data/` folder (it contains `app.sqlite`, `config.php`, and `uploads/`). For a consistent SQLite copy, do it while the site is idle, or use `sqlite3 data/app.sqlite ".backup data/backup.sqlite"`.
- **Restore / migrate to a new host:** upload the files, then drop your saved `data/` folder in place (do **not** re‑run the installer). The app picks it up immediately.

---

## Updating

1. Back up `data/` (see above).
2. Replace the app files (`app/`, `assets/`, `index.php`, `.htaccess`) with the new version — **leave `data/` untouched**.
3. Load any page; `app/db.php` runs idempotent `CREATE TABLE IF NOT EXISTS` migrations automatically.

---

## Troubleshooting

**Clean URLs 404 (e.g. `/dashboard` not found).**
The host lacks `mod_rewrite`. The app already falls back to `index.php?r=/…` links if it detected this at install time. If `mod_rewrite` was enabled *after* install, edit `data/config.php` and set `'pretty' => true`.

**"Writable folder" check fails in the installer.**
Make the install folder writable (e.g. `chmod 755`) so `data/` and the SQLite file can be created.

**Logo or assets not loading.**
Ensure `assets/` was uploaded and is web‑readable. The logo is served via the `/logo` route from `data/uploads/`.

**Email isn't sending.**
Email is optional. Configure SMTP under **Settings → Email** and use the *test email* field. STARTTLS uses port 587; SSL/TLS uses 465. The app remains fully usable by sharing links if email isn't set up.

**Forgot the admin password and email isn't configured.**
Run the documented file‑based reset from the install folder (shown in [`docs/INSTALL.md`](docs/INSTALL.md)):

```bash
php -r 'require "app/helpers.php"; $GLOBALS["config"]=require "data/config.php"; require "app/db.php"; require "app/auth.php"; db()->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash("NEW_PASSWORD", PASSWORD_DEFAULT), "you@example.org"]);'
```

**"Database is locked" under load.**
SQLite uses WAL mode here, which handles typical poll traffic fine. If you expect heavy concurrency, host on a faster disk or consider a different backend (out of scope for v1).

**Re‑running `install.php` says "Already installed."**
That's the safety lock. To genuinely reinstall, remove `data/config.php` (which deletes your config — back up `data/` first).

---

## Out of Scope (v1)

Intentionally **not** included: Calendly‑style 1:1 booking, per‑slot capacity caps, Google Calendar OAuth sync, multi‑tenant hosting/billing, native mobile apps, recurring polls, and shipped translations (UI strings are centralized for future i18n but English‑only today). See [`specs/meeting-poll.md`](specs/meeting-poll.md) for the full contract.

---

## License

Released under the **MIT License** — see [`LICENSE`](LICENSE). MIT lets any nonprofit (or anyone) freely self‑host, modify, and share Meeting Poll with no obligations beyond keeping the copyright notice. If you'd instead prefer to require that hosted/modified versions stay open source, swap to **AGPL‑3.0**.
