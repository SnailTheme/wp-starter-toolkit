---
name: wp-starter-block-development
description: Create or modify this theme's reusable WordPress/ACF blocks, render templates, fields, and iframe-safe assets. Use when block implementation must change rather than just page composition.
---
<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Develop Theme Blocks

Read [Blocks and ACF](../../blocks_and_acf.md) and
[Style guidelines](../../style_guidelines.md). Inspect a neighboring installed
block and `ST_WP_CORE_THEME_PATTERNS` before choosing names or helper calls.

- Work under `blocks/<namespace>-<block-name>/`. Use Block API v3 and ACF
  Blocks v3 with ACF PRO 6.6+. Do not copy obsolete edit-mode or v2 shims.
- Derive namespace and slug from the registered block name/theme patterns.
  Preserve stable `st_wp_core_*` APIs; never rename them for branding.
- Give each elemental block one named InnerBlocks slot. Enforce parent/child
  relationships in metadata and InnerBlocks, and keep templates editable.
  Prefer native heading, paragraph, list, quote and button blocks for content.
- Native-only elements need no dummy ACF groups. For ACF-backed blocks, use
  Fields/Settings tabs and the established groups, double-underscore leaves
  and singular/plural repeater grammar. No cross-block Clone dependencies.
- Validate settings in PHP even when ACF constrains the input: allowlist
  choices, clamp counts, and accept only finite positive custom lengths.
  Use existing attachment helpers to preserve image metadata and responsive
  sources. Missing images must not suppress editable content.
- Declare built editor/frontend assets in `block.json`. Scope editor rules to
  the canvas and style the actual InnerBlocks wrappers. Do not apply native
  wrapper supports twice. Any editor script must follow the guide's iframe,
  rerender and cleanup contract; do not query only the parent document.
- Keep BEM classes, custom properties and Sass block-owned. Do not depend on
  Blueprint containers/variables, Tailwind utility markup or optional animation
  libraries. Content must wrap without widening the page.

Run source builds, PHP/WPCS and POT generation as applicable. Verify insert,
nest/reorder, duplicate, live field edits, save/reload, missing/long content and
responsive editor/frontend rendering. Package synchronization and releases are
separate maintainer operations, not implicit parts of a client-theme change.
