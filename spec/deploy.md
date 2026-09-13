# spec/deploy.md

How the site reaches the PHP hosting and stays updated.

## Layout

The repository root is the web root on the hosting (e.g.
`https://new.skywood.club/NEW/`). All asset/API references in the HTML are
**relative**, so the site works from any subfolder.

- `index.html`, `order.html` — static pages.
- `css/`, `js/`, `assets/` — static assets.
- `api/` — PHP backend (`spec/backend.md`).
- `data/` — `products.json` (catalog) + runtime `orders.json`,
  `skywood.sqlite` (DB), `.cdek-token.json`, `.installed`. `data/.htaccess`
  denies web access.
- `../.env` — credentials, **one level above the web root** (outside
  `public_html`), so the file is unreachable over HTTP and untouched by
  `pull.php`. A legacy `.env` in the web root still works as a fallback
  (`.htaccess` denies web access to it).
- `.env.example` — template with every key and no secrets; copy it to `../.env`.
- `.htaccess` — `DirectoryIndex`, 301 redirects from the old WordPress URLs,
  `/admin` → `admin.php`, mod_rewrite (`api/.+` → `api/index.php`), deny rules
  for `.env*` / `pull-config.php` / `*.log` / `*.md`.
- `robots.txt`, `sitemap.xml` — SEO.
- `install.php` — installer.

## SEO

- `.htaccess` 301-redirects the old WordPress paths
  `/гамаки/гамак-палатка-skywood/` and `/подвесные-палатки/skywoodjet/` to `/`
  (with a percent-encoded fallback) to preserve search positions.
- `robots.txt` allows the site, blocks `/admin`, `/api/`, `/data/`, service PHP,
  and points to `sitemap.xml`.
- `index.html` / `privacy.html` carry `canonical`, Open Graph and `Store` JSON-LD.
- Full audit + remaining recommendations: `SEOrecommend.md` (claude-seo method).

## pull.php — update from GitHub

`pull.php` + `pull-config.php` live in the site folder (preserved across
pulls). Opening `pull.php` downloads the repo ZIP from GitHub and overwrites the
folder. Files not present in the repo (`orders.json`, `.cdek-token.json`,
`.installed`) are left untouched.

`pull-config.php`: `repo`, `branch`, `subdir` (empty = repo root), `secret`
(optional URL token), `gh_token` (fine-grained PAT, needs **Contents: Read** +
Metadata), `keep_files`, `timezone`.

`pull.php` and `install.php` are PHP 5.6+ compatible so they load even on hosts
with an outdated PHP; the rest of the API needs PHP 7.x.

## Auto-pull — the update check on every PHP request

`api/lib/autopull.php` (class `AutoPull`, vendored from `site_yacloud_openrouter`;
its spec lives there as `/spec/auto_pull.md`) plus the glue `sw_autopull_opts()` /
`sw_autopull_run()`. The switch is the admin tab **Обновление кода**
(`spec/admin.md`); it is meant for active development, off by default.

While `autopull_enabled = 1`, every PHP entry point — `admin.php`, `settings.php`
and `api/index.php`, called first thing, before any output — asks GitHub for the head
of the ref `pull.php` tracks:

- same commit → nothing happens and nothing is printed;
- new commit → `pull.php` is requested over HTTP (`?plain=1`) and deploys it, then the
  browser gets `302` back to the URL it asked for and sees the new code. API calls and
  XHR are checked but never redirected — their answer is data.

The static pages (`index.html`, `order.html`) never reach PHP, so they do not trigger a
check; opening the admin or any `/api/*` call does.

Credentials come from `pull-config.php` only: `repo`, `branch` / `pr_number`,
`gh_token` (or `GITHUB_TOKEN` in ENV, which wins), and the `pull.php` gate —
`password_hash` is turned into the `pull_auth` cookie `pull.php` issues itself
(`<expires>|HMAC-SHA256('pull-auth|<expires>', key = password_hash)`), the older
`secret` into `?token=`.

Settings (`settings` table): `autopull_enabled` (`0`/`1`), `autopull_interval`
(seconds between checks, `0` = every request), `autopull_url` (explicit `pull.php`
URL; empty = derived from the web root under `DOCUMENT_ROOT`).

State: `data/auto-pull.json` (`0600`) + `auto-pull.json.lock` — last check, head,
deployed commit, error, cooldown. A failed check stands the automation down for 120
seconds; `flock` keeps parallel requests from deploying at once. The deployed commit
comes from `pull-state.json` when the installed `pull.php` writes one, otherwise from
this file.

Limits: one GitHub API call per request at `autopull_interval = 0` (5000/h with a
token), and the deploy is a second HTTP request to the same host.

## install.php — first run

Open once after deploying. Checks PHP ≥ 7.2, extensions
(`curl`, `json`, `mbstring`, `ZipArchive`), `mod_rewrite`, creates/validates
`data/`, reports the `.env` it found and which integrations are configured. Writes
`data/.installed`. Safe to re-run; delete after the site works.

## BASE_URL

Payment callback URLs (`NotificationURL`, `SuccessURL`, `FailURL`) use
`BASE_URL` from the `.env`; if empty, the PHP API auto-detects it from the request.
Must point at the public site root.
