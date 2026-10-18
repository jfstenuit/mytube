# MyTube

**MyTube** is a self-hosted, lightweight video platform built in PHP: share videos publicly, by
link, or with selected groups of people, without relying on a third-party video service. Playback
uses [Video.js](https://videojs.com/) with adaptive HLS streaming and casting.

It suits families, associations and small organizations who want to publish videos on their own
server and control who sees what. The user interface is currently in French only (translations
are on the roadmap).

## Features

**Playback**
- [Video.js 8](https://github.com/videojs/video.js) with HLS adaptive streaming (H.264/AAC) and
  plain MP4, quality selector, Chromecast / Android TV casting.
- Responsive layout; the player's aspect ratio comes from the stored resolution, so pages don't jump.
- `watch` page, minimal `embed` page for iframes, oEmbed (JSON and XML) and OpenGraph tags for
  link previews (WordPress, social networks). Share button using the Web Share API.

**Visibility and access**
- Three levels per video: **public** (listed, on the home page), **unlisted** (anyone with the
  link; the page of an unlisted video also lists the other unlisted videos of its collection, so
  sharing one link shares the collection) and **private** (members of groups granted the
  collection).
- Videos are organised in **collections**; **groups** of users are granted collections; a
  built-in **admin** group sees everything and manages the site.
- Collection switcher on the watch page; welcome page when no video is visible to the visitor.

**Accounts**
- Self-registration with email verification, login by username or email, "remember me",
  password reset by email, profile page (display name, email change confirmed by the new address,
  password change).
- Accounts created by an administrator receive an invitation to choose their password.

**Engagement**
- View counts reported by the player after 30 s of actual playback (or half of a short video),
  checked server-side with signed tokens and deduplicated per viewer and hour.
- Likes and comments for members of groups with access to the collection.

**Administration** (`/admin`)
- Video catalogue: edit title, description, collection, visibility, thumbnail; register media
  found in `media/` (resolution, duration and thumbnail are probed automatically).
- Collections, groups and users, with membership and access grants editable from either side,
  and protection against locking out the last administrator.

**Security and privacy**
- CSRF protection on every form, Argon2id password hashing, session invalidation on password
  change, throttling of logins and sensitive forms, invisible reCAPTCHA (optional) on
  registration and password forms.
- No cookie for anonymous visitors of public pages; anonymous viewers are counted through a daily
  hash, without storing IP addresses.
- Third-party resources: Video.js and its plugins (vjs.zencdn.net, cdn.jsdelivr.net), the
  Chromecast sender (gstatic.com), Material Icons (Google Fonts); reCAPTCHA (google.com) only on
  login/registration/password pages when configured; the Buy Me a Coffee button only if enabled.

## Adding videos (current workflow)

Videos can't be uploaded through the browser yet (first item of the roadmap). Today:

1. Copy the source file to `incoming/` (SFTP, shell…).
2. Run `scripts/video-encoder.sh` (e.g. `setsid nohup scripts/video-encoder.sh >/dev/null 2>&1 < /dev/null &`).
   It transcodes each file into an HLS tree `media/<md5>/` (5 renditions + audio, NVENC when
   available, libx264 otherwise) and moves the source to `processed/`.
   Alternatively, put an existing `NAME.mp4` or HLS directory (`master.m3u8`) directly in `media/`.
3. In **Administration → Ajouter une vidéo**, the new entry appears; fill in title, collection and
   visibility. Files the web server can't read, or encodings that look unfinished, are flagged.

## Roadmap

### Next

1. **Upload and transcoding from the browser**: the missing piece between a video file and a
   published video.
   - Upload from the admin pages, resumable and chunked for large files (the unused `uploads`
     table was designed for this; e.g. the tus protocol).
   - Background transcoding queue replacing the manual `video-encoder.sh` run, with progress and
     errors shown in the admin pages, and the video registered automatically when done.
   - Fix the current encoder's limitations at the same time: renditions scaled to fixed 16:9 sizes
     (distorts other aspect ratios), small sources upscaled (a 720×576 source still gets a
     1280×720 rendition), rendition names not matching their sizes ("480p" is 960×540).
2. **Internationalisation (i18n)**: the interface is French only, with texts written directly in
   the templates, the controllers' messages and the emails.
   - Translation catalogues for every text (pages, flash and error messages, emails), with French
     and English to start.
   - Language chosen from the browser (`Accept-Language`), overridable by a language selector and
     a preference stored in the user's profile; emails sent in the recipient's language.
   - Locale-aware dates and numbers, and the OpenGraph locale (currently fixed to `fr_BE`).

### Later / nice to have / floating ideas

- **Subtitles and chapters** via WebVTT files (upload, language, chapter markers in the player).
- **Analytics** in the admin pages: views over time per video and collection (raw events are
  already kept in `video_views`).
- **REST API** for front-end interaction and integrations.
- **Media protection** for unlisted and private videos (signed URLs checked by the web server, media
  possibly on a separate server or CDN). Deferred: without encryption, it doesn't stop a stream
  downloader once a viewer has access. Design notes in [docs/auth-plan.md](docs/auth-plan.md), phase 6.
- **Passkeys** (WebAuthn): the authentication design already allows accounts without a password.
- Comment moderation tools beyond deletion (reports, pending queue), email notifications.
- Configurable author for oEmbed (currently fixed in the template).
- Self-hosting the Video.js assets and icons instead of loading them from CDNs.
- Housekeeping: remove `scripts/add-video.sh` (superseded by the admin pages, no longer works
  with the current schema), empty placeholder directories in `src/`.

### Done

- [x] User authentication, registration, password reset, profile (2026)
- [x] Admin UI: video catalogue, media registration, collections, groups, users
- [x] Visibility levels and group-based access to collections
- [x] View counts, likes and comments (tied to accounts; anonymous views without cookies)
- [x] Invisible reCAPTCHA on sensitive forms
- [x] Migration from Smarty to Twig
- [x] Sharing with the Web Share API
- [x] Separate JavaScript modules per feature, versioned asset URLs
- [x] Test suites (unit, integration, end-to-end, browser)

## Requirements

- PHP 8.1 or newer (developed on 8.4) with `pdo_mysql`, `curl`, `mbstring`; Argon2 support
  recommended. The test suites need PHP 8.3+.
- MariaDB 10.5+ or MySQL 8.0.16+ (developed on MariaDB 11.8).
- Apache with `mod_rewrite` and `AllowOverride` (`.htaccess` sends every request to `index.php`);
  works behind a reverse proxy (`X-Forwarded-Proto`, `TRUSTED_PROXIES`).
- `ffmpeg` and `ffprobe` (thumbnails, resolution and duration; transcoding).
- An SMTP server for account emails.
- Composer.
- Optional: a Google Cloud reCAPTCHA key ([docs/recaptcha.md](docs/recaptcha.md)).

## Installation

1. **Get the code and dependencies**

   ```bash
   git clone https://github.com/jfstenuit/mytube.git
   cd mytube
   composer install --no-dev
   ```

2. **Configure**: `cp .env.sample .env`, then set at least the database, `SITE_URL`,
   `APP_SECRET` (`php -r 'echo bin2hex(random_bytes(32)), "\n";'`), SMTP and `MAIL_FROM`.
   See the comments in `.env.sample`.

3. **Create the database schema**

   ```bash
   mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < scripts/init-db.sql
   ```

4. **Web server**: point the virtual host (or an alias) to the project directory with
   `AllowOverride All`, and give the web server user write access to `thumbs/`, `logs/`,
   `incoming/`, `processed/` and `twig/cache/` (`scripts/setup-acl.sh` does it with POSIX ACLs for
   `www-data`). The web server must be able to read `media/`.

5. **Create the first administrator**

   ```bash
   php scripts/create-user.php admin you@example.com --admin --name="Your Name"
   php scripts/send-test-mail.php you@example.com    # check outgoing mail
   ```

6. Open the site, log in, and add a first video (see *Adding videos* above).

### Upgrading an existing installation

Apply the files of `scripts/migrations/` not yet listed in the `schema_migrations` table, in
order (`mysql … < scripts/migrations/NNN-….sql`), and run the companion `*-backfill-*.php`
scripts where present. Back up the database first: schema changes aren't transactional.

## Configuration

All settings live in `.env` (documented in `.env.sample`):

| Settings | Purpose |
|---|---|
| `DB_*` | Database connection |
| `SITE_URL`, `SITE_NAME` | Public base URL (used for links in emails), site name |
| `APP_ENV`, `APP_TIMEZONE`, `APP_SECRET` | `production`/`development`, time zone of displayed dates, secret for signed view tokens |
| `TRUSTED_PROXIES` | Reverse proxies allowed to set `X-Forwarded-For` |
| `SMTP_*`, `MAIL_FROM*` | Outgoing mail (verification, password reset, invitations) |
| `RECAPTCHA_*` | Invisible reCAPTCHA; empty site key = disabled ([docs/recaptcha.md](docs/recaptcha.md)) |
| `FOOTER_COPYRIGHT`, `BMC_*` | Footer text, optional Buy Me a Coffee button |

## Project structure

```text
mytube/
├── index.php            Front controller: routes → src/Controllers
├── src/
│   ├── Auth/            Authentication, access rules, tokens, throttling, passwords
│   ├── Controllers/     Video, account, registration, password reset, engagement, admin pages
│   ├── Core/            App, router, request, session, CSRF, config, database, mailer
│   ├── Media/           Media library (media/ scanner) and ffmpeg/ffprobe helpers
│   ├── Models/          Users, groups, collections, videos, likes and comments
│   ├── Security/        reCAPTCHA verification
│   └── Stats/           View counting
├── templates/           Twig templates (pages, admin/, mail/, partials/)
├── assets/              CSS, JavaScript modules, images
├── scripts/             init-db.sql, migrations/, create-user, encoder, setup tools
├── media/               Videos (MP4 files and HLS directories)
├── thumbs/              Generated thumbnails
├── incoming/, processed/  Encoder input and processed sources
├── docs/                Design notes: auth-plan.md (phases and decisions), recaptcha.md
├── tests/               Test suites (see tests/README.md)
├── .env.sample          Configuration template
└── .htaccess            Rewrites every request to index.php
```

## Tests

Unit, integration, end-to-end (HTTP) and browser test suites live in `tests/`; see
[tests/README.md](tests/README.md) for what they need and how to run them
(`vendor/bin/phpunit`, `python3 tests/browser/test_browser.py`). They are meant for a
development instance (`APP_ENV=development`).

## License

This project is open-source and released under the **MIT License**.

## Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss the proposed changes.

## Contact & Feedback

Feature suggestions, bug reports, and contributions are welcome via GitHub Issues.
