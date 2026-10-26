---
name: wp-starter-elemental-sections
description: Compose editable WordPress page sections from this theme's el-* blocks. Use for building or changing section layouts, not for inventing new block types.
---
<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Compose Elemental Sections

Read [AGENTS.md](../../../AGENTS.md), then the composition and field contracts in
[Blocks and ACF](../../blocks_and_acf.md). Current namespace:
`<current_block_namespace>`, read from
`ST_WP_CORE_THEME_PATTERNS['block_namespace']`.

- Inspect installed blocks before composing. If the foundation is missing,
  propose `composer toolkit:blocks-install:dry-run elementals`; perform
  installation only within the user's authorized task. Do not copy package
  resources into a theme by hand or invent destination slugs for this bundle.
- Start each Section with `<namespace>/el-wrapper`, then optional `el-container`
  and meaningful content. Wrapper owns native background, margin and padding;
  Container owns width and position without automatic gutters or background.
- Use Heading/Text for native copy, Columns/Column for weighted layouts,
  Grid/Grid Cell for responsive tracks/spans, and Content With Image for native
  copy/buttons plus an ACF attachment. Columns accept only Column children;
  Grids accept only Grid Cells. Respect each element's native-content allowlist.
- Keep heading levels in native Heading blocks. Use the element's controls
  rather than replacing its markup with profile utility classes or ACF-rich
  text fields. Keep templates editable, not globally locked.
- Reuse these elements before creating section-specific PHP. Do not introduce
  a separate Section block system, pattern catalog, or tabs implementation as
  part of an ordinary composition request. Do not globally disable core layout
  blocks; they remain available outside these compositions.

Check editor/frontend parity, image position, spacing persistence, responsive
stacking, long/empty copy and missing media. Use the project's actual UI profile
and installed WordPress/ACF versions; report what was really tested.
