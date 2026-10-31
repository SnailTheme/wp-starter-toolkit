<!-- st-toolkit-agent-doc-version: 1.3.2 -->
# CLI And Toolkit

## Composer Commands

```bash
composer install
composer lint:php
composer lint:wpcs
composer lint:wpcs:fix
composer make-pot
```

`composer make-pot` generates the POT file in `/languages/` and normalizes
project-owned headers through `/core/tools/normalize-pot.php`.

## Node Commands

```bash
npm install
npm run dev
npm run build
```

Use `npm run dev` during active development to watch source assets for file
changes and keep sourcemaps available.

Use `npm run build` before committing. It creates production assets and removes
sourcemaps.

## Toolkit

The toolkit is installed as a Composer development dependency.
For dependency upgrades, copied-core updates and maintainer release ordering,
use [Updates and releases](updates_and_releases.md). These are separate from
simply installing the current lockfile or refreshing local instructions.

Public repository:

https://github.com/snailtheme/wp-starter-toolkit

Install local development notes:

```bash
composer st-toolkit init
composer toolkit:init
```

Refresh toolkit-managed local development notes without updating core files:

```bash
composer toolkit:docs-update
composer toolkit:docs-update -- --force --yes
```

Common commands:

```bash
composer toolkit:doctor
composer toolkit:init
composer toolkit:docs-update
composer toolkit:core-check
composer toolkit:core-update:dry-run
composer toolkit:core-update -- --yes
composer toolkit:core-rollback -- --yes
composer toolkit:blocks-list
composer toolkit:blocks-install:dry-run elementals
composer toolkit:blocks-install elementals
composer toolkit:blocks-install:dry-run slider hero-slider
composer toolkit:blocks-install slider hero-slider -- --yes
composer toolkit:ui-list
composer toolkit:ui-status
composer toolkit:ui-install:dry-run blueprint
composer toolkit:ui-install blueprint
composer toolkit:components-list
composer toolkit:components-install mega-menu -- --dry-run
composer toolkit:components-install mega-menu -- --yes
```

These Composer scripts are project wrappers around the toolkit binary installed
in `/vendor/bin/`. Prefer them in documentation and day-to-day usage so
developers do not need to know the vendor binary path.

For less-common toolkit commands, use the generic passthrough script:

```bash
composer st-toolkit core:check --theme=.
composer st-toolkit -- blocks:install slider hero-slider --theme=. --dry-run
```

Use `--` before toolkit arguments when an option name may also be a Composer
option, such as `--dry-run`.

Toolkit core updates are for package-owned files only.

Expected update targets:

- `/core/**`
- `/assets/scss/core/**`
- `/assets/scripts/core/**`
- `/assets/css/core/**`
- `/assets/js/core/**`

The manifest has six explicit legacy flat core-admin/core-customizer removals
outside those directories. Review those named migration paths; do not broaden
them into project-asset wildcards.

Do not use toolkit core updates as a way to change project-specific code in
`/inc/`, templates, generic assets, or local blocks.

Before confirming a destructive operation, inspect its dry run and check every
planned path against that operation's ownership. Stop on unexpected targets
rather than broadening globs, bypassing preflight or treating `--yes` as a fix.
Keep the backup location and distinguish a completed installation from a failed
build that left files in place. An exit-zero checksum check verifies packaged
bytes; runtime behavior still needs the appropriate theme/editor checks.

