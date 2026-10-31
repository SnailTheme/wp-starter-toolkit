---
name: wp-starter-validation
description: Verify theme changes before handoff or commit, including PHP standards, built assets, POT freshness, and WordPress editor/frontend behavior.
---
<!-- st-toolkit-agent-doc-version: 1.1.2 -->
# Validate A Theme Change

Read [CLI and toolkit](../../cli_and_toolkit.md) for available commands and
[Blocks and ACF](../../blocks_and_acf.md) when block behavior changed. Discover
the project's actual PHP/Composer/Node environment before running tools.

- Check branch, worktree and scope. Preserve unrelated work and never switch
  profiles or discard files just to get a clean test run.
- Run `composer validate --strict`, `composer lint:php` and
  `composer lint:wpcs` for PHP changes. Never run the automatic fixer as a
  substitute for validation without reviewing its changes.
- Run `npm run build` before a commit and check generated outputs, stale maps
  and CSS/Sass collisions. Run dependency audits when dependency/build tooling
  changes; do not silence advisories or force unrelated major upgrades.
- Run `composer make-pot` after PHP changes even if strings are unchanged:
  source-line references can change. Keep real developer-facing POT dates;
  CI normalization belongs only on temporary comparison copies.
- Test relevant blocks in the actual iframe and frontend: insert/nest/reorder,
  duplicate, field edits, save/reload, long/empty content, missing images and
  responsive layouts. For elementals, verify weighted columns, grid span
  clamping, custom container widths and native Wrapper controls.
- When variants or animations change, test all variants together, invalid/missing
  settings and field rerenders. Check scripts disabled, failed initialization,
  reduced motion and editor visibility: hidden content is not a successful
  animation. Use [AnimateCSS](../../animations.md) for the actual runtime limits.
- For structured-data or FAQ changes, use [Schemas and FAQ](../../schemas.md).
  Inspect the actual head graph, not just rendered block HTML. Verify safe
  JSON encoding, canonical IDs, consecutive breadcrumb positions, idempotent
  registration and rejected late calls. Test nested/synced FAQ discovery,
  cycles, Query Loop exclusion, disabled/protected/preview contexts, and
  unchanged ACF/WordPress context. Check multiple FAQs aggregate once without
  changing SEO-plugin output or executing shortcodes during collection.
  Verify visible answers match schema, incomplete rows are omitted, and
  native FAQ keyboard/first-open/single-open behavior survives duplication,
  save/reload, long content and each UI profile's responsive layouts.
- Finish with `git diff --check`. Report actual runtime versions, tests run,
  failures and untested platforms. A historical fixture or successful build
  alone does not prove runtime correctness or latest-WordPress support.

When changing toolkit installers, exports or validation logic, test observable
invariants in disposable fixtures, not just a successful command or matching
message. Hash actual written files against recorded checksums; compare the
source and out-of-scope files before/after. Cover dry-run and cancellation with
no writes, backups/recovery after a write failure, changed inputs after approval,
and clear nonzero failures. Human-readable write plans must precede confirmation;
JSON output must remain parseable on failures, including with PHP warnings enabled.
Preserve JSON object/list distinctions and validate element types before sorting
or comparing configuration; `{}` is not interchangeable with `[]` in Vite state.
For static PHP checks, imports, comments and deferred callbacks must not masquerade
as active helper/version declarations. Never execute package PHP just to inspect it.

Use disposable fixtures for destructive installer/failure tests and keep test
data/dependency trees out of product commits. Commit/push only when authorized;
passing checks do not authorize a release or a merge of a workbench into main.
