# CRM

A sales CRM for one person or a small team. It tracks leads, works a call queue,
schedules follow-ups, sends email from the CRM, syncs with Google Calendar, and
produces quotes and signable agreements. It runs on your own computer with one
command, or on an ordinary PHP host.

## Install

- **On your own computer (recommended).** Run `./install.sh`. It uses Docker and
  serves the CRM at http://localhost:8080.
- **On a PHP web host.** Follow the web host steps in
  [INSTALL.md](INSTALL.md).

## What it does

- **A call queue with outcomes and dial stats.** Work through today's calls,
  record what happened, and see how the numbers add up.
- **Lead search finds businesses on Google Maps.** The Outreach tab saves a
  search like "plumber in Austin, TX", runs it now or every day, and adds the
  new businesses to Leads. Businesses already in the CRM are skipped.
- **Proposals are generated, not written.** Tick the services a lead agreed to
  and the system renders a signable PDF from the current price list. The package
  that was agreed on gets recorded against the lead, so the proposal and the call
  log cannot drift apart.
- **Sent mail goes into the real Sent folder.** Email is sent over SMTP and then
  filed into IMAP, so the thread reads normally in a regular mail client.
  Anything sent from the CRM is visible from a phone without the CRM.
- **Attachments are built, not hand-made.** The service one-pagers are generated
  from the HTML sources in `attachments/src/` by `build.py`, which inlines the
  fonts and drives headless Chrome. Changing a price means editing the source and
  rebuilding, not hunting for the newest PDF on a desktop.
- **Per-business settings in a profile.** Name, prices, agreements, email
  templates, industries, stage names, and which panels show all live in a
  profile, so the same code can serve any business.

## How it is put together

Plain PHP with no framework, SQLite for storage, and vanilla JavaScript on the
front end. That is a deliberate choice: it runs on ordinary shared hosting, with
no build step and no long-running process.

```
profiles/
  starter/       every setting, with defaults: the template for a new business
bin/setup.php    first-run setup for a new install
index.php        entry point and router
api.php          the API surface the front end talks to
lib/
  business.php   loads this install's profile
  auth.php       session handling
  db.php         SQLite access
  google.php     OAuth for calendar and contacts
  mailer.php     SMTP send, then IMAP file into Sent
  proposal.php   builds the signable proposal
  documents.php  attachment handling
  templates.php  HTML template rendering
  trello.php     pushes won deals onto the delivery board
  outreach.php   saved searches and the Google Maps scraper client
bin/render-worker.php   PDF rendering, run from cron
bin/outreach-worker.php Outreach lead search, run from cron
install.sh       one-command local install with Docker
compose.yaml     the Docker services
Dockerfile       the app image
docker/          entrypoint and support files
```

**PDF rendering runs outside the web request.** Proposals render through headless
Chrome, and some shared hosts cap a web request's memory below what Chrome needs.
So the web request queues a render and `bin/render-worker.php` picks it up from
cron. Chrome also needs a writable `HOME` and its own profile directory, which is
not the default on those hosts.

**Secrets and data are not in the web root.** `secrets.php` and `crm.sqlite` live
in a data folder outside anything the web server will serve. The gitignore blocks
them as well in case one ever gets copied in by mistake.

## Making it yours

Edit `business.php` in your data folder. Anything you set there wins over the
shared profile. `profiles/starter/business.php` lists every setting with a
comment; copy in the ones you want to change.

## License

No license has been chosen yet. Ask before reusing the code.