Package maintainers have separate `core:manifest`, `ui:profile-export` and
`ui:profile-verify` commands. Inspect the installed command's help first; older
toolkit releases may not include them. They validate/export packaged resources,
not client setup or theme updates. Mutating maintainer commands belong in a
toolkit Git checkout, never a client's installed vendor copy. See the public
[maintainer guide](https://github.com/snailtheme/wp-starter-toolkit#maintainer-tools)
for allowlists, checksum generation, workbench identity, backups and verification.

Toolkit docs updates are separate from core updates. Use `docs:update` when
you only want local instructions and skills refreshed. Initialization installs
`AGENTS.md`, focused guides and canonical `.agents/skills/` workflows, a
`CLAUDE.md` import, and thin `.claude/skills/wp-starter-*/` entrypoints pointing
to the shared workflows. No global agent configuration is changed.

Start Codex or Claude Code from the theme root or a subdirectory, and start a
fresh session after initial installation if new skills are not listed. Select
the matching skill by name in Codex, or use `/wp-starter-elemental-sections`
in Claude Code when composing a Section. See AGENTS.md for the full skill map.
Other application/cloud modes may not use local project skill directories.

These files are ignored by Git for normal Git deployments. Exclude them from
manual uploads too: `.gitignore` is not web-server access control. Updates skip
markerless customized files, including skills and existing Claude instructions,
even with `--force`. In SKILL.md, remove only the version comment after the YAML
header to opt out; keep the YAML header first. During equal-version prerelease
development use the explicit forced docs update shown above.
If a custom `CLAUDE.md` is preserved, add `@AGENTS.md` to it manually when you
want shared theme instructions imported; the toolkit does not merge custom text.

Toolkit block installs are separate from core updates and docs updates. Use
`blocks:install` when you want to copy a curated packaged block into the local
theme. Block installs can also install missing npm dependencies, copy required
shared source assets, and run `npm run build` when the packaged block declares
those requirements.

The `elementals` bundle installs its fixed `el-*` family in one operation:

```bash
composer st-toolkit -- blocks:install elementals --dry-run
composer st-toolkit -- blocks:install elementals --yes
```

Do not pass a destination slug for a bundle. All member paths and internal
references use the theme's configured block namespace. The toolkit validates
the complete pack and confirms overwrites before target writes, then creates
one backup and runs one build. A build failure leaves the installed files and
reports the backup; fix the issue and rerun `npm run build`.

The ten-member bundle includes FAQ. FAQ is also installable alone without
reinstalling other Elementals:

```bash
composer st-toolkit -- blocks:install el-faq el-faq --dry-run
composer st-toolkit blocks:install el-faq el-faq
```

FAQ requires shared core `1.2.0`. An old-core preflight failure is an instruction
to inspect `composer toolkit:core-check`, preview the core update, and apply it
when authorized before retrying. Updating docs alone does not install schema
APIs or blocks. The original nine foundation members remain bundle-only.

UI profile installs scaffold project-owned style sources and update the
committed `st-toolkit.json` profile receipt. Choose the profile once, before
project styling begins. Available starting points are:

- `bare` for the functional minimum
- `blueprint` for the conventional Sass theme layer
- `tailwind` for Tailwind CSS v4 through its official Vite plugin

Keep `/assets/scss/_st-toolkit-profile.scss` as a dedicated managed marker:
comments and one literal `$ui-profile: "PROFILE";` assignment, optionally with
`!default`. Put other Sass logic elsewhere. Commented examples, duplicate
assignments and computed/nested overrides do not establish the active profile.
Its value must agree with `st-toolkit.json`; do not edit either just to bypass
an installer preflight.

The first `ui:install` locks the selection. Do not treat UI profiles as themes
to toggle during development. Replacing a locked profile is an exceptional,
destructive operation:

```bash
composer toolkit:ui-install:dry-run tailwind
composer toolkit:ui-replace tailwind
```

The `toolkit:ui-replace` wrapper supplies the required `--replace` and `--force`
flags. The toolkit still asks for confirmation, creates a backup before
changing profile files, and runs `npm run build` afterward. Restart a running
`npm run dev` watcher because Vite reads profile integration from
`st-toolkit.json` when it starts.

Component installs add reusable non-block behavior separately from core,
profiles, and blocks. Select the project UI first so the committed asset
pipeline is available. Toolkit-owned component integrations use
`/inc/components/toolkit/`, with their registered Sass and JavaScript sources
isolated below matching `toolkit/` directories. The installer checks the
component's required shared core version before writing files; run
`composer toolkit:core-update` first when instructed.
