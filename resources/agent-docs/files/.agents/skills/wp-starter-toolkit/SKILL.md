---
name: wp-starter-toolkit
description: Initialize theme guidance or install, inspect, and update toolkit-managed docs, blocks, core, UI profiles, and components. Use for toolkit operations rather than ordinary implementation edits.
---
<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Operate The Theme Toolkit

Read [CLI and toolkit](../../cli_and_toolkit.md). Run from the target theme
root and inspect the installed command's help when its options are uncertain.
Do not assume the local package has unreleased commands.

- First setup is `composer install`, `npm install`, then
  `composer st-toolkit init`. Guidance/skills are local-only by default.
- Refresh guidance independently with `composer toolkit:docs-update`; this is
  not a core update. Markerless files are developer-owned even with `--force`.
  Equal-version prerelease refreshes may use `--force` when requested.
- Use `composer toolkit:doctor` and `composer toolkit:core-check` for diagnosis.
  Preview core updates before applying them, and preserve the reported backup
  for rollback. Never run core updates just because docs need refreshing.
- List blocks before installation. Standalone blocks take source/destination
  slugs; `elementals` takes no destination and installs its fixed family as one
  bundle. Review its dry run and conflicts, including the namespaced Wrapper
  spacing opt-in in theme.json. Shared dependencies and a final production
  build may be part of installation. Do not run npm or copy files around a
  failed preflight to make the command appear successful.
- Choose UI once before styling; inspect its status first. Replacing a locked
  UI deletes managed scaffold paths and requires explicit migration approval.
  A component install may require an installed UI/core version; do not bypass
  those checks or overwrite project integrations to satisfy them.

Report actual changes, conflicts, dependency/build results and backups. A build
failure may leave installed files for diagnosis; use the command's recovery
instructions and do not claim success. Initialization or installation is not
authorization to commit, push, tag, deploy, or alter global agent settings.
