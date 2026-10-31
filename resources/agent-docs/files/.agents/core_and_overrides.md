<!-- st-toolkit-agent-doc-version: 1.2.2 -->
# Core And Overrides

## Directory Roles

`/core/` contains shared functionality that can be updated by the toolkit.
Do not customize `/core/` directly when the project should keep receiving
future core updates.

`/inc/` contains theme-owned behavior. Put project customizations, overrides,
and integration hooks here.

`/core/components.php` is shared discovery infrastructure. It loads the
project-selected integrations installed below `/inc/components/`. Customize an
installed component in `/inc/components/`; do not move the generic loader out
of core.

New core writes belong only to `/core/` and the `core/` directories under
`/assets/scss/`, `/assets/scripts/`, `/assets/css/` and `/assets/js/`.
The manifest also names six legacy flat core-admin/core-customizer source and
output files for removal. Those explicit migration exceptions do not authorize
wildcards over other project assets; inspect the actual removal list.
Do not expand a manifest glob or removal entry to include `/inc/`, blocks,
templates or generic assets. Component installation into project-owned
`/inc/components/toolkit/` is a separate operation, not core-update ownership.
Maintainer checksum generation validates package data; it is not permission to
edit a client's project or proof that the packaged callbacks execute correctly.

## Early Loader Decisions

Use `/inc/bootstrap.php` only for early decisions that must run before
`/core/bootstrap.php` finishes loading.

Good uses:

- filter `st_wp_core_files`
- filter `st_wp_core_should_load_file`
- disable broad core features with `st_wp_core_enable_*` filters

Avoid normal theme setup in `/inc/bootstrap.php`. Use the matching `/inc/`
module instead.

## Matching Override Files

Core modules load matching `/inc/` files before defining guarded fallback
callbacks.

Common pairs:

- `/core/scripts.php` -> `/inc/scripts.php`
- `/core/template-tags.php` -> `/inc/template-tags.php`
- `/core/template-functions.php` -> `/inc/template-functions.php`
- `/core/schema.php` -> `/inc/schema.php`
- `/core/woocommerce.php` -> `/inc/woocommerce.php`
- `/core/jetpack.php` -> `/inc/jetpack.php`

If a core callback is guarded with `function_exists()`, define the replacement
in the matching `/inc/` file.

## Feature Filters

Use feature filters when the core file should still load but a feature should
be disabled.

Examples:

```php
add_filter( 'st_wp_core_enable_customizer', '__return_false' );
add_filter( 'st_wp_core_enable_woocommerce', '__return_false' );
add_filter( 'st_wp_core_enable_plugin_suggestions_notice', '__return_false' );
```

Use `st_wp_core_files` or `st_wp_core_should_load_file` only when an entire core
module should not load.

`st_wp_core_enable_customizer` hides only the Customizer UI shell. Saved theme
mods still affect the frontend through `/core/customizer-runtime.php`.
Disable a complete feature with its registry filter, for example
`st_wp_core_enable_customizer_animatecss`, rather than assuming that hiding the
UI disables animation assets. Read the registry for the actual feature switch.

Customizer runtime is an explicit override-location exception: project callbacks
and feature filters belong in `/inc/customizer-functions.php`, loaded before
feature fallbacks, not an inferred `/inc/customizer-runtime.php`. Filters that
prevent feature loading must be registered before that loading happens.

## Schema Ownership

Shared core `1.2.0` adds the head-only schema registry in `/core/schema.php`.
Use `/inc/schema.php` for project schema policy and guarded overrides, not a
second output registry. Use the global/per-type disable hooks and final graph
filter exposed by that module when filters are sufficient. Leave SEO-plugin
output alone unless the project explicitly chooses otherwise. See
[Schemas and FAQ](schemas.md) for timing, providers, and ownership rules.
