# User authentication & access control — plan

Status legend: ✅ done · 🟡 drafted · 🔨 in progress · ⬜ to do

## Goals

- Videos are **public**, **unlisted** (anyone with the link) or **private**.
- Users belong to one or more **groups**; a group is granted one or more **collections**.
- Built-in **admin** group: full access to everything, plus administration.
- Header shows **Se connecter** (top right) or a **user menu** once logged in:
  profile, change password, logout.

## Decisions (checkpoint 1)

| Topic | Decision |
|-------|----------|
| Sign-up | **Self-registration**, with email verification before the first login |
| Login identifier | Username **or** email |
| Password reset | By email, built on the same token and mail plumbing as registration |
| Persistent login | **"Remember me"** via a `user_tokens` table. The design must allow **passkeys** later |
| Private media | **Not served through PHP**. Media may live on a separate server, so access uses **signed URLs** checked by the media server |
| Likes/comments | Attached to `users.id` when logged in; the anonymous id is kept for logged-out visitors |
| Landing after login | Home page (random public video), plus a list of the collections the user can see |
| Mail | PHPMailer 7.x through an SMTP smarthost configured in `.env` |

## Access rules

| Visibility | Who can watch                                        | Listed in "Dans la même collection" |
|------------|------------------------------------------------------|-------------------------------------|
| `public`   | Everyone                                             | Everyone                            |
| `unlisted` | Everyone with the link                               | Viewers with collection access, and visitors who opened an unlisted link of that collection (for their session) |
| `private`  | Admins, and members of a group granted the collection | Only viewers with collection access |

"Collection access" means `admin` membership, or membership of any group listed
in `group_collections` for the video's `collection_id`. Self-registered users
start with no group, so they see public videos only until an admin adds them to one.

**Sharing an unlisted collection** (checkpoint 6, the most common use): a visitor who opens the
link of an unlisted video can browse all the other unlisted videos of the same collection. The
collection is remembered in the session (2 h idle, 24 h at most), so it stays unlocked while
browsing that collection, including from a public sibling. Opening a *public* video unlocks
nothing, so unlisted videos never surface from the home page. A session cookie is only set when
an unlisted link is opened; public pages remain cookie-free.

When a private video is denied:
- **anonymous** → redirect to `login?return=…` (the shared link still works once the user logs in);
- **logged in without access** → 404 (the same page as a missing video).

The same check applies to `watch`, `embed` and `oembed`. Private videos will not
work in third-party iframes, because browsers don't send the session cookie
cross-site. That is expected, and unlisted videos are the way to embed.

## Authentication design

- **One entry point for every login method**: `Auth::login(User $user, string $method)`.
  It regenerates the session id, stores the user id, the login time and the method
  (`password`, `remember`, later `passkey`), and updates `last_login_at`. Password login,
  remember-me and future passkeys only differ in how they identify the user.
- **`users.password_hash` is nullable**, so a passkey-only account will be possible later.
  Password login simply fails for an account without a password.
- **`user_tokens`** holds every single-purpose secret: `remember`, `verify_email`,
  `reset_password` and `change_email`. Each row has a public `selector` (lookup key) and a
  SHA-256 hash of the secret `validator`. The raw validator only ever exists in the cookie
  or link (`selector:validator`), so a database leak doesn't expose usable tokens, and the
  comparison is constant-time (`hash_equals`).
- **Remember me**: a 30-day cookie `mytube_remember` (`HttpOnly`, `Secure`, `SameSite=Lax`).
  It is rotated on each use (new validator, same selector). If the selector matches but the
  validator doesn't, the cookie was stolen and replayed: all of that user's remember tokens
  are revoked. Logout revokes the current token; a password change or reset revokes all of them.
- **Session invalidation**: a session or remember token issued before
  `users.password_changed_at` is rejected.
- **Future passkeys** (not built now): a `user_passkeys` table (credential id, public key,
  sign count, transports, label, created/last used) and a WebAuthn library. They will plug into
  `Auth::login($user, 'passkey')`, with no changes needed to sessions or remember-me.

