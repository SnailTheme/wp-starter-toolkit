# ST WP Starter Toolkit

Development toolkit for SnailTheme starter-based WordPress themes.

Package identity:

- Composer package: `snailtheme/wp-starter-toolkit`
- PHP namespace: `SnailTheme\WPStarterToolkit`
- CLI binary: `st-toolkit`
- framework: Symfony Console

## Current State

- active development branch: `next`
- stable releases are published from `main`
- repository is public on GitHub
- the source theme currently consumes this package through Composer VCS as a
  development dependency

## Install

Install in a theme or project as a development dependency:

```bash
composer require --dev snailtheme/wp-starter-toolkit
```

For local package development:

```bash
composer install
./bin/st-toolkit list
```

## Commands

Core commands:

```bash
composer toolkit:core-check
composer toolkit:core-update:dry-run
composer toolkit:core-update -- --yes
composer toolkit:core-rollback -- --yes
```

Block commands:

```bash
composer toolkit:blocks-list
composer toolkit:blocks-install:dry-run slider hero-slider
composer toolkit:blocks-install slider hero-slider -- --yes
```

UI profile commands:

```bash
composer toolkit:ui-list
composer toolkit:ui-status
composer toolkit:ui-install:dry-run blueprint
composer toolkit:ui-install blueprint

# Exceptional replacement of an already locked selection:
composer toolkit:ui-install:dry-run tailwind
composer toolkit:ui-replace tailwind
```

Component commands:

```bash
composer toolkit:components-list
composer toolkit:components-install mega-menu -- --dry-run
composer toolkit:components-install mega-menu -- --yes
```

Diagnostics:

```bash
composer st-toolkit init
composer toolkit:docs-update
composer toolkit:doctor
```

The starter theme exposes these root Composer scripts around the installed
toolkit binary. Package developers can still run `./bin/st-toolkit ...` from
this repository while working directly on the toolkit.

For less-common toolkit commands, the starter theme also exposes a generic
passthrough:

```bash
composer st-toolkit -- core:check --theme=/path/to/theme
composer st-toolkit -- blocks:install slider hero-slider --theme=/path/to/theme --dry-run
```

Use `--` before toolkit arguments when an option name may also be a Composer
option, such as `--dry-run`.

Every command supports `--theme` and `--json`. Write commands also support
`--dry-run` and `--yes`. `docs:update` also supports `--force` for refreshing
current-version managed docs while the package is still on a pre-release
branch.

## Init And Agent Docs

`init` installs local development notes into the target theme:

```text
AGENTS.md
.agents/
```

These files are ignored by Git by default. Each managed Markdown file starts
with its own content-version marker. `init` updates a file only when that
specific guide has a newer packaged version. If a developer removes the marker,
that file is treated as custom and is skipped.

`docs:update` refreshes toolkit-managed docs without updating core files or
installing blocks. Use it when documentation changes but the theme core should
stay untouched.

## Core Updates

`core:update` reads `ST_WP_CORE_VERSION` and `ST_WP_CORE_THEME_PATTERNS`
statically from `core/bootstrap.php`. It does not bootstrap WordPress.

The command writes only files declared by
`resources/core/st-wp-core-manifest.json`.

Current package-owned paths are:

- `core/**`
- `assets/scss/core/**`
- `assets/scripts/core/**`
- `assets/css/core/**`
- `assets/js/core/**`

It never edits:

- `/inc/`
- templates
- generic theme assets
- `/blocks/`

The core manifest also tracks checksums for bundled package-owned files so the
updater can verify what it owns.

## Block Installs

Blocks are installed from `resources/blocks/<block>/st-block.json`.

The elemental foundation is a fixed-member bundle:

```bash
composer st-toolkit -- blocks:install elementals --dry-run
composer st-toolkit -- blocks:install elementals --yes
```

It installs Wrapper, Container, Heading, Text, Columns/Column, Grid/Grid Cell,
and Content With Image together. Names follow the theme's namespace, such as
`acme/el-wrapper` in `blocks/acme-el-wrapper/`. Bundle members cannot be
renamed independently or installed with a destination argument.

