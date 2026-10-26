<!-- st-toolkit-agent-doc-version: 1.3.0 -->
# Theme Agent Guide

This file is the entrypoint for maintainers and coding agents working inside
this theme.

## Main Rule

Keep project-specific work out of `/core/` when future toolkit updates should
remain possible.

- Use `/core/` for shared, updateable functionality.
- Use `/inc/` for theme-owned behavior, overrides, and integrations.
- Use templates, assets, and blocks for project-specific output.
- Keep stable `st_wp_core_*` helpers intact; they are shared core API.

## Project Map

Docs use placeholders for reusable examples:

- `<namespace>` means `ST_WP_CORE_THEME_PATTERNS['block_namespace']`.
- Current block namespace: `<current_block_namespace>`.
- `<block-name>` means the block slug without the namespace.

The current namespace line is refreshed by `composer st-toolkit init` or
`composer toolkit:docs-update`.

## Focused Guides

- [Core and overrides](.agents/core_and_overrides.md)
- [Scripts and styles](.agents/scripts_and_styles.md)
- [Style guidelines](.agents/style_guidelines.md)
- [Template tags and helpers](.agents/template_tags_and_helpers.md)
- [Blocks and ACF](.agents/blocks_and_acf.md)
- [Schemas and FAQ](.agents/schemas.md)
- [CLI and toolkit](.agents/cli_and_toolkit.md)

## Task Skills

Load the workflow matching the task, then only its relevant guides:

- [Compose elemental sections](.agents/skills/wp-starter-elemental-sections/SKILL.md)
- [Develop reusable blocks](.agents/skills/wp-starter-block-development/SKILL.md)
- [Add or change structured data](.agents/skills/wp-starter-schemas/SKILL.md)
- [Work with assets](.agents/skills/wp-starter-assets/SKILL.md)
- [Customize core behavior safely](.agents/skills/wp-starter-core-overrides/SKILL.md)
- [Operate the toolkit](.agents/skills/wp-starter-toolkit/SKILL.md)
- [Validate a change](.agents/skills/wp-starter-validation/SKILL.md)

Canonical skills live in `.agents/skills/`. Claude's `.claude/skills/`
entrypoints delegate to those same files; `CLAUDE.md` imports this guide.
Start the coding agent from this theme root (or a subdirectory). After initial
setup, start a fresh session if skills are not listed. Provider-specific
permissions and workspace trust still apply; this setup changes neither.

## Before Editing

- Read the focused guide for the area you are changing.
- Prefer `/inc/` over `/core/` for project behavior.
- Avoid changing generated assets directly; update source assets instead.
- Keep documentation and examples generator-safe.
- Check `composer toolkit:ui-status` before assuming a Sass or Tailwind source
  layout.
- Treat the locked UI profile as the project's permanent styling foundation;
  replacement is an exceptional destructive migration.
- Keep reusable blocks independent from the active UI profile.
- Compose Sections from the `el-*` foundation, rooted in `el-wrapper`, with an
  optional `el-container`; native-only elements need no dummy ACF fields. Read
  the Blocks and ACF guide before adding elemental or section compositions.
- Register supplied breadcrumb trails before `get_header()`. Schema-producing
  blocks use the early provider contract, not render-time registration. Read
  the Schemas and FAQ guide before changing structured data or FAQ content.

## Local-Only Notes

These files are installed by `composer st-toolkit init` and are ignored by Git
by default. To customize a file and opt out of updates, remove its
`st-toolkit-agent-doc-version` comment. In SKILL.md that comment follows the
YAML header; keep the header first so skill discovery still works.

Git ignore rules do not protect manual FTP uploads or a server that serves
local files directly. Exclude local agent files from non-Git deployments too.