## Phases

### 1. Database ✅
`scripts/migrations/001-auth.sql` (applied to `mytube-dev`); `scripts/init-db.sql` updated for fresh installs.

- `videos.visibility` ENUM('public','unlisted','private'): existing rows are set to `public`, the default for new rows is `private`
- FK `videos.collection_id → collections.id`
- `users`: username (ASCII, case-insensitive, unique), email (unique), display_name,
  password_hash, is_active, created/updated/last_login/password_changed timestamps
- `user_groups` (id 1 = `admin`, `is_builtin=1`): can't be deleted or renamed
- `group_members` (user ↔ group), `group_collections` (group ↔ collection), both with cascade deletes
- `login_attempts`: brute-force throttling
- `schema_migrations`: bookkeeping
- `scripts/create-user.php <username> <email> [--admin] [--name=…]`: CLI bootstrap for the first admin

### 2. UI draft ✅
- `templates/base.twig` layout, used by `watch.twig`, `login.twig` and `account.twig`
- `templates/partials/header.twig`: login button, or a user menu with avatar initial, name/email,
  Administration (admins only), Mon profil, Changer le mot de passe, Se déconnecter (POST form)
- `templates/account.twig`: profile form and password form (`account#password`)
- `assets/js/usermenu.js`: dropdown with keyboard support (↓/↑/Esc, closes on click outside)
- Home page (`/`): random public video; versioned asset URLs (`asset()` Twig function)

### 3. Core plumbing & authentication ✅
Notes from implementation:
- Sessions start lazily: anonymous visitors get no cookie until they open the login page.
- Apache already sees real client IPs (checked from another LAN machine), so `TRUSTED_PROXIES`
  stays empty on the dev instance.
- `scripts/send-test-mail.php <recipient>` checks the mail setup; `scripts/create-user.php`
  creates already-verified accounts.
