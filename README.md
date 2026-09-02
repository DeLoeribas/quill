# Quill

A minimal personal RSS/Atom/JSON reader. Plain PHP backend (no framework), flat JSON file storage (no database), vanilla HTML/CSS/JS frontend (no build step).

## Features

- Add/remove feeds by URL (RSS 2.0, Atom, and JSON Feed supported). Pasting a plain site URL instead of a direct feed URL auto-discovers any feed(s) the page declares (`<link rel="alternate">`) and offers them to pick from
- Collapsible folder tree in the sidebar to group feeds, plus an "Unread" view (below "All Items") showing only unread items across every feed
- Enable/disable individual feeds — a disabled feed keeps its existing items but is skipped by both manual and scheduled refresh
- Read/unread tracking with unread counts
- Save items you want to keep — a "Saved" view appears below "Unread" once you have at least one, showing only saved items across every feed
- Save a search as a named shortcut in the sidebar, showing a live count of how many items currently match it
- Manual refresh (button, with a "Updated Xm ago" indicator in the header) and scheduled refresh (cron)
- Selecting a feed shows its refresh interval in the header: feeds that declare their own cadence (RSS `<ttl>` or the Syndication module) show it as fixed text, while every other feed shows a clickable interval that opens a preset popup (15m–24h) to change it
- The open browser tab polls every 60 seconds and refreshes sidebar unread counts/badges live, so you can see new content has arrived without reloading. It deliberately never touches the item list you're currently looking at, so nothing you're mid-read on can vanish out from under you (e.g. an item dropping out of "Unread" the moment you finish reading it). Paused while the tab isn't visible or Settings is open
- OPML import (nested `<outline>` folders are preserved one level deep) — **replaces** your entire feed/folder set with the file's contents — and OPML export of your current feeds/folders
- Full backup/restore (Settings → Backup): downloads everything — feeds, folders, filters, saved searches, every item's content and read/starred state, and UI prefs — as a single file (a real `.zip` when PHP's ZipArchive is available, falling back to gzip or plain JSON depending on what your host supports). Restoring one is a full destructive replace, same idea as OPML import but broader in scope, with a confirmation prompt
- Single-user login (username + password, set up on first visit) gates every page and API request via a PHP session — no database, no third-party auth, just a bcrypt hash in `data/auth.json`
- Each feed shows its favicon in the sidebar: during refresh, the server fetches the feed's site and reads its real `<link rel="icon">` (falling back to `{site}/favicon.ico` if none is declared), caching the result on the feed so it's never re-fetched once resolved — no third-party favicon service involved
- Per-feed item storage is bounded: `MAX_ITEMS_PER_FEED` (default 300, in `src/config.php`) prunes oldest *read* items past that count during refresh, keeping unread items forever. `HARD_MAX_ITEMS_PER_FEED` (default 1000) is a safety net on top of that — if a feed accumulates more unread items than the soft cap alone can prune, oldest items are dropped regardless of read status once this hard ceiling is hit.

## Local setup (macOS)

Modern macOS doesn't ship PHP, so install it via Homebrew first:

```
brew install php
```

Then, from this project directory:

```
cp src/config.sample.php src/config.php   # already done if you're reading this after setup
php -S localhost:8000 -t public
```

Open http://localhost:8000/ in a browser.

Data is stored under `data/` as JSON files — nothing else to configure for local use. The first time you open the app you'll be asked to create a username and password (stored as a bcrypt hash in `data/auth.json`); every page and API request requires being logged in via that session afterwards.

**Lost your username or password?** There's no in-app recovery flow (no email involved anywhere in this app). Delete `data/auth.json` directly on the server (SSH, FTP, or your host's file manager) and reload the app — `data/auth.json` missing means no login is configured yet, so you'll get the first-run "Create your login" screen again and can set a new username/password from scratch. Your feeds and articles are untouched — those live in `data/feeds.json`/`data/items/`, not in `auth.json`.

## Using the app

