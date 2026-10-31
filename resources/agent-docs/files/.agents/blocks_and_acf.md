<!-- st-toolkit-agent-doc-version: 1.3.1 -->
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

An optional `block-bootstrap.php` registers block-local integrations after
successful block registration. Core loads it once from the trusted registered
block directory. Keep registration side-effect free: no markup, direct schema
output, or content rendering. A schema provider belongs here, not in the render
template; see [Schemas and FAQ](schemas.md).

## Block Metadata

New blocks should use WordPress Block API v3 and ACF Blocks v3:

```json
{
  "apiVersion": 3,
  "acf": {
    "blockVersion": 3,
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
$parts = explode( '/', (string) ( $block['name'] ?? '' ), 2 );
$block_namespace = '';
if ( defined( 'ST_WP_CORE_THEME_PATTERNS' ) && is_array( ST_WP_CORE_THEME_PATTERNS ) ) {
	$block_namespace = (string) ( ST_WP_CORE_THEME_PATTERNS['block_namespace'] ?? '' );
}

if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
	$block_namespace = $parts[0];
}

$block_namespace = sanitize_key( $block_namespace );
$block_name      = sanitize_key( $parts[1] ?? $parts[0] );
```

Split the registered name once rather than globally replacing a namespace
substring. Use the resulting namespace/slug consistently for fields, markup,
and instance options; preserve stable core helper names.

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

Native-only elements need no dummy ACF field groups. When a block has ACF
data, use the Fields/Settings tabs and two main groups:

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

## Elemental Compositions

Install the ten-member foundation with
`composer st-toolkit blocks:install elementals`.
Its `el-*` slugs are fixed; only the configured namespace changes. A Section
is rooted in `<namespace>/el-wrapper` and contains a meaningful elemental
child, optionally inside `<namespace>/el-container`.

- Wrapper is a full-width section with native background-colour, margin, and
  padding controls only. Do not add background effects, borders, visibility,
  or project header behaviour to it.
- Container owns width/position, not background or automatic gutters.
- Heading/Text own native content; Content With Image adds an ACF image, not
  duplicate ACF heading/body/button fields.
- FAQ owns a self-contained ACF repeater, not InnerBlocks. Put an `el-heading`
  before it when a section heading is needed; FAQ adds no container or section
  styling. Its native details/summary interaction needs no JavaScript.
- Columns accepts Column children; Grid accepts Grid Cell children. Use these
  elements instead of core Group/Row/Stack/Columns/Grid in our compositions.
  Do not globally disable WordPress layout blocks.
- Keep one named `InnerBlocks` slot per content-composing element, including
  its `className` wrapper in front-end/editor styles. Keep templates editable and heading
  levels under the native Heading block's control.
- Keep ACF field definitions self-contained. No cross-block Clone groups or
  dependencies on Cynomi branding, source helpers, or legacy tabs code.

Spacing defaults come from WordPress tokens with sensible fallbacks. Layouts
stack below 48rem by default, with selectable 40rem/48rem/64rem tiers. Tabs are
not part of the first foundation pack.

Native spacing controls also need a theme opt-in. Bundle installation merges
only `settings.blocks.<namespace>/el-wrapper.spacing.margin/padding` into
`theme.json` and backs up that file with the block installation. Do not enable
spacing globally just to expose the Wrapper's controls. In the editor,
WordPress applies native supports to the outer block wrapper; do not duplicate
those native styles on the preview's inner section.

Treat ACF settings as input, not validation guarantees: allowlist layout choices
and clamp numeric ranges in render files. Custom CSS lengths must be finite and
positive. Keep layout children shrinkable and allow long unbroken content to
wrap inside the owned content root instead of hiding overflow or widening the
page. Check the same cases in the iframe and frontend.

## FAQ Element

`<namespace>/el-faq` is the tenth Elementals member and is also available
individually:

```bash
composer st-toolkit -- blocks:install el-faq el-faq --dry-run
composer st-toolkit blocks:install el-faq el-faq
```

FAQ requires shared core `1.2.0` and its schema helpers. If installation rejects
an older core, preview/apply `composer toolkit:core-update` first; do not bypass
the minimum-version check. Installing FAQ alone must not replace siblings.
Its manifest explicitly sets `bundle_only: false`; the original nine elements
remain bundle-only with fixed slugs.

Fields contain an items repeater with a plain-text question and a basic
WYSIWYG answer, with media uploads disabled. Settings contain schema enabled
and first item initially open; both default on. Preserve the Fields/Settings
groups and repeater naming above. Do not add dummy groups to native-only
elements to imitate FAQ.

Use one block-local normalization path for visible content and early schema.
Read raw ACF data without leaking or replacing ambient ACF/WordPress context;
sanitize basic rich text without executing shortcodes or embeds. Incomplete
rows show editor guidance but produce neither frontend rows nor schema.
Register the provider in `block-bootstrap.php`; the render template must not
try to add FAQ data after the head has been printed.

