# Installing Meeting Poll

Meeting Poll runs on almost any PHP host (shared hosting, cPanel, a VPS, even a Raspberry Pi). No database server or terminal is required — it uses a self-contained SQLite file.

## What you need
- PHP 8.1 or newer with the `pdo_sqlite` extension (nearly all hosts have this).
- A subdomain (recommended) such as `meet.yourorg.org`, **or** a subfolder like `yourorg.org/meet`. Both work.

## Steps

1. **Create the subdomain** in your hosting control panel (e.g. cPanel → *Subdomains*). Note the folder it points to (its "document root").
2. **Upload the files.** Using your host's File Manager or any FTP app, upload the entire Meeting Poll folder's contents into that document root. (If you only have `install.php`, it can fetch the rest when `RELEASE_URL` is configured — otherwise upload the full package.)
3. **Open your site** in a browser, e.g. `https://meet.yourorg.org/install.php`.
4. **Follow the wizard.** It checks your server, then asks for your organization name, logo, color, timezone, and your admin login. Email (SMTP) is optional — you can add it later in Settings.
5. **Done.** You'll land on the dashboard. For safety, delete `install.php` afterward.

## After installing
- Create a poll, then share its public link. Participants respond with just their name — no account.
- Add more staff under **Organizers** (admin only).
- Configure email anytime under **Settings → Email**.

## Recovering a lost admin password
- If email (SMTP) is configured, use **Forgot your password?** on the sign-in page.
- If email is not configured, reset it from the server: open a terminal in the install folder and run
  ```
  php -r 'require "app/helpers.php"; $GLOBALS["config"]=require "data/config.php"; require "app/db.php"; require "app/auth.php"; db()->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash("NEW_PASSWORD", PASSWORD_DEFAULT), "you@example.org"]);'
  ```
  replacing `NEW_PASSWORD` and the email. (Many hosts offer a "PHP terminal" or cron command runner for this.)

## Subfolder vs subdomain
Either works with no configuration changes — Meeting Poll detects where it's installed and builds links accordingly.
