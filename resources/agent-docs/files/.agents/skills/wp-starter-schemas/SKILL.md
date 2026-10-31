---
name: wp-starter-schemas
description: Add or change this theme's head-only structured data, supplied breadcrumb trails, FAQ contributions, and early block schema providers. Use for schema behavior rather than unrelated SEO configuration.
---
<!-- st-toolkit-agent-doc-version: 1.0.1 -->
# Work With Theme Schemas

Read [Schemas and FAQ](../../schemas.md), then inspect the installed
`core/schema.php` and any `inc/schema.php` policy before changing behavior.
These APIs require shared core `1.2.0`; a docs refresh does not update core.

- Choose schema ownership explicitly. Do not remove, merge into, or reference
  SEO-plugin graphs automatically. Reuse the theme registry, not another
  JSON-LD script or footer fallback.
- Generic registration takes one unwrapped node with a short `@type`; core
  supplies `@context`/`@graph`. Breadcrumb/FAQ helpers need a singular permalink
  and reject archives/search/posts-index requests. Do not turn an expected
  `false` into duplicate output or fake singular context.
- Register supplied breadcrumb `name`/`url` pairs before `get_header()`.
  Do not infer hierarchy, add breadcrumb markup, or use the request URI as a
  canonical identifier. Check registration return values and late diagnostics.
- For a block contribution, register a provider from `block-bootstrap.php`
  with callable `(array $parsed_block, int $post_id, string $source_key): void`.
  Normalize raw block data and call `st_wp_core_schema_add_faq()` from the
  provider. Do not render content, expand shortcodes/embeds, or mutate ambient
  ACF/WordPress context to discover schema.
- Share FAQ normalization with visible rendering. Keep only complete question
  and answer pairs, preserve their order, honor schema opt-out, and use stable
  source keys rather than random interaction group names. Do not promise
  Google FAQ rich results; Google retired that feature in May 2026.

Verify the actual head graph, safe encoding, canonical IDs, repeated and late
calls, multi-block deduplication, nested/synced references and cycle protection.
Test disabled and protected contexts plus visible-answer parity. Check native
block visibility through ancestors and synced references, including
viewport combinations with no remaining visible size. Viewport-conditioned
synced content needs one certified outer root: prefer `el-wrapper` or
`el-container`. Check the diagnostic/skip behavior for ambiguous roots rather
than predicting arbitrary dynamic output. For FAQ UI,
check native keyboard interaction and independent duplicate instances without
JavaScript. Follow [validation](../wp-starter-validation/SKILL.md) for the
remaining change-specific checks; do not release or deploy implicitly.
