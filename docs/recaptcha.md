# reCAPTCHA setup

MyTube uses **invisible, score-based reCAPTCHA** on login, registration, "forgot
password" and "resend confirmation". There is no checkbox and no image challenge.
When a form is submitted, the browser fetches a token, and the server asks Google
for a score between 0.0 (bot) and 1.0 (human).

reCAPTCHA is **disabled while `RECAPTCHA_SITE_KEY` is empty** in `.env`, so the
forms work without it on a development instance.

*Information checked in October 2026; Google renames these products often. Sources are at the end.*

## v3, Enterprise, Cloud, Fraud Defense: what's what in 2026

- **"reCAPTCHA v3"** was the *classic* score-based product, with keys created at
  `google.com/recaptcha/admin` and checked through the `siteverify` endpoint with a
  secret key.
- Google has since **moved all reCAPTCHA keys into Google Cloud projects**. New classic keys
  stopped being available in 2024, and the automatic migration of the remaining ones finished in
  Q1 2026. Every key, old or new, is now managed in the **Google Cloud console**, on a page now
  called **"Google Cloud Fraud Defense"** (it used to be "reCAPTCHA Enterprise", and reCAPTCHA is
  now described as "part of Google Cloud Fraud Defense").
- So **"reCAPTCHA Enterprise" is no longer a separate thing to choose**. It's the
  Google Cloud reCAPTCHA product that every key now lives in. A "score-based key" created there
  is today's equivalent of a v3 key.
- Billing tiers apply per Cloud project. **Essentials** (no billing account) is free up to
  **10,000 assessments per month**; beyond that, requests are refused until the next month. Enabling
  billing switches to the paid tiers. MyTube only uses an assessment when a protected form is
  submitted, not on page views, so a small site stays far below that limit.

Two ways remain to verify tokens on the server. MyTube supports both (`RECAPTCHA_MODE`):

| | `assessment` (recommended) | `siteverify` (legacy) |
|---|---|---|
| Server API | `recaptchaenterprise.googleapis.com/v1/projects/…/assessments` (CreateAssessment) | `www.google.com/recaptcha/api/siteverify` |
| Credentials | Cloud **project ID** + an **API key** | The key's **legacy secret key** |
| Browser script | `enterprise.js` / `grecaptcha.enterprise` | `api.js` / `grecaptcha` |
| Google's position | Recommended for new integrations | Kept for legacy integrations and plugins |
| Over quota | Fails closed (HTTP 429): MyTube refuses strict forms with an "unavailable" message | Returns success with a fixed score of 0.9, so protection silently stops |

## Setup (assessment mode, recommended)

### 1. Create or choose a Google Cloud project

1. Open <https://console.cloud.google.com/> with your Google account.
2. Create a project (for example "mytube"), or select an existing one. Note its **project ID**,
   shown in the project selector (it looks like `mytube-123456`, not the display name).
3. No billing account is needed for the free Essentials tier.

### 2. Create a score-based key

1. In the console, open **Google Cloud Fraud Defense** (search "reCAPTCHA" or "Fraud Defense"
   in the top search bar). If asked, enable the API. The APIs a key needs are normally enabled
   when you create it.
2. **Keys** tab → **Create key**.
3. **Display name**: e.g. `MyTube labs`.
4. **Application type**: **Web**.
5. **Domain list**: click **Add a domain** and enter `labs.chryseis.be`, plus your production
   domain if it differs. A domain also covers its subdomains. Leave **Disable domain verification**
   off.
6. Keep the default **score-based** type. Don't enable challenges or the checkbox.
7. Click **Create key**. The **Key ID** shown at the top of the key's page is the **site key**.

Domain list changes can take up to 30 minutes to apply.

### 3. Create an API key for the server

1. Console → **APIs & Services** → **Credentials** → **Create credentials** → **API key**.
2. Edit the new key (**Edit API key**):
   - **API restrictions**: *Restrict key* → select **reCAPTCHA Enterprise API** only.
   - **Application restrictions**: optionally *IP addresses*, with the public IP address(es)
     your server uses for outgoing requests.
3. Copy the key. It goes into `.env` and must never appear in the browser.

### 4. Fill in `.env`

