# MyTube tests

Four layers, from fastest to most complete:

| Suite | Tool | Needs | Covers |
|---|---|---|---|
| `unit` | PHPUnit | PHP only | Password rules, return-URL validation, view thresholds and tokens, reCAPTCHA verdicts (simulated Google answers), media scanner (temporary directory), request helpers, Twig filters |
| `integration` | PHPUnit | Development database, ffmpeg | Users, split tokens, throttling, access rules, groups/collections links, view counting, likes and comments, ffprobe/ffmpeg |
| `e2e` | PHPUnit | Running development site | Every user-facing flow over HTTP: public pages, login and remember-me, registration, password reset, account page, access matrix, administration, views, likes, comments, with mail captured locally |
| browser | Python + Chrome | Chrome DevTools endpoint | Layout and overflow (desktop, mobile, dark), user menu, collection switcher on touch screens, like without reload, view counted after real playback, real reCAPTCHA token |

## Running

```bash
composer install                       # PHPUnit is a dev dependency
vendor/bin/phpunit                      # unit + integration + e2e (about 3 minutes)
vendor/bin/phpunit --testsuite unit     # or: composer test:unit / test:integration / test:e2e
vendor/bin/phpunit --filter AuthTest    # one class or test
python3 tests/browser/test_browser.py -v              # browser tests (about 3 minutes)
python3 tests/browser/test_browser.py -v LayoutTest   # one class
```

Run the browser tests **after** the PHPUnit suite has finished, not at the same time (see
`.env.e2e` below). Browser screenshots are written to `tests/output/browser/` for review.

## Assumptions

- **Development instance.** `.env` must have `APP_ENV=development`; integration, end-to-end and
  browser tests refuse to run otherwise, because they write to the database.
