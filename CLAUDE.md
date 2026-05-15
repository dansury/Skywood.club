#1
Lookup file -> spec mapping in `spec.md`. It is a thin navigation index — read once, then open ONE `/spec/<module>.md` for the relevant area. Never read the whole `/spec/` folder.

#2
For reference details (signatures, DB schemas, algorithms, flows, user path, streak-unlock, confirm_pay, CJM parser, OGG-chunking, deep-links): open exactly one `/spec/<module>.md` that matches the module you are editing. If the detail is missing there, read the source file — do NOT pull another `/spec/<module>.md` unless needed.

#3
Evaluate task complexity. Before reading any spec, check `TODO.md` first. Incomplete tasks are written to `TODO.md` (numbered). Completed tasks are removed from `TODO.md`.

#4
**Spec-driven workflow** — for any new feature or non-trivial change:
1. Read the relevant `/spec/<module>.md`.
2. Update that spec to describe the planned change (signatures, tables, flows, configs) — before writing any code.
3. Implement code to match the updated spec.
4. Log changes in `/changelog/changes-{date}-{time}.md`. Do not report in dialog — the file is sufficient.

Specs describe actual functionality only — never changelogs or version history. For pure bug fixes that require no design decisions, step 2 may be skipped; update the spec after the fix if its content was inaccurate.

#5
Do not bloat `bot.py`. Principally new functionality goes into new files. Additions to existing functionality go into the file where that functionality lives.

## Language & Format Rules

- **All files in English**: `spec.md`, `/spec/*.md`, `legacy.md`, `changes-*.md`, all skills
- **Skills maximum compactness**: preserve functionality, minimize prose. Use step lists, tersest notation (`func(param:type)->type`, `TABLE col TYPE`)
- **No filler**: facts only — names, types, values, relations. No "this does X" explanations

## Spec structure

- `spec.md` — navigation index only (file -> spec mapping, rules, file list). No details.
- `/spec/<module>.md` — per-module details (signatures, tables, algorithms, flows).
- `/spec/artifact0.md` — artifact0 module spec (psychographic contour, P1–P12, prompt chain).
- `/legacy.md` — legacy / dead code inventory awaiting decision (delete vs archive).
- `TODO.md` — incomplete tasks; check before reading any spec; remove entries when done.
