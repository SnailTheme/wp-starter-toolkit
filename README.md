# ST WP Starter Toolkit

Development toolkit for SnailTheme starter-based WordPress themes.

Package identity:

- Composer package: `snailtheme/wp-starter-toolkit`
- PHP namespace: `SnailTheme\WPStarterToolkit`
- CLI binary: `st-toolkit`
- framework: Symfony Console

## Current State

- active development branch: `next`
- stable branch: `main` is planned for the first stable release
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

Diagnostics:

```bash
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
`--dry-run` and `--yes`.

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

NPM dependencies are reported only. The toolkit does not edit `package.json`
and does not run `npm install`.

## Relationship To The Theme

The canonical source theme is `SnailTheme/wp-starter`.

The expected release order is:

1. toolkit stable release
2. theme stable release
3. generator stable release

That order avoids shipping a stable theme that still depends on a moving
toolkit prerelease reference.
