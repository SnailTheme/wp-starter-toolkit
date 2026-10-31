<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Updates And Releases

## Pick The Update Boundary

These operations are separate; perform only the ones the project requested:

| Operation | What it changes |
| --- | --- |
| `composer install` | Installs the versions in the project's lockfile, or resolves dependencies when no lockfile exists. |
| Targeted Composer update | Updates the installed toolkit package and necessary dependencies within the approved constraint. |
| `composer toolkit:docs-update` | Refreshes managed local guides/skills from that installed package, not from GitHub live. |
| `composer toolkit:core-update` | Copies that package's shared core into the theme; it is not a whole-theme updater. |
| UI/block/component installer | A separate scaffold installation/replacement, with its own conflicts, receipts and build. |

Upgrading the toolkit dependency does not itself refresh copied docs/core or
reinstall project blocks, components or UI sources. Never copy a fresh starter
over a developed theme, reinstall a profile to upgrade it, or merge a demo
workbench to obtain an update.

## Project Update

Start in the target theme root. Inspect Git changes, installed toolkit/core
versions, `composer.json`/`composer.lock`, UI status, overrides and required
runtime versions. Save a recoverable project baseline without discarding local
work. Use `composer show snailtheme/wp-starter-toolkit` and
`composer toolkit:doctor`; the toolkit CLI version and `ST_WP_CORE_VERSION`
measure different things.

Preview a targeted dependency upgrade rather than updating every package:

```bash
composer update snailtheme/wp-starter-toolkit --with-dependencies --minimal-changes --dry-run
```

When authorized, run the same command without `--dry-run` and review the lockfile
diff. If the desired release is outside the root constraint, inspect its
compatibility requirements and obtain approval for changing that constraint;
do not silently switch a client to `dev-next`, ignore platform requirements,
or force unrelated dependency upgrades. Preserve the toolkit package identity.

Refresh instructions independently when requested:

```bash
composer st-toolkit -- docs:update --theme=. --dry-run
composer toolkit:docs-update
```

Normal docs updates use each file's content version; unchanged/newer files
are skipped. Markerless files remain developer-owned even with `--force`.
Read the dry run before an intentional forced refresh. Updating docs does not
require updating core or selecting another UI profile.

For a requested core update:

```bash
composer toolkit:core-check
```

Read the installed/packaged versions and `Update available` result, not just
the exit code: this diagnostic succeeds even when no upgrade is available.
For an ordinary upgrade, continue only when the packaged version is newer.
An equal-version repair/reinstall or a downgrade needs separate authorization;
`core:update` does not enforce a newer-version gate for you.

After that check and approval of the actual dry-run changes:

```bash
composer toolkit:core-update:dry-run
composer toolkit:core-update
```

Review all writes/removals and check project overrides against the changed
callbacks/hooks. The updater can replace locally edited package-owned files;
there is no three-way merge. The manifest's six named legacy flat core-asset
removals are explicit migration exceptions, not a wildcard over generic assets.
Preserve the reported backup and verify `/inc/`, templates, project assets,
block files and `st-toolkit.json` remain untouched outside those exceptions.

A failed copy/removal or post-write PHP validation can leave partial changes;
the core updater does not automatically restore them. Its backup path may not
be printed when the command fails. Stop before retrying, inspect the attempt's
`core` backup under `.st-toolkit/backups/`, and compare its files/absent-path
metadata with the saved pre-update baseline. Do not assume the newest backup
is complete: failure during backup creation can leave an incomplete directory.
Use the independent project backup when the toolkit backup cannot be verified.

If recovery is needed, select the verified core backup explicitly:

```bash
composer toolkit:core-rollback -- --backup=/absolute/path/to/reported-core-backup
```

Core rollback is not dependency, docs, UI or database rollback. Restore an
approved lockfile baseline separately and run `composer install` if dependency
recovery is also needed; do not assume one command reverses the whole upgrade.

Run [validation](skills/wp-starter-validation/SKILL.md), including production
builds, PHP/WPCS, POT regeneration after PHP changes and relevant editor/frontend
runtime checks. Use `npm ci` for a reproducible existing npm lockfile. Audit
dependencies and report remaining gaps. Commit lockfile and copied/generated
changes only when authorized; keep local guidance ignored.

## Starter Maintainer Release

This section applies only when working on the starter repositories, not a
generated client project. Read the repository's own branch/release rules first.

- Keep `main` stable and use clean `next` for integration. Develop block
  fixtures on `blocks-dev` and UI fixtures on `workbench/blueprint` or
  `workbench/tailwind`. Synchronize only distributable resources into toolkit
  `next`; never merge an installed workbench wholesale into clean branches.
- For shared-core changes, synchronize packaged core and validate
  `core:manifest --check`; update checksums with the maintainer command only
  after ownership/API review. Export/verify approved UI sources with the
  maintainer tools in a toolkit Git checkout. Test fresh installation, not
  just resource equality. Inspect [CLI and toolkit](cli_and_toolkit.md).
- Track toolkit CLI, theme stylesheet/npm, shared core, UI profile and individual
  guide versions independently. Bump only what changed, with matching `@since`
  for new APIs. Changed guides need matching file-version markers/manifest
  entries so normal docs updates deliver them. A docs-only change does not
  imply a new core/profile version.
- Validate toolkit tests and fresh generated-theme installs. Publish the
  toolkit through its approved merge/tag first; only then update clean theme
  dependencies to the actual public stable tag, regenerate its lockfile and
  validate without local path overrides. Never lock a release to an unpublished
  or unapproved development commit to bypass this order.
- After the approved theme merge, verify CI and tag the exact tested main
  commit, not a stale local branch. Never move an existing public tag. Update
  generator features/tests if contracts or documented capabilities changed;
  deploy only when needed and approved, then smoke-test a fresh Stable ZIP.

Passing tests do not authorize commits, pushes, merges, tags or deployment.
Follow the user's requested approval checkpoints and do not modify production
while conducting a local upgrade/release review.
