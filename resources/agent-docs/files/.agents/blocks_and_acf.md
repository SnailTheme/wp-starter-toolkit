<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Blocks And ACF

## Namespace Map

Use generic placeholders in examples:

- `<namespace>` means `ST_WP_CORE_THEME_PATTERNS['block_namespace']`.
- Current block namespace: `<current_block_namespace>`.
- `<block-name>` means the block slug without the namespace.

The current namespace line is refreshed by `composer st-toolkit init` or
`composer toolkit:docs-update`.

## Block Location

Blocks live in:

```text
/blocks/<namespace>-<block-name>/
```

Example:

```text
/blocks/<namespace>-content-section/
```

Each block should keep its PHP, `block.json`, and block assets inside its own
directory.

## Block Metadata

New blocks should use WordPress Block API v3 and ACF Blocks v3:

```json
{
  "apiVersion": 3,
  "acf": {
    "blockVersion": 3,
    "mode": "preview",
    "renderTemplate": "block-render.php"
  }
}
```

Block names use the configured namespace:

```text
<namespace>/<block-name>
```

Block category comes from:

```php
ST_WP_CORE_THEME_PATTERNS['block_category']
```

Do not hardcode the source theme namespace in reusable block logic. Read it
from theme patterns or derive it from `$block['name']`.

## Block Assets In V3

Do not enqueue block editor styles or scripts from `admin_enqueue_scripts`.
Block API v3 renders the post/page editor canvas inside an iframe.

Declare front-end and editor assets from `block.json`:

```json
{
  "viewStyle": [ "shared.style.handle", "file:./assets/css/view-style.min.css" ],
  "editorStyle": [ "shared.style.handle", "file:./assets/css/editor-style.min.css" ],
  "viewScript": [ "shared.script.handle", "file:./assets/js/viewScripts.js" ],
  "editorScript": [ "shared.script.handle", "file:./assets/js/editorScripts.js" ]
}
```

Shared handles come from the core asset registry. Block-specific files stay
inside the block directory. If a block package needs a shared library, the
toolkit can install the shared source assets during `blocks:install`; generated
shared CSS and JS should come from `npm run build`, not from hand edits.

## Render Files

Render files should derive the namespace and block name from theme patterns and
`$block['name']`:

```php
$block_namespace = '';
if ( defined( 'ST_WP_CORE_THEME_PATTERNS' ) && is_array( ST_WP_CORE_THEME_PATTERNS ) ) {
	$block_namespace = (string) ( ST_WP_CORE_THEME_PATTERNS['block_namespace'] ?? '' );
}

$block_name = str_replace( "{$block_namespace}/", '', $block['name'] );
```

Build ACF field roots from `$block_namespace` and `$block_name`:

```php
$fields_group   = "{$block_namespace}-{$block_name}-fields";
$settings_group = "{$block_namespace}-{$block_name}-settings";

$fields   = get_field( $fields_group );
$settings = get_field( $settings_group );
$fields   = is_array( $fields ) ? $fields : array();
$settings = is_array( $settings ) ? $settings : array();
```

Use stable core helpers when useful:

```php
st_wp_core_get_block_wrapper_attributes()
```

Settings that affect JavaScript behavior should be printed into the rendered
markup, usually as data attributes. Let JavaScript read rendered options
instead of duplicating PHP settings logic in the script.

## ACF Field Structure

New ACF blocks should use two main groups:

- `<namespace>-<block-name>-fields`
- `<namespace>-<block-name>-settings`

Use `Fields` for content shown on the front end.

Use `Settings` for block-level options such as style, container width,
animation, and behavior toggles.

Simple field names:

```text
<namespace>-<block-name>-fields__<field_name>
<namespace>-<block-name>-settings__<setting_name>
```

Repeater names:

```text
<namespace>-<block-name>-fields-<plural_repeater_name>
<namespace>-<block-name>-fields-<singular_repeater_name>__<field_name>
```

Avoid loose fields outside the two main groups.

When blocks are installed through the toolkit, package source ACF JSON can use
readable symbolic keys. The toolkit rewrites those keys to deterministic
ACF-style keys for the installed destination block and names the local JSON
file after the generated group key.

## Block Styles

Block SCSS source files live in:

```text
/blocks/<namespace>-<block-name>/assets/scss/
```

Current style file pattern:

- `_styles.scss` contains shared block styles.
- `view-style.scss` imports front-end styles.
- `editor-style.scss` imports editor styles.

For Block API v3, editor styles must be iframe-safe. Scope editor-only rules
under `.editor-styles-wrapper` when they are meant only for the editor canvas:

```scss
.editor-styles-wrapper {
  @import "styles";
}
```

Vite compiles block SCSS to:

```text
/blocks/<namespace>-<block-name>/assets/css/view-style.min.css
/blocks/<namespace>-<block-name>/assets/css/editor-style.min.css
```

Run `npm run dev` while actively editing styles. Run `npm run build` before
committing. Do not hand-edit generated block CSS.

## Block Scripts

Block scripts live in:

```text
/blocks/<namespace>-<block-name>/assets/js/
```

The current V1 build does not compile block JavaScript. `block.json` loads
`editorScripts.js` and `viewScripts.js` directly, so keep those files
browser-ready and run `node --check` after editing them.

For Block API v3, editor scripts must handle iframe rendering:

- Do not assume `.block-editor` contains the rendered block preview.
- Do not rely only on `wp.domReady()`.
- Observe the current document and same-origin iframe documents.
- Guard missing dependencies.
- Skip instances that are already initialized.
- Skip empty markup with no rendered items.
- Catch initialization errors so one bad block does not break the editor.

Front-end scripts can usually initialize on `DOMContentLoaded`, but should use
the same guards for missing dependencies, empty markup, and duplicate
initialization.
