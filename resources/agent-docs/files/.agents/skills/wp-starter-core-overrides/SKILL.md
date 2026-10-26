---
name: wp-starter-core-overrides
description: Customize this theme's PHP behavior, hooks, template tags, and helpers without overwriting toolkit-owned core files.
---
<!-- st-toolkit-agent-doc-version: 1.0.1 -->
# Customize Theme Behavior

Read [Core and overrides](../../core_and_overrides.md) and
[Template tags and helpers](../../template_tags_and_helpers.md).

- Find the existing core callback, its guard, hooks and matching `/inc/` load
  order before changing behavior. Prefer the established filter or project
  override instead of editing shared `/core/` or `/assets/**/core/` paths.
- Use `inc/bootstrap.php` only for early loader filters. Normal setup and
  integrations belong in the appropriate matching `/inc/` module.
- Define a guarded callback override only where the documented load order
  permits it. Do not assume every core function is replaceable; inspect its
  implementation and prefer hooks when replacement is not supported.
- Put reusable HTML-producing template helpers in `inc/template-tags.php`;
  put non-markup helpers and filters in `inc/template-functions.php`. Keep
  block-local rendering logic inside the block rather than growing a core
  framework or unrelated global helper collection.
- Use this project's function prefix from theme patterns for project callbacks.
  Stable `st_wp_core_*` names are shared API and must remain unchanged.

If the defect truly belongs to shared core, explain that ownership boundary.
For toolkit maintainers, synchronize approved core changes to packaged resources
and test update/rollback. Check the manifest against the actual packaged bytes
without expanding core ownership to client paths. Required APIs need real,
immediately available declarations, not imports or uncalled setup functions;
static inspection does not replace runtime verification. Do not silently turn
a client customization into a
package release. Run relevant PHP/WPCS checks and regenerate POT after PHP
changes, including changes that only move translation source lines.
