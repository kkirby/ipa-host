# IPA Host

Self-hosted over-the-air (OTA) installs for your own iOS apps. Upload an `.ipa`, tap **Install** on your iPhone, done. It's plain PHP with SQLite: no Composer, no build step, no database server. Upload the folder and open it in a browser.

Built for one person: a single admin password, no user accounts.

## What iOS requires (read this first)

These are Apple's rules, not limitations of this app:

1. **HTTPS with a trusted certificate.** iOS refuses `itms-services://` installs over HTTP or with self-signed certificates. A free Let's Encrypt certificate is fine.
2. **The IPA must be signed for device distribution:** *Ad Hoc* ("Release Testing" in Xcode), *Development* ("Debugging"), or *Enterprise*. App Store/TestFlight exports **will not** install this way. The app detects and flags these.
3. **For Ad Hoc and Development, your device's UDID must be in the provisioning profile.** Use **Devices → Get device UDID** to read it from your phone, add it at developer.apple.com, then re-export the app. Each build page shows whether your registered devices are in its profile.
4. **Development builds on iOS 16+** need *Settings → Privacy & Security → Developer Mode* turned on.
5. **Profiles expire** after a year at most (7 days for free Apple IDs). Expired builds are flagged.

## Install

Requirements: PHP 8.1+ with `zip`, `pdo_sqlite`, `dom`, `zlib` and `mbstring` or `iconv`. Almost every shared host has these enabled by default.

1. Upload this folder to your host, e.g. `public_html/ipa/`.
2. Make sure PHP can write to `data/`. Usually it already can; if not, `chmod 775 data`.
3. **Straight away**, open `https://yourdomain.com/ipa/` and finish setup (site title, base URL, password). Until setup is done, anyone who finds the URL could claim it.
4. Drop an `.ipa` onto the page.

That's it. On Apache, the included `.htaccess` files block access to `src/`, `views/` and `data/`.

### nginx

nginx ignores `.htaccess`. Add this to your server block, or store data outside the web root (see below):

```nginx
location ~ ^/ipa/(src|views|data)/ { deny all; return 404; }
location ~ ^/ipa/(config|config\.sample)\.php$ { deny all; return 404; }
location ~ /\. { deny all; return 404; }

# Optional: let API uploads larger than 1 MB through (the default).
# Browser uploads are chunked and adapt to whatever limit is set.
client_max_body_size 2g;
```

Even without these rules, IPA and icon filenames are random 128-bit names, so they can't be guessed.

### Optional `config.php`

Copy `config.sample.php` to `config.php` to:

- `data_dir`: keep the database and IPAs outside the web root (recommended on nginx).
- `trust_proxy`: set to `true` behind Cloudflare or another reverse proxy, so HTTPS and client IPs are read from `X-Forwarded-*` headers.
- `debug`: show full error messages while troubleshooting.

## Features

**Uploading**
- Drag and drop, or tap to choose; multiple files are queued.
- Chunked uploads (≤ 8 MB per request), so PHP's `upload_max_filesize` doesn't matter and IPAs of any size work. Progress and speed are shown, network errors retry with backoff, and if a web server returns 413 the chunk size shrinks automatically.
- Optional release notes attached at upload time, editable later.
- Duplicate detection by SHA-256.
- Free-disk-space check before accepting an upload.

**IPA inspection**, all in pure PHP:
- Name, bundle ID, version, build number, minimum iOS, supported devices, SDK and Xcode version.
- App icon extracted and converted from Apple's "crushed" CgBI PNG format so browsers can show it. Builds that only ship `Assets.car` reuse the app's last known icon.
- Provisioning profile: type (Ad Hoc / Development / Enterprise / App Store), team, expiry, provisioned UDIDs, entitlements.
- Embedded extensions, widgets, watch apps and App Clips.
- Code-signature presence, and the full Info.plist viewable as JSON.
- Reads both binary and XML plists, and is hardened against malformed files.

