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
The `mega-menu` component supplies one accessible PHP/JavaScript disclosure
implementation and selects a small style adapter for the active UI profile.
Core's `/core/components.php` loader discovers project-owned integrations below
`/inc/components/`; the component installer adds the integration and generic
asset sources, then runs the theme production build. Component manifests state
their required core version, and installation stops with a `core:update`
instruction when the loader/API is too old.

## Relationship To The Theme

The canonical source theme is `SnailTheme/wp-starter`.

The expected release order is:

1. toolkit stable release
2. theme stable release
3. generator stable release

That order avoids shipping a stable theme that still depends on a moving
toolkit prerelease reference.