The complete bundle is staged and validated before target writes. The plan
lists every overwrite, and confirmed installs create one file-scoped backup
and run one build. Unrelated project files are preserved. The native Wrapper
spacing opt-in is merged into its namespaced `theme.json` block settings,
without replacing the project's palette, layout, or other block settings.

Compose Sections from Wrapper plus meaningful elemental children, optionally
through Container. Native content stays in InnerBlocks; ACF holds additional
content such as images and element-specific settings. Native-only elements
have no dummy ACF groups. Tabs are not included in this first foundation pack.

Standalone blocks retain their existing source/destination syntax.

Example:

```bash
composer toolkit:blocks-install slider hero-slider -- --yes
```

This installs the packaged `slider` block into:

```text
<theme>/blocks/<namespace>-hero-slider/
```

The installer uses:

- `ST_WP_CORE_THEME_PATTERNS['block_namespace']` for the block namespace
- the block namespace as the block directory prefix
- `ST_WP_CORE_THEME_PATTERNS['block_category']` for the block category
- `ST_WP_CORE_THEME_PATTERNS['text_domain']` for translations
- stable `st_wp_core_*` helper calls without renaming them

For the source theme, `<namespace>` defaults to `stwp`. Generated themes can
change that value, and block installs follow the generated theme's configured
namespace automatically.

ACF local JSON source files can keep readable package keys such as
`group_stwp_slider` and `field_stwp_slider_fields_group`. During install, the
toolkit writes deterministic ACF-style keys and filenames for the destination
block. That keeps generated field keys unique without changing them on every
reinstall of the same destination block.

Shared assets, such as Splide resources, are checksum checked. Missing shared
assets are added. Changed shared assets are reported as conflicts and require
`--replace-shared-assets` for non-interactive replacement.

Missing npm dependencies are installed before files are written when the user
confirms the install or passes `--yes`. After block files, ACF JSON, and shared
source assets are written, the toolkit runs `npm run build` so generated assets
are refreshed.

## UI Profiles

UI profiles are project scaffolds stored in `resources/ui-profiles/`:

- `bare` provides Normalize, box sizing, responsive media, accessibility, and
  WordPress alignment helpers.
- `blueprint` provides the conventional Sass visual layer from the starter.
- `tailwind` uses Tailwind CSS v4 native CSS and `@tailwindcss/vite`.

The starter's committed `st-toolkit.json` begins with an unlocked Bare
foundation. After `composer st-toolkit init`, choose the project's UI once with
`ui:install`; the command records and locks that selection. Profile files then
become project-owned.

Changing a locked selection is an exceptional destructive operation. A dry run
lists every managed file that will be removed or replaced. The real command
requires `--replace`, creates a backup, deletes obsolete managed files and empty
directories, installs the target profile, and runs a clean production build.
Locally modified managed files additionally require `--force`. Shared core
assets, blocks, installed components, and untracked project files are outside
the profile deletion list.

One Vite configuration supports every profile. Sass profiles use recursive SCSS
entry discovery. Tailwind activates recursive native CSS discovery below
`assets/styles/` and the official Vite plugin through `st-toolkit.json`.
Non-underscored native CSS files compile independently with their relative
paths preserved below `assets/css/`; underscore-prefixed files are import-only.
The state is read when Vite starts, so restart an active `npm run dev` watcher
after the one-time profile selection.

Sass and Tailwind-native entries may coexist for standalone integrations, but
they cannot claim the same generated path. The Vite pipeline reports both
sources and fails before cleaning existing outputs when a collision is found.
There is no CSS/Sass precedence or automatic merge.

## Components

Components package reusable non-block behavior under `resources/components/`.
The `mega-menu` component supplies a complete, profile-independent primary
navigation: desktop disclosure panels, one-to-four-column layouts, recursive
mobile drawers, expandable site search, menu-item image modes, keyboard and
focus management, and a no-JavaScript fallback.
Choose the project UI with `ui:install` before installing components; component
installation validates the active profile marker before writing files or
running the production build.
Core's `/core/components.php` loader discovers project-owned integrations below
`/inc/components/` recursively. Toolkit-owned component integrations live below
`/inc/components/toolkit/`, with registered Sass and JavaScript sources below
the matching `/assets/**/styles-register/toolkit/` and
`/assets/**/scripts-register/toolkit/` paths. The installer then runs the theme
production build. Component manifests state their required core version, and
installation stops with a `core:update` instruction when the loader/API is too
old.

