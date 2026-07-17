<!-- st-toolkit-agent-doc-version: 1.1.0 -->
# Scripts And Styles

## Source And Build Paths

Edit source files:

- `/assets/scss/`
- `/assets/styles/` when the Tailwind profile is active
- `/assets/scripts/`
- `/blocks/<block-name>/assets/scss/`
- `/blocks/<block-name>/assets/js/`

Do not hand-edit generated files:

- `/assets/css/`
- `/assets/js/`
- `/blocks/<block-name>/assets/css/`

Use Vite to compile assets:

```bash
npm run dev
npm run build
```

Use [style guidelines](style_guidelines.md) for CSS class naming and SCSS
structure.

## Main Theme Assets

Project-owned theme assets are enqueued from `/inc/scripts.php`.

Default source/output pairs:

- `/assets/scss/main.scss` -> `/assets/css/main.min.css` for Sass profiles
- `/assets/styles/main.css` -> `/assets/css/main.min.css` for native CSS profiles
- `/assets/scripts/main.js` -> `/assets/js/main.min.js`
- `/assets/scss/editor.scss` or `/assets/styles/editor.css` -> `/assets/css/editor.min.css`
- `/assets/scripts/admin.js` -> `/assets/js/admin.min.js`
- optional `/assets/scss/woocommerce.scss` -> `/assets/css/woocommerce.min.css`

The active profile and optional native CSS entries are recorded in
`/st-toolkit.json`. Do not edit that file just to bypass a profile conflict;
use `composer toolkit:ui-status` and a profile dry run first.

Native CSS entries are source files and must remain outside `/assets/css/`,
which is a generated output directory deleted before production builds. The
Tailwind pipeline accepts native CSS sources only below `/assets/styles/`.

When Tailwind is active, Vite recursively discovers every non-underscored CSS
file below `/assets/styles/` as an independent entry and preserves its relative
path below `/assets/css/`. Files beginning with `_` are import-only partials and
do not produce standalone output.

Examples:

- `/assets/styles/main.css` -> `/assets/css/main.min.css`
- `/assets/styles/styles-enqueue/page-special.css` -> `/assets/css/styles-enqueue/page-special.min.css`
- `/assets/styles/components/_buttons.css` -> no standalone output

Only `main.css` and `editor.css` should normally import Tailwind itself. Native
register/enqueue entries can use `@reference` with the correct relative path to
access the main Tailwind theme and utilities for `@apply` without emitting the
framework a second time.

Keep the `@source not` exclusions for `/assets/css/`, `/assets/js/`, and
`/.st-toolkit/` in the main Tailwind entries. Tailwind scans the theme source
root for class names; excluding generated assets and toolkit backups prevents
generated writes from causing development-watch rebuild loops.

Classic Sass and Tailwind-native CSS can compile in parallel, but two entries
must never claim the same output path. For example, these sources conflict:

```text
/assets/scss/styles-enqueue/example.scss
/assets/styles/styles-enqueue/example.css
```

Both would produce `/assets/css/styles-enqueue/example.min.css`. Vite stops
before cleaning generated assets and reports both source paths. Rename one
entry, remove one, or make one an underscore-prefixed partial; the pipelines do
not merge and neither source has precedence.

The same `vite.config.js` handles all supported profiles. Bare and Blueprint
use recursively discovered Sass entries. Tailwind activates recursive native
CSS discovery and `@tailwindcss/vite` through `st-toolkit.json`. Native CSS is
rejected while Tailwind is inactive. Vite reads the profile contract at
startup, so restart `npm run dev` after the one-time UI selection.

`npm run dev` runs Vite in watch mode and writes development source maps.
`npm run build` runs a clean production build, removes stale outputs, and must
always be run before committing.

Core-owned asset sources live under:

- `/assets/scss/core/`
- `/assets/scripts/core/`

Avoid changing core-owned assets for project-specific styling.

## Block Assets

Block styles are declared from each block's `block.json`:

- `viewStyle` for front-end block CSS
- `editorStyle` for Block API v3 iframe/editor CSS

Vite compiles block SCSS from:

```text
/blocks/<block-name>/assets/scss/
```

to:

```text
/blocks/<block-name>/assets/css/
```

Block scripts are also declared from `block.json`:

- `viewScript` for front-end behavior
- `editorScript` for editor preview behavior

The current build does not compile block JavaScript. Files in
`/blocks/<block-name>/assets/js/` are runtime files loaded directly by
WordPress, so keep them browser-ready and syntax-check them after edits.

Do not enqueue block editor styles with `admin_enqueue_scripts`. Block API v3
uses an iframe editor canvas, so block editor styles must be loaded through
`block.json` `editorStyle` or another block-aware API.

Theme-wide editor content styles are registered from `/inc/scripts.php` with
`add_editor_style()`. WordPress scopes the compiled editor stylesheet to
`.editor-styles-wrapper`, including root selectors emitted by reset libraries.
Do not directly enqueue `/assets/css/editor.min.css`: `enqueue_block_assets`
also loads assets outside the iframe for compatibility, where an unscoped reset
could alter the surrounding wp-admin interface.

## Auto-Registered And Auto-Enqueued Assets

The core asset loader watches compiled asset directories:

- `/assets/css/styles-register/` auto-registers styles
- `/assets/css/styles-enqueue/` auto-enqueues styles
- `/assets/js/scripts-register/` auto-registers scripts
- `/assets/js/scripts-enqueue/` auto-enqueues scripts

Handles are generated from the path after `styles-register`,
`styles-enqueue`, `scripts-register`, or `scripts-enqueue`.

In Tailwind projects, place native source entries in the matching
`/assets/styles/styles-register/` or `/assets/styles/styles-enqueue/` directory.
In Sass projects, use the matching directories below `/assets/scss/`. Both
compile to the same PHP-watched `/assets/css/` directories.

Examples:

- `/assets/css/styles-register/page-default.min.css` -> `page-default`
- `/assets/css/styles-register/plugins/splidejs/core.min.css` -> `plugins.splidejs.core`
- `/assets/js/scripts-register/plugins/splidejs/core.min.js` -> `plugins.splidejs.core`

Shared assets under `/plugins/` are registered for the block editor and by the
front-end asset registry. Custom blocks can reference those handles from
`block.json` `viewStyle`, `editorStyle`, `viewScript`, or `editorScript`
without manually enqueueing the shared library.

## When To Use `/inc/scripts.php`

Use `/inc/scripts.php` when auto-loading is not enough.

Good uses:

- conditional loading by template, route, post type, or WooCommerce state
- third-party assets that need custom dependencies
- inline styles or scripts that need a registered handle
- replacing or extending theme-owned enqueue callbacks

Do not edit `/core/scripts.php` for project behavior.

Use `admin_enqueue_scripts` only for wp-admin UI behavior/assets. Use
`add_editor_style()` for the theme-wide post/page editor canvas stylesheet.
Reserve `enqueue_block_assets` for content assets intentionally needed on the
front end and in the editor, or for narrowly scoped conditional block assets.

## Installed Components

Toolkit components place PHP integration below `/inc/components/`, Sass below
`/assets/scss/components/`, and JavaScript below `/assets/scripts/components/`.
The package-owned `/core/components.php` loader includes component PHP after
the required core helpers have booted. Keep component-specific enqueue logic
with the project-owned component PHP file.