- **Mobile**: below ~768px wide, the sidebar becomes a slide-in drawer (☰ button in the header opens it, tap the dimmed backdrop or Escape to close). Picking a folder/feed/All Items/Unread closes the drawer automatically; collapsing a folder or using a feed's action buttons (rename/remove/etc.) does not. Buttons that only appear on `:hover` on desktop (feed actions, the per-item read toggle) are always visible on touch devices instead, since touch has no hover state. Opening Settings closes the drawer and vice versa, so they never overlap.
- **Sidebar**: "All Items" and "Unread" at the top, then one collapsible section per folder (click the ▾/▸ chevron to collapse/expand — this is remembered across reloads). Feeds without a folder appear under "Ungrouped". Click a folder or feed name to filter the item list to it — this selection is remembered across reloads too (falls back to "All Items" if the feed/folder was since deleted). Feeds within each folder (and "Ungrouped") normally keep their manual/insertion order; turn on "Sort feeds within folders alphabetically" in Settings to list them A–Z instead, remembered across reloads.
- **Sort order**: the "Newest first"/"Oldest first" button in the item list header flips chronological order for whatever you're currently viewing, remembered across reloads. "Unread" keeps its own independently remembered sort order — toggling it there doesn't affect the order used everywhere else (All Items, folders, individual feeds, Saved), and vice versa.
- **Search**: the search box in the header searches title + summary + feed name across *every* feed, live as you type (ignores whatever folder/feed you had selected). Clear it (native ✕ or empty the box) to go back to what you were viewing. Matching is accent-insensitive in both directions — searching "cafe" finds "café" and vice versa.
- **Saved searches**: while viewing search results, click "Save" next to the search box to name and save that query. Saved searches appear in the sidebar (below "Saved", above your folders) showing a live count of how many items currently match; click one to view its results, or hover it and click the ✕ that appears to delete it (only removes the saved shortcut, not any items).
- **Feed row actions**: hovering a feed (or always, on touch devices) reveals a refresh icon to force-refresh just that feed, a ✕ to remove it (click again within 3 seconds to confirm), and a ⋮ button (or right-click the row) opening a menu with **Rename feed…** (custom title, defaults to whatever the feed itself reports), **Edit content filters…** (see below), **Move to folder…**, and **Disable feed (stop refreshing)** / **Enable feed** (a disabled feed is shown dimmed; existing items stay, refresh just skips it).
- **Refresh interval**: selecting a feed shows how often it refreshes, below its URL in the header. If the feed declares its own cadence (RSS `<ttl>` or the Syndication module), that's shown as plain text — "defined in the feed" — since the publisher's value always wins again on the next successful refresh anyway. Otherwise it's a clickable button: click it to pick a new interval (15m/30m/1h/2h/4h/6h/12h/24h) from a popup, with a ✓ marking whichever preset is closest to the feed's current value.
- **Reading pane**: clicking an item selects it and marks it read, showing its title, feed/time, image, and sanitized summary in a persistent reading pane alongside the list (it doesn't open the article). Click "Show page" on an item, or press Enter while it's keyboard-highlighted, to open the article link itself in a new tab (also marking it read).
- **Thumbnails**: a small preview image shows next to an item when one can be found — checked in order: `media:thumbnail`/`media:content` (Media RSS), an image `<enclosure>`, an Atom image `<link rel=enclosure>` (for JSON Feed: its own `image` field, then an image `attachment`), then falling back to the first `<img>` in the item's own HTML content. If an item has no image of its own, the feed's own artwork is used instead when the feed declares one (RSS `<channel><image>`, Atom `<logo>`/`<icon>`, JSON Feed's `icon`/`favicon`), and if the feed doesn't declare artwork either (common for forum/Discourse feeds), the site's favicon is used as a last resort. Broken image links are hidden automatically rather than showing a broken-image icon. If an item was first stored before its source added an image for it, a later refresh backfills the thumbnail once one becomes available — an item that already has an image is never overwritten.
- **Toggle an item's read status directly**: hover an item and click the ✓/○ button on the right (✓ = mark read, ○ = mark unread) — without opening the article or expanding its summary. You can also just hover an item and press the space bar instead of clicking the button (ignored while typing in a text field).
- **Save an item**: hover an item and click the bookmark button, or hover it and press `s` (ignored while typing in a text field) — same interaction as the read/unread toggle, but independent of read state (saving doesn't mark read, unsaving doesn't mark unread). A saved item's bookmark icon stays visible even without hovering, so you can spot what you've kept at a glance.
- **Keyboard navigation** (desktop): with focus outside any text field, ↑/↓ (or j/k) moves a highlighted selection up and down the current item list (scrolling it into view as needed), and Enter opens the highlighted item's article link in a new tab and marks it read (same as its "Show page" button — unlike clicking the item, which only selects it in the reading pane). ←/→ switches which pane has keyboard focus (shown with a subtle accent outline) between the sidebar and the item list. While the sidebar has focus, ↑/↓/j/k/Home/End move through its feeds and folders, switching the item list to each one immediately as you move — clicking either pane with the mouse also gives it keyboard focus.
- **Delete a folder**: hover it and click the ✕ that appears (confirm within 3 seconds) — this deletes it **and every feed inside it**, including their stored items, not just an ungroup.
- **Per-feed content filters**: open a feed's ⋮ menu → Edit content filters… to set a comma-separated list of strings. During refresh, any incoming item whose title or summary contains one of them (case-insensitive substring match) is skipped entirely and never stored. Feeds with active filters show an eye-slash icon + count badge in the sidebar at all times (hover it to see the filter list). Filtering only applies to items fetched *after* the filter is set — it doesn't retroactively remove already-stored items.
- **Settings** (⚙ button in the header) holds everything that isn't needed on every visit: a "Sort feeds within folders alphabetically" toggle, adding a feed by URL, creating a new folder, importing an OPML file (⚠️ deletes every existing feed/folder and their stored items first, with a confirmation prompt — it's a full replace, not a merge), exporting your current feeds/folders as an OPML file, downloading a full backup or restoring from one (see "Full backup/restore" above), and logging out. Close it with the ✕, Escape, or by clicking outside the panel. The header itself only keeps what you'd use every session: search and "Refresh all".

## Scheduled refresh

The manual "Refresh all" button in the UI only refreshes while your browser is open. To keep feeds up to date in the background, run `cron/refresh.php` periodically — it refreshes every feed that's past its `refresh_interval_minutes` (default 60) and logs one summary line per run to `data/cron.log` itself (via internal, lock-protected file writes — this works the same whether the script is run manually, via cron, or via launchd).

**Important:** don't also redirect cron's/launchd's own stdout to `data/cron.log` — the script already writes there itself, so redirecting the process's stdout to the same file double-logs every line. Point any `>> ...` redirect or `StandardOutPath`/`StandardErrorPath` at a *different* file (e.g. `data/cron-stderr.log`), used only to catch unexpected PHP fatals/crashes that bypass the script's own logging.

A feed only shows up as `refreshed` once `refresh_interval_minutes` (60 by default) has actually elapsed since its last fetch — until then it's correctly reported as `skipped`. Disabled feeds always show as `disabled`, regardless of timing.

`refresh_interval_minutes` defaults to `DEFAULT_REFRESH_INTERVAL_MINUTES` in `src/config.php` (60 out of the box) for every feed — edit that constant to change the default for feeds that don't set their own, or change it per-feed from the app itself (see "Using the app" → Refresh interval). The exception: an RSS 2.0 feed that declares its own cadence — a plain `<ttl>` element, or the Syndication module's `<sy:updatePeriod>`/`<sy:updateFrequency>` pair — has that value applied to *its own* `refresh_interval_minutes` instead, re-checked on every successful fetch; such a feed is marked `refresh_interval_locked` and can't be overridden from the app, since the publisher's value would just win again on the next fetch anyway. Atom and JSON Feed have no equivalent field, so this only ever affects RSS feeds that opt into declaring it; every other feed just follows the config default (or whatever you've manually set) until you change it again.

Note that this interval only governs the *unattended background* trigger below — the "Refresh all" button always force-refreshes every feed immediately, ignoring it entirely. A forced refresh also skips the conditional `ETag`/`If-Modified-Since` headers normally sent to avoid re-downloading unchanged feeds, so it always re-fetches and re-parses the full body — this matters because a `304 Not Modified` response never gets parsed, so a feed's declared `<ttl>` could otherwise never be (re-)detected on a feed whose content happens not to have changed. Scheduled/cron refreshes are unaffected and still use conditional GET for efficiency.

Pick **one** of the three options below. If you're not sure whether your host gives you shell/SSH access, use Option A — it works everywhere.

### Option A: URL-based cron (works on any host, no shell access needed)

Many shared hosts don't offer real cron/SSH access — only a web control panel with its own "URL cron" field, or nothing at all. `public/cron.php` exists for exactly that case: it's a normal URL, triggerable by anything that can make an HTTP request on a schedule — your host's own URL-cron feature, a free external pinger like cron-job.org, or a scheduled GitHub Actions workflow.

It's disabled by default (returns a 403). To enable it:

1. **Generate a token.** In a terminal, on the machine/hosting account running the app:
   ```
   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
   ```
   This prints a random hex string — that's your token. Treat it like a password.
2. **Add it to your live config.** Open `src/config.php` (your actual config — not `config.sample.php`, which is just the template) and add, near the other `define(...)` lines:
   ```php
   define('CRON_TOKEN', 'paste the value you generated here');
   ```
   `src/config.php` is gitignored, so the token is never committed.
3. **Build the URL**, combining your real domain with `/cron.php?token=` and that same value:
   ```
   https://your-domain.example/cron.php?token=paste-the-same-value-here
   ```
4. **Give that URL to whatever will request it on a schedule**, every 15–30 minutes — pick whichever fits your host:
   - Your host's control panel, if it has a "URL Cron" / "Cron via URL" field — paste the URL there.
   - A free external pinger like cron-job.org — add a new job with that URL and interval.
   - A scheduled GitHub Actions workflow that just `curl`s the URL.

Each time the URL is requested with the right token, it refreshes every feed and logs one summary line to `data/cron.log`, same as the other two options below. A wrong or missing token returns a 403 and does nothing.

Don't paste the full URL (with the token in it) anywhere public — a public repo, a public status page, etc. Anyone who has it can trigger a refresh, though that's all the endpoint can do; it has no other access.

### Option B: cron (if you have shell/SSH access)

```
crontab -e
```

Add a line (adjust the PHP path if `which php` differs, and the project path to match where you cloned this):

```
*/15 * * * * /path/to/php /path/to/rss/cron/refresh.php >> /path/to/rss/data/cron-stderr.log 2>&1
```

### Option C: launchd (macOS-native, local/dev use)

Create `~/Library/LaunchAgents/com.example.quill.refresh.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.example.quill.refresh</string>
  <key>ProgramArguments</key>
  <array>
    <string>/path/to/php</string>
    <string>/path/to/rss/cron/refresh.php</string>
  </array>
  <key>StartInterval</key><integer>900</integer>
  <key>StandardOutPath</key><string>/path/to/rss/data/cron-stderr.log</string>
  <key>StandardErrorPath</key><string>/path/to/rss/data/cron-stderr.log</string>
</dict></plist>
```

Load it:

```
launchctl load ~/Library/LaunchAgents/com.example.quill.refresh.plist
```

If you're editing an already-loaded agent, reload it so the change takes effect:

```
launchctl unload ~/Library/LaunchAgents/com.example.quill.refresh.plist
launchctl load ~/Library/LaunchAgents/com.example.quill.refresh.plist
```

## Deploying to shared PHP hosting

1. **Don't upload your working copy directly** (Finder/rsync/scp of this whole folder) — it has your real `data/feeds.json`, `data/auth.json`, `data/items/`, logs, and `src/config.php` sitting on disk locally, all of which are gitignored precisely so they never get uploaded by accident. Instead run:
   ```
   bin/package-for-deploy.sh
   ```
   which uses `git archive` to build a clean copy in `./deploy/` containing only what's tracked in git — no personal data, ever. It also stamps `deploy/src/version.php` with `git describe --tags --always` for the commit being packaged (shown in the app's footer — see "Versioning" below), and creates `deploy/src/config.php` from `config.sample.php` for you, with `CRON_TOKEN` left blank — the script prints a reminder to generate one yourself and set it in that file before uploading if you want scheduled refresh via `public/cron.php` (see "Scheduled refresh" → Option A below); store the generated value somewhere safe, since it can't be recovered from the server afterwards. Upload the contents of `./deploy/`. Point the site's **document root** at `public/` if your host allows it — this keeps `src/` and `data/` outside the web-exposed folder entirely, which is the safest setup.
2. If your host only exposes a single folder (e.g. `public_html` *is* the repo root, `public/` can't be the doc root), the `.htaccess` files in `data/` and `src/` deny all direct requests to those folders as a fallback — but a document root above the repo is still preferable when available.
3. **Optionally, also protect the site with HTTP Basic Auth** as an extra layer in front of the app's own login (step 4 below is what actually keeps strangers out on its own — this is defense-in-depth on top of that, and is disabled by default). `public/.htaccess` ships with the relevant lines commented out:
   ```
   # AuthType Basic
   AuthName "Quill"
   # AuthUserFile /home/yourusername/rss/.htpasswd
   # Require valid-user
   ```
   To enable it: generate a `.htpasswd` file at the project root (outside `public/`, never committed — it's gitignored):
   ```
   php -r "echo 'yourusername:' . password_hash('yourpassword', PASSWORD_BCRYPT) . PHP_EOL;"
   ```
   Paste the output as the full contents of `.htpasswd`, then uncomment the three `AuthType`/`AuthUserFile`/`Require` lines above — `AuthUserFile` **must** be an absolute path matching wherever the project actually lives on that server. This only works on real Apache/LiteSpeed hosting — PHP's built-in `php -S` dev server ignores `.htaccess` entirely, so it has no effect locally either way (expected). If you later remove `.htpasswd` again, make sure to re-comment those same lines — leaving `AuthUserFile` pointing at a now-missing file breaks every request with a 500 error instead of just removing the extra layer.
4. The app's own login (see "Local setup" above) protects every page and API request on its own via a PHP session — this works even on hosts where you can't set a document root or rely on `.htaccess`/Basic Auth at all (the single-folder fallback in step 2). Credentials live in `data/auth.json`, outside the web-exposed folder and denied directly by `data/.htaccess` regardless.
5. Schedule feed refreshes — see "Scheduled refresh" above for all three options. Shared hosts vary a lot here: some give real SSH/crontab access (Option B), some only a control-panel "Cron Jobs" page that still runs a shell command via a fixed PHP CLI path you'd get from your host's docs or `which php` over SSH, and some offer neither — only a "URL cron" field, or nothing at all. **Option A (`public/cron.php`, token-protected) is the one guaranteed to work regardless of which of those you get**, since it only needs an HTTP request, not shell access.

## Versioning

The app's footer shows a version, e.g. `v1.0.0` or `va1b2c3d`. There's no hand-maintained version constant — each run of `bin/package-for-deploy.sh` stamps `src/version.php` with `git describe --tags --always` for whatever commit it's archiving at that moment, so it's always accurate to what's actually deployed even though every install is packaged and uploaded independently. Running the app locally (unpackaged) shows `vdev`, since `src/version.php` only ever exists inside a packaged copy.

With no tags yet, `git describe` falls back to a short commit hash. To get real version numbers instead, tag a release (without a `v` prefix — the footer already adds one):

```
git tag -a 1.0.0 -m "1.0.0"
git push origin 1.0.0
```

The next `bin/package-for-deploy.sh` run on that commit will then stamp `1.0.0` instead of a hash; a few commits past a tag it'll read something like `1.0.0-3-gabc1234` (3 commits after the `1.0.0` tag, at commit `abc1234`).

The footer also checks GitHub for a newer tagged release than the one deployed (cached server-side for up to `GITHUB_VERSION_CACHE_SECONDS`, 6 hours by default). When the deployed version is behind, an "Update available: vX.Y.Z" badge appears linking to the repo's tags page — it stays hidden otherwise (including on an untagged/`dev` build, since there's nothing meaningful to compare).

## Project layout

```
public/          document root: index.html, style.css, app.js, api/*.php, cron.php
src/             PHP classes: Storage, Auth, FeedFetcher, FaviconResolver, FeedDiscovery, RefreshService, OpmlImporter, YouTubeResolver
data/            feeds.json, auth.json, items/<feed_id>.json, cron.log
cron/refresh.php CLI scheduled-refresh entry point (see also public/cron.php, its HTTP equivalent)
```

Every `public/api/` endpoint requires being logged in (`Auth::requireLogin()`, checked for every method including GET) except `auth.php` itself. `public/cron.php` lives outside `api/` and isn't part of this list — it's unauthenticated by design (see "Scheduled refresh" above), gated by its own `CRON_TOKEN` instead of a login session: `feeds.php` (GET/POST/PATCH/DELETE — PATCH also toggles `enabled`, sets `filters`, and sets `refresh_interval_minutes` (ignored if the feed's interval is locked to its own declared `<ttl>`); POST returns either `{feed}` on success or, if the URL isn't a feed itself, `{candidates: [{url, title}]}` for any feed(s) discovered on the page — see `src/FeedDiscovery.php`), `folders.php` (GET/POST/PATCH/DELETE — DELETE takes `?cascade=1` to also delete every feed in the folder), `items.php` (GET with `feed_id`/`folder_id`/`unread_only`/`starred_only`/`q` — `q` searches title + summary + feed name across every feed and overrides `feed_id`/`folder_id` scoping, POST to mark read/unread or star/unstar), `refresh.php` (POST, manual refresh), `import.php` (POST, OPML upload — deletes every existing feed/folder and their stored items first, then imports the file into a clean slate; response includes `feeds_removed`), `export.php` (GET, downloads current feeds/folders as an OPML file), `saved_searches.php` (POST to save the current query under a name, DELETE `?id=` to remove one — match counts are computed and included whenever `feeds.php` GET returns the feed/folder list), `backup.php` (GET/POST, downloads a full-fidelity backup — feeds, folders, filters, saved searches, every item, UI prefs — as a zip, gzip, or plain-JSON file depending on server support), `backup-restore.php` (POST, raw file body — restores a backup file, replacing feeds.json and every feed's items outright; accepts the older gzip/plain-JSON backup formats too), `settings.php` (GET/PATCH `default_refresh_interval_minutes` — PATCH applies it to every existing feed too, not just new ones), `auth.php` (GET returns `{configured, authenticated}`; POST `{action: 'setup'|'login'|'logout', ...}` — `setup` only works once, before a login exists).