```ini
RECAPTCHA_SITE_KEY=<Key ID from step 2>
RECAPTCHA_MODE=assessment
RECAPTCHA_PROJECT_ID=<project ID from step 1>
RECAPTCHA_API_KEY=<API key from step 3>
RECAPTCHA_SECRET_KEY=
RECAPTCHA_MIN_SCORE=0.5
```

No restart is needed. `.env` is read on every request.

## Alternative: siteverify mode (legacy secret key)

Use this if you'd rather not create an API key, or you already have a migrated v3 key with its
secret. Note the quota behaviour in the table above.

1. Create the key as in steps 1–2 above, or reuse a migrated key.
2. On the key's details page, open the **Integration** tab and click **Use Legacy Key** (or
   **Integrate with a third-party service or plugin** if the key isn't set up for legacy use).
   A dialog shows the **legacy secret key**. Some documentation calls it the "private key".
3. `.env`:

```ini
RECAPTCHA_SITE_KEY=<Key ID>
RECAPTCHA_MODE=siteverify
RECAPTCHA_SECRET_KEY=<legacy secret key>
RECAPTCHA_MIN_SCORE=0.5
```

## How MyTube uses it

- **Actions**: `login`, `register`, `forgot_password`, `resend_verification`. They appear per
  action in the console's metrics, and the server checks that each token was issued for the
  expected action and for the domain in `SITE_URL`.
- **Token timing**: the token is requested at submit time (`assets/js/recaptcha.js`), because
  tokens must be verified within 2 minutes.
- **Strict forms** (registration, forgot password, resend confirmation): the request is refused
  if the score is below `RECAPTCHA_MIN_SCORE`, the token is invalid, or verification couldn't
  run (script blocked, Google unreachable, quota exceeded, wrong credentials).
- **Login is lenient**: an invalid token or a low score is refused, but if verification couldn't
  run, login still works, so a Google outage or a browser extension can't lock users out. Login
  is protected by throttling in any case (5 failures per identifier / 20 per IP per 15 minutes).
- **Badge**: Google's floating badge is hidden (`.grecaptcha-badge { visibility: hidden; }`), which
  Google allows if the page says the site is protected by reCAPTCHA. Each protected form shows
  "Ce site est protégé par reCAPTCHA."
- **Privacy**: since 2 April 2026 Google acts as a *data processor* for reCAPTCHA, and the site
  operator is the controller. Mention reCAPTCHA in the site's privacy notice. The script is only
  loaded on the pages with protected forms, not on video pages.

## Checking that it works

1. With the keys set, open `/videos/register`: the page source should load
   `https://www.google.com/recaptcha/enterprise.js?render=<site key>` (or `api.js` in siteverify
   mode), and the form shows "Ce site est protégé par reCAPTCHA."
2. Submit the "forgot password" form with any address. A normal browser gets the usual
   confirmation message.
3. In the console, the key's page shows the requests and the score distribution after a few
   minutes.
4. Problems are written to the PHP error log, prefixed with `reCAPTCHA`, for example:
   - `HTTP 400/403 …`: wrong project ID or API key, or the API key restrictions don't allow
     the reCAPTCHA Enterprise API
   - `action mismatch`, `hostname mismatch`: key or domain configuration
   - `low score`: tune `RECAPTCHA_MIN_SCORE` if legitimate users are refused (0.3 is more lenient)

For a test-only key, the key creation flow has an option (**Are you creating this key for testing
purposes only?**) that always returns a chosen score. Don't use it in production.

## Sources

- [reCAPTCHA Classic → Google Cloud migration overview](https://docs.cloud.google.com/recaptcha/docs/migration-overview)
- [Create a website key](https://docs.cloud.google.com/recaptcha/docs/create-key-website)
- [Add reCAPTCHA to web pages (`enterprise.js`)](https://docs.cloud.google.com/recaptcha/docs/instrument-web-pages)
- [Create an assessment (REST, API key)](https://docs.cloud.google.com/recaptcha/docs/create-assessment-website)
- [Legacy SiteVerify and legacy secret key](https://docs.cloud.google.com/recaptcha/docs/verify)
- [Console terminology (legacy secret key location)](https://docs.cloud.google.com/recaptcha/docs/reconcile-legacy-terminology)
- [Billing tiers](https://docs.cloud.google.com/recaptcha/docs/billing-information)
- [FAQ (hiding the badge)](https://docs.cloud.google.com/recaptcha/docs/faq)
