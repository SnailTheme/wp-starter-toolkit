# ST WP Starter Toolkit

Development toolkit for ST WP Starter based themes.

Package identity:

- Composer package: `snailtheme/wp-starter-toolkit`
- PHP namespace: `SnailTheme\WPStarterToolkit`
- CLI binary: `st-toolkit`
- Framework: Symfony Console

The repository can remain private under `snailtheme/wp-starter-toolkit` while the package
name points toward the SnailTheme organization.

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
st-toolkit core:check --theme=/path/to/theme
st-toolkit core:update --theme=/path/to/theme --dry-run
st-toolkit core:update --theme=/path/to/theme --yes
st-toolkit core:rollback --theme=/path/to/theme --yes
```

Block commands:

```bash
st-toolkit blocks:list
st-toolkit blocks:install slider hero-slider --theme=/path/to/theme --dry-run
st-toolkit blocks:install slider hero-slider --theme=/path/to/theme --yes
```

Diagnostics:

```bash
st-toolkit doctor --theme=/path/to/theme
```

Every command supports `--theme` and `--json`. Write commands also support
`--dry-run` and `--yes`.

## Core Updates

`core:update` reads `ST_WP_CORE_VERSION` and `ST_WP_CORE_THEME_PATTERNS` statically
from `core/bootstrap.php`. It does not bootstrap WordPress.

The command writes only files declared by `resources/core/st-wp-core-manifest.json`.
Current package-owned paths are:

- `core/**`
- `assets/scss/core/**`
- `assets/scripts/core/**`
- `assets/css/core/**`
- `assets/js/core/**`

It never edits `/inc/`, templates, generic theme assets, or `/blocks/`.

## Block Installs

Blocks are installed from `resources/blocks/<block>/st-block.json`.

Example:

```bash
st-toolkit blocks:install slider hero-slider --theme=/path/to/theme --yes
```

This installs the packaged `slider` block into:

```text
<theme>/blocks/hero-slider/
```

The installer uses:

- `ST_WP_CORE_THEME_PATTERNS['block_namespace']` for the block namespace
- `ST_WP_CORE_THEME_PATTERNS['block_category']` for the block category
- `ST_WP_CORE_THEME_PATTERNS['text_domain']` for translations
- stable `st_wp_core_*` helper calls without renaming them

Shared assets, such as Splide resources, are checksum checked. Missing shared
assets are added. Changed shared assets are reported as conflicts and require
`--replace-shared-assets` for non-interactive replacement.

NPM dependencies are reported only. The toolkit does not edit `package.json` and
does not run `npm install`.