- **Database.** Tests use the database configured in `.env` (there is no separate test database:
  the database user can't create one). All test data is prefixed with `zz`: users `zz_…`, groups
  `zz_…`, collections `zz …`, video ids `zz…`, media files `zz_…`. It's removed after each test and
  again at the end of a run. Don't give real content names starting with `zz`.
- **Site URL.** End-to-end and browser tests call the site at `SITE_URL` from `.env`
  (`https://labs.chryseis.be/videos/` on the dev instance), or `MYTUBE_TEST_URL` if set. The web
  server must run the working copy the tests are run from (the tests create files in `media/` and
  read `thumbs/`).
- **ffmpeg/ffprobe** are installed: a 4-second test clip (`media/zz_e2e_clip.mp4`) is generated
  for the run and deleted afterwards.
- **Mail.** During an end-to-end run, mail is captured by a local SMTP sink
  (`tests/bin/mail-sink.php`, port 2526 or `MYTUBE_TEST_SMTP_PORT`) and stored in
  `tests/output/mail/`; nothing is delivered.
- **Chrome DevTools.** The browser tests assume that **`http://127.0.0.1:9222` is a valid
  endpoint for a Chrome browser DevTools interface** (`CHROME_DEVTOOLS=host:port` to change it).
  For example, Chrome on another machine started with
  `chrome --remote-debugging-port=9222 --user-data-dir=<dedicated profile>` and reached through
  `ssh -R 9222:127.0.0.1:9222 …` (use `127.0.0.1`, not `localhost`, which may resolve to IPv6).
  Each test runs in its own isolated browser context (like an incognito window), so the browser's
  own tabs and cookies aren't touched. Without the endpoint, the browser tests are skipped.
- **reCAPTCHA.** The browser reCAPTCHA tests use the real keys from `.env` and are skipped when
  `RECAPTCHA_SITE_KEY` is empty.

## `.env.e2e`: settings overridden during an end-to-end run

Scripts can't obtain reCAPTCHA tokens and mustn't send real mail, so the end-to-end suite writes
`.env.e2e` at its start:

```ini
SMTP_HOST=127.0.0.1
SMTP_PORT=2526
RECAPTCHA_SITE_KEY=
```

`src/Core/Config.php` applies this file on top of `.env` **only when `APP_ENV=development`**
(production ignores it), and it's git-ignored. The suite deletes it at the end. While it exists,
the dev site sends its mail to the sink and has reCAPTCHA disabled. If a run is interrupted
(e.g. killed), delete `.env.e2e` by hand; the browser tests refuse to start while it's present.

## Layout

```text
tests/
├── bootstrap.php              PHPUnit bootstrap (autoload, .env)
├── Support/                   Fixtures, HTTP client with cookie jar, mail sink, base test cases
├── Unit/                      no database, no network
├── Integration/               development database
├── E2E/                       HTTP against the development site
├── browser/                   cdp.py (DevTools client, stdlib only) and test_browser.py
├── bin/
│   ├── mail-sink.php          local SMTP server used by the e2e suite
│   └── fixtures.php           fixture helper (JSON) for the browser tests
└── output/                    mail, screenshots (git-ignored)
```

## What each test file covers

**Unit**
- `PasswordsTest`: length, confirmation, not equal to username/email, Argon2id, rehash of bcrypt.
- `ReturnPathTest`: accepted return targets; absolute/protocol-relative URLs, `..`, backslashes,
  control characters and lookalike prefixes fall back to the home page.
- `ViewCounterTest`: threshold (30 s or half the video), token binding to video and time,
  rejection of bots, forged/foreign/fresh/expired tokens, disabled without `APP_SECRET`.
- `RecaptchaTest`: verdicts for both backends (valid, low score, wrong action, wrong hostname,
  invalid token, HTTP error, missing token, disabled, missing credentials), exact request formats.
- `MediaLibraryTest`: MP4, HLS (`master.m3u8`, `manifest/video.m3u8`), incomplete encodings,
  unsupported formats, upper-case `.MP4`, unreadable directories, hidden files, path traversal,
  URL encoding.
- `RequestTest`: HEAD as GET, array parameters, client IP behind trusted proxies, HTTPS detection.
- `TwigExtensionsTest`: `filesize` filter, `asset()` versioning, no HTML escaping in `*.txt.twig`,
  default thumbnail time.

**Integration**
- `UserModelTest`: lookup by username/email, passwords, login ability, groups, admin flag.
- `TokenRepositoryTest`: only a hash stored, validator/expiry flags, rotation, deletion by type.
- `ThrottleTest`: login per identifier and IP, success clears failures, registration, views (IP
  only), IPv6.
- `AccessTest`: the access matrix in SQL (anonymous, no group, member, admin) and the unlisted
  siblings rule.
- `AdminModelsTest`: group/collection/user links from either side, names with commas in lists,
  built-in group protection, name checks, active admins.
- `ViewCounterRecordTest`: one view per viewer per hour, repeat after an hour, logged-in identity,
  no IP stored, elapsed-time check, 30 views per IP per hour.
- `EngagementModelTest`: like toggling, comments order and authors, cascades on deletion.
- `VideoProbeTest`: resolution, duration and thumbnail of the test clip.

**End-to-end (HTTP)**
- `PublicPagesTest`: watch/embed/oEmbed (JSON and XML, escaping, thumbnail URL), no cookie on
  public pages, errors (400/404/405), missing media, thumbnail generated on first view, home page.
- `AuthTest`: CSRF, login by username/email, session id regeneration, failures (wrong password,
  unknown, unverified, disabled), throttling, open redirects, remember-me rotation and theft
  detection, logout, sessions ended by a password change or by disabling the account.
- `RegistrationTest`: full sign-up and verification, validation, honeypot, taken username,
  existing addresses not revealed, resend, throttling, purge of stale sign-ups (not invitations).
- `PasswordResetTest`: same answer for unknown accounts, full reset (token leaves the URL, other
  sessions logged out, notice mailed, single use), verification by reset, disabled accounts,
  invalid links, throttling.
- `AccountTest`: display name, email change confirmed by the new address with a notice to the old
  one, taken addresses, throttling, password change keeping only the current session, accounts
  without a password.
- `VideoAccessTest`: watch/embed/oEmbed status for every viewer × visibility, login redirect,
  sidebar listing, unlisted links leaving no trace, collection switcher targets, home page picks.
- `AdminVideosTest`: admin-only pages, media scan flags, adding (probe, thumbnail, new collection),
  editing, removing (media kept), path traversal, list with views and missing media.
- `AdminManagementTest`: collections CRUD and protections, groups and links from both sides with
  their effect on access, built-in admin group, user invitations end to end, editing/disabling/
  deleting users, self lock-out protection.
- `EngagementTest`: view beacon (dedup, other browser, too early, bot, forged token, private
  videos, embed page), like permissions and toggling (JSON and no-JavaScript), comments
  (permissions, validation, draft, escaping, deletion rights, throttling).

**Browser** (`tests/browser/test_browser.py`)
- `LayoutTest`: public, member and admin pages in desktop, mobile and dark layouts: no horizontal
  overflow, no JavaScript error, header present; screenshots.
- `HeaderTest`: banner leads home, icon-only login button on phones, user menu with mouse and
  keyboard, logout from the menu.
- `CollectionSwitcherTest`: taps open/close/navigate with 44 px targets, Escape closes.
- `EngagementTest`: like toggles without reloading, one view beacon after real playback, share uses
  the video URL.
- `RecaptchaTest`: real token accepted by Google, hidden badge with the notice, protected form
  accepted.
