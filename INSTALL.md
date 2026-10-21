# Installing the CRM for your business

A sales CRM for one person or a small team: leads, a call queue, follow-ups,
email from the CRM, Google Calendar, and quotes. One codebase runs any business;
everything specific to yours lives in a settings file you edit.

There are two ways to install it:

- **On your own computer with Docker (recommended).** About 5 minutes and one
  command. The CRM runs on your machine only, at http://localhost:8080.
- **On a PHP web host.** The steps further down, for a CRM you reach over the
  internet.

## Install on your own computer (recommended)

This installs everything the CRM needs (the app, the database, PDF rendering),
adds a desktop shortcut, and starts the CRM when the computer starts.

1. Download the code:

   ```bash
   git clone https://github.com/Hamud101/CRM.git crm
   cd crm
   ```

   No git? Download the ZIP from GitHub, unzip it, and open a terminal in that
   folder.

2. Run the installer:

   ```bash
   ./install.sh
   ```

The installer asks for:

- **Business name**: shown in the header and on quotes (optional, press Enter to
  skip)
- **Your first name**: used to sign emails (optional, press Enter to skip)
- **Timezone**: it suggests your computer's own
- **Port**: `8080` unless that port is already taken

**If Docker is missing:** on Linux the installer offers to install it. It asks
first and needs your password for `sudo`. On Mac and Windows, install Docker
Desktop from https://www.docker.com/products/docker-desktop/ , start it, and run
the installer again. On Windows, run the installer from WSL or Git Bash.

**There is no password.** The CRM only answers on this computer, at
http://localhost:8080, so nothing else on your network or the internet can
reach it.

### Updating

Run `git pull` in the CRM folder, then `./install.sh` again. It keeps your
answers and your data.

### Stopping and starting

In the CRM folder:

```bash
docker compose stop
docker compose start
```

### Backing up

```bash
docker compose exec -T crm tar -C /data -cf - . > crm-backup.tar
```

Your data lives in a Docker volume, not in the CRM folder, so deleting the
folder does not delete your leads.

### Email and calendar

Setup screens for email, calls and calendar inside the CRM are on the way.
Until then, the settings file is at `/data/secrets.php` inside the container:

```bash
docker compose exec crm nano /data/secrets.php
```

Then see sections 4 and 5 below for what to fill in.

## Install on a web host

About 15 minutes on any ordinary PHP web host.

### What you need

- **A web host with PHP 8.0 or newer** and the `pdo_sqlite` extension. Most shared
  hosts (Namecheap, SiteGround, cPanel hosts) have it.
  Add `imap` for filing sent mail, `curl` for Google Calendar.
- **SSH access** to run the one-time setup command. Most hosts include it; it may
  need switching on in the control panel.
- **Apache or LiteSpeed** (the usual shared-hosting servers). The included
  `.htaccess` blocks the private folders. On nginx, see the note at the end.
- No database server. The CRM uses a single SQLite file.

### 1. Put the code on your host

Pick where the CRM will live, for example `https://crm.example.com/` or
`https://example.com/crm/`. Then, over SSH, go to that web folder and clone:

```bash
cd ~/public_html            # or your host's web folder, e.g. ~/domains/example.com/public_html
git clone https://github.com/Hamud101/CRM.git crm
cd crm
```

That makes the CRM available at `https://example.com/crm/`. To put it at the
root of a subdomain instead, clone into the subdomain's web folder with `.` as
the last argument.

No git on the host? Download the ZIP from GitHub, unzip it on your computer, and
upload the folder by SFTP.

### 2. Run setup

Still inside the CRM folder:

```bash
php bin/setup.php
```

It asks for:

| Question | What to answer |
|---|---|
| **Data folder** | Where your database and settings go. It must be **outside** the web folder. The suggested `~/appdata/<folder>` is fine. |
| **Timezone** | e.g. `Africa/Mogadishu`, `Europe/London`, `America/Chicago` |
| **Shared profile** | `starter` (the blank profile). You can add your own folder under `profiles/` to share settings between installs. |
| **Public URL** | The address from step 1, e.g. `https://example.com/crm/` |
| **Password** | 12 characters or more. Only a hash is stored. |
| **Business name**, **your first name** | Both optional. Shown in the header and used to sign emails. |

It writes `secrets.php` and `business.php` into the data folder and creates the
database. Running it again never overwrites them.

Open the URL and sign in.

### 3. Make it yours

Edit `business.php` in your data folder. Anything you put there wins over the
shared profile. `profiles/starter/business.php` lists every setting with a
comment; copy in the ones you want to change.

| Setting | What it does |
|---|---|
| `name`, `app_name`, `sender`, `logo` | Header, browser tab, email sign-off |
| `stage_labels` | The words for each pipeline stage, e.g. "Quote sent" |
| `demo_word` | What a booked sales meeting is called: "site visit", "demo" |
| `industries`, `service_industry` | The groups you filter leads by |
| `services` | Lines you can tick on a quote, with prices |
| `email_templates` | Ready-made emails using `{{first_name}}`, `{{company}}`, `{{sender}}` |
| `currency` | `$`, `€`, `KSh` ... |
| `features` | Turn panels on and off |

Changes show on the next page load.

For a logo, put the image in the CRM's `assets/` folder and set
`'logo' => 'assets/your-logo.png'`.

### 4. Optional: send email from the CRM

In `secrets.php` in your data folder, fill in the `smtp_*` and `imap_*` lines
with your mailbox's settings (your email provider lists them). The CRM sends
over SMTP, then files a copy in your Sent folder over IMAP, so it also shows in
your normal mail app.

### 5. Optional: Google Calendar

In the Google Cloud console, create an OAuth client (type: web application) and
add the redirect URI from `secrets.php` (it ends in `/oauth/google/callback`).
Put the client id and secret into `secrets.php`, then click **Connect Google
Calendar** in the CRM.

### 6. Optional: cron

If you use Google Calendar or Trello, add a cron job in your host's panel:

```
*/10 * * * * php /full/path/to/crm/sync.php
```

## Updating

On your own computer: `git pull`, then `./install.sh`.

```bash
cd ~/public_html/crm && git pull
```

Your data folder is outside the code, so updates never touch your leads or settings.

## Moving hosts

Copy two folders, the code and the data folder. If the data folder's path is
different on the new host, update `data_dir` in the CRM's `local.php` (or, if
you used the default `~/appdata/<folder>`, put it in the same place).

## nginx

`.htaccess` is ignored on nginx. Deny these paths inside the CRM's location
instead: `lib/`, `profiles/`, `bin/`, `templates/`, `sync.php`, `local.php`, and
anything starting with a dot. Route `oauth/google/callback` to `oauth_google.php`.