Render native `<details>/<summary>` with a unique shared `name` per rendered
instance. The first complete row opens by default and only one row opens at a
time within that instance. ACF block IDs alone are not unique across identical
duplicates or separate editor previews; do not use them alone as group names.
Keep instances independent, keyboard-accessible, and useful without scripts.
Use inherited typography, separators, wrapping, focus-visible treatment and an
`[open]` CSS caret, without animation dependencies or accordion JavaScript.

## Block Styles

Block SCSS source files live in:

```text
/blocks/<namespace>-<block-name>/assets/scss/
```

Current style file pattern:

- `_styles.scss` contains shared block styles.
- `view-style.scss` emits front-end styles with `@use` and a mixin.
- `editor-style.scss` emits the same baseline below the editor wrapper.

For Block API v3, editor styles must be iframe-safe. Scope editor-only rules
under `.editor-styles-wrapper` when they are meant only for the editor canvas:

```scss
@use "styles";

.editor-styles-wrapper {
	@include styles.render;
}
```

Packaged blocks must remain UI-profile neutral. Use block-owned BEM classes,
WordPress `theme.json` variables with sensible fallbacks, and block-owned CSS
custom properties. Do not depend on generic profile classes such as
`.container`, Blueprint Sass variables, Tailwind utility classes, or optional
theme animation classes in a reusable render template.

Vite compiles block SCSS to:

```text
/blocks/<namespace>-<block-name>/assets/css/view-style.min.css
/blocks/<namespace>-<block-name>/assets/css/editor-style.min.css
```

Run `npm run dev` while actively editing styles. Run `npm run build` before
committing. Do not hand-edit generated block CSS.

## Style Variants

For an ACF-driven block variant, use the Settings group's `__style` field,
with explicit choices and a default. Validate that value again in PHP with an
allowlist before constructing classes or JavaScript options. This is not the
same API as Gutenberg `block.json` styles / `is-style-*`; do not add a second
independent selector for an existing ACF setting.

Keep the base class and add one modifier on the outer section/component root.
The slider uses `slider-section slider-section--horizontal` or
`slider-section slider-section--vertical`, not a modifier on its inner Splide
element. Other blocks should use their own established or namespaced BEM root.

Structure `_styles.scss` as common rules first, then distinct variant groups:

```scss
@mixin render {
	.feature {
		&__content {
			min-width: 0;
		}

		&--compact {
			.feature__content {
				padding-block: 1rem;
			}
		}

		&--spacious {
			.feature__content {
				padding-block: 2rem;
			}
		}
	}
}
```

Use that same mixin for frontend and scoped editor styles. When the variant
also changes behavior, derive options from the validated PHP value and encode
them in escaped data attributes. The slider's vertical variant sets Splide
`direction: "ttb"` and a height; its initializer reads `data-splide` per instance.
Do not infer behavior from page-wide selectors or hardcode one variant in JS.
Test both variants together, field changes in iframe previews, duplicate
instances, empty content and responsive layouts. Keep optional animation
enhancements separate; see [AnimateCSS](animations.md).

## Block Scripts

Block scripts live in:

```text
/blocks/<namespace>-<block-name>/assets/js/
```

The current V1 build does not compile block JavaScript. `block.json` loads
`editorScripts.js` and `viewScripts.js` directly, so keep those files
browser-ready and run `node --check` after editing them.

Declare script dependencies in the adjacent `editorScripts.asset.php` or
`viewScripts.asset.php`, returning `dependencies` and `version`. A list of
handles in `block.json` does not establish execution order. An ACF preview
initializer depends on `acf-input` plus its actual library handles; use the
source file timestamp for its cache version when no bundler supplies one.

For Block API v3, editor scripts must handle iframe rendering:

- Do not assume `.block-editor` contains the rendered block preview.
- Do not rely only on `wp.domReady()`.
- Use ACF's `render_block_preview` action for ACF previews. Its first argument
  is a jQuery preview wrapper; resolve `[0].ownerDocument` rather than looking
  for the markup in the parent editor shell. The action runs again after
  replacement/cached previews. A parent-document observer alone misses iframe
  body creation and can leave previews uninitialized.
- Guard missing dependencies.
- Skip instances that are already initialized.
- Skip empty markup with no rendered items.
- Catch and report initialization errors so one bad block does not break the
  editor, without silently masking a failed preview.
- If an initializer attaches persistent listeners, observers or library
  instances, release them when its preview is replaced. Keep initialization
  scoped to the owning preview so another block's rerender cannot mount it twice.

Front-end scripts can usually initialize on `DOMContentLoaded`, but should use
the same guards for missing dependencies, empty markup, and duplicate
initialization.