- Twig updated to 3.30 (security advisories, none affecting this app since the sandbox isn't used).

- Migration `002-tokens.sql`:
  - `user_tokens`
  - `users.email_verified_at`, and `users.password_hash` made nullable
  - `login_attempts` becomes a generic `throttle_events` table with an `action` column
    (`login`, `register`, `reset`)
  - `video_likes` and `video_comments`: `user_id char(32)` renamed to `anon_id`, plus a new
    nullable `user_id int` FK to `users`
- A small router in `index.php` in place of the `$action` if-chain; controllers in `src/Controllers/`
  (`VideoController`, `AuthController`, `AccountController`)
- `src/Core/Session.php`: cookie `mytube_sid`, `HttpOnly`, `Secure` (when HTTPS), `SameSite=Lax`,
  path = app base; idle timeout (2 h) and absolute timeout (24 h); flash messages
- `src/Core/Csrf.php`: per-session token, checked on every POST
- `src/Auth/Auth.php`: `login()`, `logout()`, `user()`, remember-me; `src/Auth/TokenRepository.php`
  for selector/validator tokens; `src/Auth/Throttle.php`
- `src/Models/User.php`: lookup by username or email, `password_verify` + `password_needs_rehash`,
  group ids, `is_admin`
- Login: generic error message. Throttling: 5 failures per 15 min per identifier, 20 per IP.
  Refused when the account is inactive or its email isn't verified.
- `return` parameter: only local paths under the app base are accepted (no open redirect).
  The default target is the home page.
- `src/Core/Mailer.php`: PHPMailer configured from `.env` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`,
  `SMTP_PASS`, `SMTP_SECURE`, `MAIL_FROM`, `MAIL_FROM_NAME`), with Twig templates for messages
- Remove the `preview_user` draft hack

### 3b. Registration, email verification, password reset ✅
Implemented as below, plus invisible **reCAPTCHA** from day one (setup: [recaptcha.md](recaptcha.md)):
strict on registration, forgot password and resend confirmation; lenient on login (a low score is
refused, an unavailable check is not). Other details:
- Unverified accounts are deleted after 7 days, so abandoned sign-ups don't hold usernames.
- The reset link's token moves into the session on first use (no token in the address bar,
  history or `Referer`). A successful reset also marks the email as verified, and a
  "password changed" notice is mailed.
- Links in emails are built from `SITE_URL`, never from the request's Host header.
- New passwords are hashed with Argon2id (older bcrypt hashes upgrade at the next login).
- Mail templates are `templates/mail/*.txt.twig` (plain text, no HTML escaping).

Planned as:
- `register`: username, display name, email, password (≥ 10 characters, confirmed), a honeypot
  field, and throttling per IP. CAPTCHA will plug in here later.
- The account is created unverified and a `verify_email` link is sent (valid 48 h). The login page
  offers to resend it.
- Registration never reveals whether an email address is already taken. If it is, the owner of
  that address gets an email saying someone tried to register and pointing to password reset.
- `forgot-password` → `reset_password` link (valid 1 h, single use). The response is always the
  same, whether or not the account exists. A successful reset sets `password_changed_at`, revokes
  all tokens and logs the user in.

### 4. Access control on videos ✅
- `src/Auth/Access.php`: `canView()` and an SQL condition for listings, as in "Access rules" above.
- `watch`: anonymous visitors of a private video go to the login page; logged-in users without
  access get the 404 page. `embed`: 404. `oembed`: 401 (oEmbed specification). `Vary: Cookie` on
  watch pages, since the sidebar depends on the viewer.
- Home page: random pick among the videos listed for the viewer (logged-in users with collection
  access also get unlisted and private videos of those collections).
- Sidebar (decided at checkpoint 4, after a mock): "Collection" label and current collection name,
  then the *other* listed videos of that collection. An **"Autres collections"** menu, shown only
  when the viewer can see other collections, lists their names (no counts); each opens its
  **most recently added** video visible to the viewer. Native `<details>` element, closed by a
  click or tap elsewhere or Escape; 44 px touch targets on touch screens.
- Migration `003-video-created-at.sql`: `videos.created_at`; existing videos dated from their media
  files with `003-backfill-created-at.php`.
- `add-video.sh` is superseded by the admin UI ("Ajouter une vidéo"). It no longer works as is
  (`resolution` has no default), so it can be removed.

### 5. Account pages ✅
- Profile: display name (shown at once in the header). Changing the email sends a `change_email`
  link (24 h) to the new address and a notice to the current one; the change applies only once
  the link is opened (`confirm-email`), and the account page shows the pending address meanwhile.
  If the new address belongs to another account, nothing is sent, with the same message.
- Password: the current one is checked (throttled: 5 failures per 15 min), the new one validated
  as at registration. All remember-me tokens are revoked and other sessions logged out; the current
  session continues (keeping its remember-me cookie if it had one), and a notice is mailed.
- Accounts without a password (future passkeys) can set one without a "current password" field.
- Email change requests are throttled (3 per hour per user).

### 5b. Collections list ✅ (covered by phase 4, no separate code)
The collection switcher on the watch page (phase 4) fulfils this phase:
- After login without a `return` URL, users land on the home page (since phase 3).
- The sidebar's current collection plus the "Autres collections" menu list exactly the collections
  the viewer can see (granted to their groups, all for admins, plus those with public videos);
  anonymous visitors get it too when several collections have public videos.
- The home page is a watch page, so the list is available there.
- No separate collections page or "Mes collections" user-menu entry: the sidebar lists the visible
  videos of the current collection, and the switcher is on every watch page. Both can be added
  later if a full overview of all collections becomes useful.

### 6. Media delivery & protection — nice to have (deferred at checkpoint 5)
**Not planned for now.** Without encryption (DRM), access controls on media files can't resist
even a basic stream downloader once a viewer has legitimate access, so the effort isn't worth it
yet. Today the media files of unlisted and private videos are hidden (their paths only appear on
pages the viewer may open), not protected. The notes below are kept for a future iteration.

Goals: PHP never streams media, and media may be moved to a separate server or CDN.

- **`MEDIA_BASE_URL`** in `.env` (default: `<app>/media/`). All media and thumbnail URLs are built
  by one service, `src/Media/MediaUrls.php`.
- **Public videos**: plain URLs, so caches and CDNs can serve them freely.
- **Unlisted and private videos**: **signed, expiring URLs** with the signature in a *path prefix*:
  `{MEDIA_BASE_URL}/s/{expires}/{signature}/{video-dir}/master.m3u8`.
  HLS playlists reference their segments with relative URLs, so each segment request automatically
  carries the same prefix, and no playlist rewriting or cookies are needed. The signature covers
  `expires` and the video directory; the secret is `MEDIA_SIGNING_KEY`, shared with the media server.
- **Checked by the web server, not PHP**: nginx (already in front of Apache) with the
  `secure_link` module, or njs for HMAC-SHA256. The signer supports the scheme that nginx checks.
  Requests to private directories without a valid prefix are refused.
- **No local file access at request time**: the request path currently looks for the media files
  on disk, probes the resolution with ffprobe and generates thumbnails with ffmpeg. These move to
  ingestion (`add-video.sh` / the future upload pipeline), which stores the manifest path and the
  resolution in the database.
- Thumbnails of private videos are signed the same way.

### 7. Administration UI ✅ (videos at checkpoint 3, users/groups/collections at checkpoint 5)
**Video catalogue** (`/admin`, `admin` group only; others get a 404):
- **Vidéos**: list with collection, visibility and media status; edit title, description,
  collection (or a new one), visibility; regenerate the thumbnail at a chosen time; remove from the
  catalogue (media files are kept and reappear in the scan).
- **Ajouter une vidéo**: scans `media/` for `NAME.mp4` files and HLS directories (`master.m3u8` or
  `manifest/video.m3u8`) not registered yet. Flags entries the web server can't read, HLS playlists
  without `#EXT-X-ENDLIST` (encoding in progress), unsupported formats and `.MP4` in upper case.
  Registering probes resolution and duration (ffprobe), generates the thumbnail (default: 10 % into
  the video, at most 2 min 20 s), and defaults to **private**. This is the ingestion step of phase 6;
  the watch page only generates missing thumbnails/resolutions for older entries.
- `<base href>` in the layouts, so relative URLs work under `admin/…`; `APP_TIMEZONE` for displayed dates.

Visibility is enforced since phase 4. Media files themselves are served without checks
(phase 6 is deferred).

**Collections, groups, users** (checkpoint 5):
- **Collections**: list (videos, groups with access); create, rename; delete only when empty (the
  default collection, id 0, can't be deleted); choose the groups with access.
- **Groups**: list (members, collections); create, edit, delete; members and granted collections
  as checkbox lists. The built-in `admin` group can't be renamed or deleted and has no grants (it
  sees everything).
- **Users**: list (groups, status: active / disabled / invitation pending / email not verified, last
  login); edit username, display name, email, "active" and "email verified" flags, groups; delete;
  send a password reset link (24 h).
- **Creating a user = an invitation**: the account has no password; an email link (7 days, same
  mechanism as password reset) lets the user choose one, which also verifies the address. Unverified
  self-registrations are still purged after 7 days, invited accounts are not.
- Links are editable from both sides: user ↔ group on the user and group pages, group ↔ collection
  on the group and collection pages.
- Lock-out protection: no disabling, un-verifying, demoting or deleting your own account; the last
  admin able to log in can't lose that ability (a safety net behind the self rules). Disabling a
  user logs them out everywhere at once.

### 8. Likes, comments, view counts ⬜ (planned feature)
- Logged in: rows carry `user_id` (one like per user). Logged out: rows carry `anon_id` from the
  anonymous cookie.
- On login, likes made under the current anonymous id are reassigned to the account.

### 9. Tests ⬜
- PHPUnit for the access rules (visibility × anonymous / member / non-member / admin),
  `return` URL validation, tokens (expiry, rotation, theft detection) and throttling
