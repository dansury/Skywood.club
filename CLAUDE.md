# Skywood.club — project guide

Selling landing + shop for Skywood hanging tents. Static HTML frontend,
PHP backend, Tinkoff (Т-Банк) payments, CDEK delivery. Runs on plain
shared PHP hosting; deployable as files via `pull.php`.

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

Pure bug fixes may skip step 2; update the spec afterwards if it was
inaccurate. Specs describe actual functionality only — never changelogs
or version history.

## Conventions

- `spec.md`, `/spec/*.md`, `README.md` — in English.
- No filler in specs: facts only — names, types, values, relations.
- Don't bloat `api/index.php`. New functionality goes into new files
  (`api/lib/*`); additions go where the feature lives.
- Catalog data — `data/products.json`. Secrets — `.env` (never commit).
- `1/` holds MHT archives of the old WordPress site — reference only.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
