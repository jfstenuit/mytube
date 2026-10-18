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
| `unlisted` | Everyone with the link                               | Only viewers with collection access |
| `private`  | Admins, and members of a group granted the collection | Only viewers with collection access |

"Collection access" means `admin` membership, or membership of any group listed
in `group_collections` for the video's `collection_id`. Self-registered users
start with no group, so they see public videos only until an admin adds them to one.

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

### 4. Access control on videos ⬜
- `src/Auth/Access.php`: `canView(video, user)` and a filter for listings
- Applied to `watch`, `embed` and `oembed`, and to the related-videos query (filtered in SQL)
- `add-video.sh`: optional `visibility` and `collection` arguments (new videos are private by default),
  and fix the SQL quoting of the title (current shell interpolation breaks on `'`)

### 5. Account pages ⬜
- Profile: update the display name. Changing the email sends a `change_email` confirmation to the
  new address; the change only takes effect once confirmed.
- Password: verify the current one; the new one must be ≥ 10 characters and match the confirmation;
  `password_changed_at = NOW()`; remember tokens revoked; other sessions logged out
- Flash messages (success/error) via the session

### 5b. Collections list ⬜
- After login without a `return` URL, users land on the home page (random public video)
- Logged-in users get a **list of the collections they can see**: collections granted to their
  groups (all collections for admins), plus collections containing public videos
- Linked from the user menu ("Mes collections") and from the home page; each collection page
  lists the videos the user may watch (public, plus unlisted and private ones they have access to)

### 6. Media delivery & protection ⬜
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

### 7. Administration UI ⬜ (part of the planned Admin UI)
- Users: create, edit, deactivate, resend verification, trigger a password reset, group membership
- Groups: create, rename, delete (not `admin`), collection grants
- Collections: create and rename; per-video visibility and collection
- The last admin can't remove themselves from `admin`

### 8. Likes, comments, view counts ⬜ (planned feature)
- Logged in: rows carry `user_id` (one like per user). Logged out: rows carry `anon_id` from the
  anonymous cookie.
- On login, likes made under the current anonymous id are reassigned to the account.

### 9. Tests ⬜
- PHPUnit for the access rules (visibility × anonymous / member / non-member / admin),
  `return` URL validation, tokens (expiry, rotation, theft detection) and throttling
