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
- `.env` — credentials. `.htaccess` denies web access.
- `.htaccess` — `DirectoryIndex`, 301 redirects from the old WordPress URLs,
  `/admin` → `admin.php`, mod_rewrite (`api/.+` → `api/index.php`), deny rules
  for `.env` / `pull-config.php` / `*.log` / `*.md`.
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

## install.php — first run

Open once after deploying. Checks PHP ≥ 7.2, extensions
(`curl`, `json`, `mbstring`, `ZipArchive`), `mod_rewrite`, creates/validates
`data/`, reports `.env` and which integrations are configured. Writes
`data/.installed`. Safe to re-run; delete after the site works.

## BASE_URL

Payment callback URLs (`NotificationURL`, `SuccessURL`, `FailURL`) use
`BASE_URL` from `.env`; if empty, the PHP API auto-detects it from the request.
Must point at the public site root.
