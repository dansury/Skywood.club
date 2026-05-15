# Skywood.club — project guide

Selling landing + shop for Skywood hanging tents. Node.js/Express backend,
static frontend, Tinkoff (Т-Банк) payments, CDEK delivery.

## Navigation

1. Read `spec.md` — a thin navigation index (file → spec mapping). Read once.
2. Open exactly one `/spec/<module>.md` for the area you edit
   (`frontend`, `backend`, `integrations`). Never read the whole `/spec/` folder.
3. If a detail is missing in the spec, read the source file.
4. Check `TODO.md` first if it exists — incomplete tasks live there (numbered).

## Spec-driven workflow

For any new feature or non-trivial change:
1. Read the relevant `/spec/<module>.md`.
2. Update that spec to describe the planned change (signatures, flows,
   configs) — before writing any code.
3. Implement code to match the updated spec.
4. Log changes in `/changelog/changes-{date}-{time}.md`. The file is
   sufficient — no need to report in dialog.

Pure bug fixes may skip step 2; update the spec afterwards if it was
inaccurate. Specs describe actual functionality only — never changelogs
or version history.

## Conventions

- `spec.md`, `/spec/*.md`, `/changelog/*.md`, `README.md` — in English.
- No filler in specs: facts only — names, types, values, relations.
- Don't bloat `src/server.js`. New functionality goes into new files
  (`src/routes/*`, `src/services/*`); additions go where the feature lives.
- Catalog data — `data/products.json`. Secrets — `.env` (never commit).
- `1/` holds MHT archives of the old WordPress site — reference only.
