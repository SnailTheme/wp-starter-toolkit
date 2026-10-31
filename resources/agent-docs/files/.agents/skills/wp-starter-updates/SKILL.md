---
name: wp-starter-updates
description: Plan and validate toolkit dependency or shared-theme-core upgrades, refresh local guidance independently, or prepare an explicitly requested starter maintainer release.
---
<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Update Toolkit And Theme

Read [Updates and releases](../../updates_and_releases.md) and the target
repository's instructions. Identify whether this is a generated client project
or starter-maintainer work before selecting commands or branches.

- Inspect the worktree, Composer constraint/lockfile, actual installed toolkit
  and core versions, UI receipt and project overrides. Preserve local work and
  establish a recoverable baseline; do not clean up by discarding changes.
- Dependency, docs and core updates are independent. `composer install` uses
  a present lockfile; `docs:update` uses the already installed toolkit. Preview
  a targeted toolkit Composer update when a newer package is requested, and
  review its dependency/lockfile diff rather than running a broad update.
- Upgrade copied core only when requested. Inspect core-check and the complete
  dry run, including removals and override/hook compatibility. Require a newer
  packaged core for an ordinary upgrade; the check's zero exit status is not
  that gate. Reinstall/repair/downgrade requires separate authorization. Retain
  the backup. Core updates do not refresh project templates, generic assets,
  installed blocks, components, UI profiles or their receipts. Do not reinstall
  a locked UI as a routine upgrade.
- Refresh local docs separately. Respect each guide's version and markerless
  ownership, including Claude adapters; do not force-update customized files
  or alter global agent settings. Use the installed command's help when needed.
- For an authorized starter release, follow toolkit-first dependency order.
  Validate resources/fresh installs, wait for the approved stable toolkit tag,
  then update/test clean theme next. Preserve fixture branches and never merge
  them wholesale. Keep CLI/theme/core/profile/guide versions separate and tag
  only the exact verified main commit after approval; never move public tags.

Use [validation](../wp-starter-validation/SKILL.md) for build/lint/POT/audits
and actual editor/frontend checks. Report versions, changes, backups and gaps.
Rollback must match the failed layer; core rollback does not restore dependency
lockfiles, UI scaffolds or databases. A failed core write has no automatic
rollback and may omit its backup path: stop, verify the attempt's backup against
the saved baseline, and do not assume a newest/partial backup is complete.
Commit/push/tag/deploy only when authorized.