## Agent Workflows

`composer st-toolkit init` installs local guides and six task skills for
elemental compositions, block development, assets, core overrides, toolkit
operations and validation. The conventions have one maintained source:

```text
AGENTS.md
CLAUDE.md                         # imports AGENTS.md
.agents/*.md                      # focused guides
.agents/skills/wp-starter-*/SKILL.md
.claude/skills/wp-starter-*/SKILL.md  # thin links to canonical skills
```

Codex discovers the canonical project skills; Claude Code uses its native
entrypoints, whose bodies load those same workflows. Start the coding agent
from the theme root or below it. If skills are not listed after initialization,
start a new session. This does not configure global agents, change permissions,
or install cloud/account-level skills.

Refresh with `composer toolkit:docs-update`. Existing customized instructions
without a version comment are preserved, including `CLAUDE.md`. New skills keep
YAML frontmatter first, with the managed version comment immediately below it;
remove that comment to opt out of updates for one file. Use
`composer toolkit:docs-update -- --force --yes` only for an intentional refresh
of equal-version prerelease files. Existing guide versions are not bumped by
this development pass.

If you already maintain your own `CLAUDE.md`, add `@AGENTS.md` there when you
want the shared theme instructions loaded too; the installer will not merge
instructions into a developer-owned file.

The installer ignores its local guides and Claude skill paths in Git without
ignoring other Claude settings or custom skills. Normal Git deployments omit
these files; manual upload/deployment tools must exclude them separately.

Discovery contracts: [Codex skills](https://learn.chatgpt.com/docs/build-skills),
[Claude Code skills](https://code.claude.com/docs/en/skills), and
[shared project instructions](https://code.claude.com/docs/en/memory#share-one-file-with-other-coding-tools).

## Maintainer Validation

Run the package checks before publishing:

```bash
composer validate --strict
composer test:acceptance
```

The offline suite covers core/UI/component checks, bundle installation and
recovery, and the Elemental test harness itself. Run the latter separately with
`composer test:elementals-harness`. It uses a deliberately minimal generator
stub only for failure/reporting checks; actual generation is tested separately:

```bash
php tests/elementals-generated.php /absolute/clean-theme /absolute/generator/includes/functions.php /new/disposable-theme
php tests/elementals-profiles.php /absolute/clean-theme /new/profile-fixtures
```

These opt-in integration checks run Composer/npm and require a clean unlocked
Bare source. They never modify the source. Reports distinguish running, failed,
and passed checks; local path repositories test unreleased toolkit code, not a
published GitHub tag.

For Tailwind profile or compiler changes, use an installed Tailwind theme with
its npm dependencies available:

```bash
node tests/tailwind-build-acceptance.mjs /path/to/tailwind-theme
```

The isolated fixture verifies shared root variables, imported partials, native
nesting and `@apply`, standalone CSS entries, parallel Sass, deterministic
output, conflict preservation, and watcher discovery without changing that
theme. Set `ST_KEEP_TAILWIND_FIXTURE=1` to retain the temporary fixture when
investigating a failure.

`tests/mega-menu-browser-acceptance.js` contains a browser function for the
local seeded Mega Menu Demo hierarchy. Run it through a browser evaluator at
desktop and mobile sizes, including reduced-motion mode. The expected demo
paths include Products/Platform, Services/Strategy, and
Explore/Build/Front-end/CSS Architecture.

See [the changelog](CHANGELOG.md) for the pending release and its validation
limits. Resource versions and package release tags are independent.

## Relationship To The Theme

The canonical source theme is `SnailTheme/wp-starter`.

The expected release order is:

1. toolkit stable release
2. theme stable release
3. generator stable release

That order avoids shipping a stable theme that still depends on a moving
toolkit prerelease reference.