**Installing**
- One-tap **Install** on iPhone/iPad, using signed, expiring manifest and IPA URLs (iOS fetches them without your login cookie).
- On a desktop, **Install** shows a **QR code** instead. Scan it with your phone to open a short-lived install page; no login needed on the phone.
- Install and download counts, plus the last-install time.
- HTTP Range support for resumable downloads.

**Apps and builds**
- Apps are grouped by bundle ID, with full build history.
- Profile badges warn about expired, soon-to-expire (14 days) and uninstallable builds.
- Retention setting: keep only the last *N* builds per app, with older ones deleted automatically.
- Delete individual builds or whole apps; files and orphaned icons are cleaned up.
- Download the original IPA.
- Search and filter apps.

**Share links**
- Public install pages for a specific build, or **"always latest"** links that always serve an app's newest build (bookmark one on your home screen).
- Expiry options: 1 hour, 1 day, 7 days, 30 days or never. View counts, and revoke at any time.

**Devices / UDIDs**
- **Get device UDID**: open it in Safari on your phone and install the temporary profile. The device reports its UDID, model and iOS version, then lands on a confirmation page.
- Add, edit or remove devices manually.
- Each build page shows whether each registered device is included in that build's profile.
- Export `devices.txt` in Apple's "Register Multiple Devices" format, or copy all UDIDs.

**API** (for CI, fastlane, or an Xcode post-archive script)
- Bearer-token auth. The token is stored hashed, shown once, and can be regenerated or revoked.
- `POST upload` (with optional notes and an auto-created share link), `GET apps`, `GET builds`, `POST share`, `POST delete`.
- Copy-paste `curl` examples on the Settings page.

```bash
curl -fsS -H "Authorization: Bearer $IPA_HOST_TOKEN" \
  -F "ipa=@MyApp.ipa" -F "notes=Fixed login" -F "share_hours=168" \
  "https://yourdomain.com/ipa/index.php?p=api&action=upload"
```

API uploads are a single request, so they are bound by `upload_max_filesize`/`post_max_size`. The bundled `.user.ini` and `.htaccess` raise these to 2 GB where the host allows it.

**Security**
- Password hashed with `password_hash`. Logins are rate limited (5 failures per 15 minutes per IP).
- "Keep me signed in" (60 days) with revocable tokens; "Sign out everywhere"; changing the password signs out other devices.
- CSRF protection on every state-changing request, and POST-only destructive actions.
- Strict Content-Security-Policy (no inline scripts), `X-Frame-Options`, `nosniff`, HSTS on HTTPS, and `noindex` everywhere.
- Install, QR and UDID links are HMAC-signed with an expiry (lifetime is configurable; default 24 hours).

**Activity log**: uploads, installs, downloads, share views, logins, failed logins, device registrations and deletions, with IP and user agent. Filterable and clearable.

**UI**: works on phone and desktop, follows the system light/dark mode, and needs no external CDNs (the QR library is vendored).

## Troubleshooting

| Symptom | Cause |
|---|---|
| Tapping Install does nothing | Not in Safari, or not HTTPS with a valid certificate. Check **Settings → Server**. |
| "Unable to install *App*" | The device's UDID isn't in the profile, the profile expired, or it's an App Store build. Check the build page. |
| "Cannot connect to *host*" | The base URL in Settings is wrong (e.g. `http://`, or the wrong path). |
| App installs but won't open | Development build: enable Developer Mode. Enterprise: trust the developer in *Settings → General → VPN & Device Management*. |
| Install link stopped working | Install/QR links expire (Settings → install link lifetime). Reload the page. |
| Uploads fail at 1 MB on nginx | They shouldn't: the uploader shrinks chunks on 413. If they still fail, raise `client_max_body_size`. |

## Files

```
index.php          entry point and router
src/               application code (Db, Auth, Plist, IpaParser, PngFix, Store, routes)
views/             templates
assets/            CSS, JS, vendored qrcode-generator (MIT)
data/              created at runtime: SQLite database, IPAs, icons, upload chunks
```

Back up `data/` to back up everything.
