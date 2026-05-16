# spec/deploy.md

How the site reaches the PHP hosting and stays updated.

## Layout

`public/` is the only deployable folder. Its contents become the web root on
the hosting (e.g. `https://new.skywood.club/NEW/`). It contains:

- `index.html`, `order.html` — static pages. All asset/API references are
  **relative**, so the site works from any subfolder.
- `css/`, `js/`, `assets/` — static assets.
- `api/` — PHP backend (`spec/backend.md`).
- `data/` — `products.json` (catalog) + runtime `orders.json`,
  `.cdek-token.json`, `.installed`. `data/.htaccess` denies web access.
- `.env` — credentials. `.htaccess` denies web access.
- `.htaccess` — `DirectoryIndex`, mod_rewrite (`api/.+` → `api/index.php`),
  deny rules for `.env` / `pull-config.php` / `*.log` / `*.md`.
- `install.php` — installer.

## pull.php — update from GitHub

`pull.php` + `pull-config.php` live in the deployed folder (preserved across
pulls). Opening `pull.php` downloads the repo ZIP from GitHub and overwrites the
folder with `pull-config.php`'s `subdir` (`public`). Files not present in the
repo (`orders.json`, `.cdek-token.json`, `.installed`) are left untouched.

`pull-config.php`: `repo`, `branch`, `subdir=public`, `secret` (optional URL
token), `gh_token` (fine-grained PAT, needs **Contents: Read** + Metadata),
`keep_files`, `timezone`.

## install.php — first run

Open once after deploying. Checks PHP ≥ 7.2, extensions
(`curl`, `json`, `mbstring`, `ZipArchive`), `mod_rewrite`, creates/validates
`data/`, reports `.env` and which integrations are configured. Writes
`data/.installed`. Safe to re-run; delete after the site works.

## BASE_URL

Payment callback URLs (`NotificationURL`, `SuccessURL`, `FailURL`) use
`BASE_URL` from `.env`; if empty, the PHP API auto-detects it from the request.
Must point at the public site root.
