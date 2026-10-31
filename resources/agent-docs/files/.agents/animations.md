<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# AnimateCSS

## Ownership And Activation

AnimateCSS is a theme feature, independent of the UI profile or slider. Its
project-editable sources remain outside `/assets/**/core/`:

- `/assets/scss/styles-register/plugins/animatecss/animate.scss`
- `/assets/scss/styles-register/plugins/animatecss/source/`
- `/assets/scripts/scripts-register/plugins/animatecss/animate.js`

`npm run build` produces the registered CSS and JS. Both handles are
`plugins.animatecss.animate`, in their separate style/script registries.
Registration does not load them automatically. The Customizer's Enable
AnimateCSS control uses the stable `st_wp_core_enable_animatecss` theme mod,
defaulting off, and enqueues both assets on the frontend when enabled.
Hiding the Customizer UI alone does not disable this saved behavior. The
feature-level `st_wp_core_enable_customizer_animatecss` filter controls both UI
and runtime; see [Core and overrides](core_and_overrides.md#feature-filters).

For project-specific conditional loading, enqueue both handles from
`/inc/scripts.php` on `wp_enqueue_scripts` after the registry (priority 20):

```php
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( is_page_template( 'page-animated.php' ) ) {
			wp_enqueue_style( 'plugins.animatecss.animate' );
			wp_enqueue_script( 'plugins.animatecss.animate' );
		}
	},
	30
);
```

The template name is an example, not a file shipped by the starter. Use the
actual project condition. CSS alone suffices for an immediate CSS animation;
the theme's in-view behavior requires the script as well.

## Select Animation Modules

Keep the shared `source/vars` and `source/base` modules. Comment/uncomment the
individual animation `@use` lines in `animate.scss` to choose the project's
subset, then rebuild. These local Sass modules are deliberately selectable;
do not replace them with one import of the whole vendor distribution. Ensure
every chosen animation class has its corresponding module enabled.

## Current In-View Contract

The existing runtime runs once on frontend `DOMContentLoaded`:

- It observes `.enter-view` wrappers, using a bottom root margin of `-120px`.
- On intersection it adds `entered` to that wrapper.
- It reveals descendant `.animate__animated.opacity-0` elements by removing
  `opacity-0` and adding their `data-animate-class` value.
- Use one nonempty class token, such as `animate__fadeIn`, in that attribute.
  A space-separated list is not valid for the runtime's `classList.add()` call.
- Put the animated element inside the observed wrapper, not only on the
  wrapper itself: `querySelectorAll()` searches descendants, excluding self.

This is not a general dynamic-content initializer. It does not automatically
observe later-inserted content, replay animations, or initialize ACF iframe
previews. For a project enhancement that needs those behaviors, implement the
appropriate lifecycle locally; use the ACF preview contract in
[Blocks and ACF](blocks_and_acf.md#block-scripts), not a parent-document-only
observer. Do not add animation dependencies to reusable Elementals or FAQ.

## Visibility And Accessibility

Prefer visible content as the baseline. The current runtime provides no
automatic no-JavaScript/observer-error fallback: its `opacity-0` helper stays
hidden until something explicitly removes it. Do not copy hidden markup into
a template without a project-owned reveal/failure strategy, and do not assume
disabling AnimateCSS always restores visibility; other CSS, including Tailwind
utilities, may also define `opacity-0`.

For progressive enhancement, apply a hidden starting state only after a
working initializer has opted that element in; preserve visible content when
scripts fail or are disabled and in editor previews. Any changed initializer
must restore visibility when setup or animation fails.

The base module shortens animations for print and reduced-motion preferences;
it does not remove `opacity-0`. Check those modes explicitly and provide a
visible nonanimated outcome. Avoid exit animations on essential reading or
interactive content. Keep project BEM classes as styling roots; animation
classes are optional behavior hooks, not replacements for owned selectors.
