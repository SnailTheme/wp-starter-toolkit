<!-- st-toolkit-agent-doc-version: 1.0.3 -->
# AnimateCSS

## Ownership And Activation

AnimateCSS is a theme feature, independent of the UI profile or slider. Its
project-editable sources remain outside `/assets/**/core/`:

- `/assets/scss/styles-register/plugins/animatecss/animate.scss`
- `/assets/scss/styles-register/plugins/animatecss/source/`
- `/assets/scripts/scripts-register/plugins/animatecss/animate.js`
- `/assets/scss/styles-register/plugins/animatecss/_reveal.scss`
- `/assets/scss/styles-enqueue/animation-reveal.scss`

The corrected contract below requires these starter assets and rebuilt outputs.
Older projects must review and port those project-owned files explicitly.
Updating toolkit core or agent docs does not install this asset fix. Inspect
the actual runtime before assuming the initializer is available.

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

## In-View Contract

```html
<section class="enter-view">
  <p class="project-intro animate__animated" data-animate-class="animate__fadeIn">
    Content is readable if optional animation is unavailable.
  </p>
</section>
```

- Use one `animate__...` class token in `data-animate-class`; do not save the
  selected animation class, a hiding utility, or runtime state in new markup.
- The observed wrapper and animated element may also be the same element.
- The runtime validates usable CSS/keyframes and observer setup before adding
  its temporary `st-wp-reveal--pending` class. The pending element is hidden;
  on intersection it fades/moves into view once, without a visible-then-hidden
  waiting phase. The wrapper receives the compatibility class `entered`.
- Validation uses the actual target's CSS, including tag/ID rules and inline
  overrides. Already-running project animations are left alone. Late viewport
  checks include nested overflowing images/boxes and text in collapsed wrappers.
- Initialization is ready-state aware and idempotent. Already-painted on-screen
  content found by a late initializer is left visible, not hidden to replay an
  entrance. Off-screen content can still be armed normally.
- Observation uses the viewport without the old negative bottom margin, so
  short viewports and content near the page bottom can intersect.
- `entered` is historical compatibility state, not proof of current visibility.
  New children request a fresh intersection result once per wrapper/batch,
  including when a running group is already observed. Do not start later
  siblings immediately because their wrapper entered the viewport earlier.
- Refreshing observation does not discard queued intersections. Consume those
  results against the existing participants before enrolling new children;
  never start a later child from an earlier snapshot or discard notifications
  belonging to other wrappers.

After inserting content, initialize that subtree explicitly:

```js
window.stWpReveal?.init(subtree);
```

For a synchronous insert followed immediately by initialization, before the
browser paints the new subtree, opt into animating its visible elements:

```js
container.append(subtree);
window.stWpReveal?.init(subtree, {beforePaint: true});
```

Do not use `beforePaint: true` on already-displayed content: it permits the
initializer to hide visible elements. A subtree within an existing `.enter-view`
works too. Repeated initialization or script evaluation does not replay completed
elements or multiply active observers/listeners. No whole-document mutation
observer automatically initializes Ajax content. Before removing a subtree:

```js
window.stWpReveal?.destroy(subtree);
subtree.remove();
```

Untriggered observations expire after 30 seconds and reveal their content,
bounding retention if a caller forgets teardown. This means a slow reader may
see later sections without animation. Individual animations, including delay,
must be finite, unpaused and at most 10 seconds. End, cancellation, watchdog,
focus, print and reduced-motion cleanup release listeners/timers and restore
the normal project appearance.

## Visibility And Accessibility

The small `animation-reveal` auto-enqueued stylesheet is a visibility safeguard,
not an animation enabler. Keep it with the shared `_reveal.scss` partial even
when another UI profile is installed. It overrides legacy hiding only on reveal
markup, including Tailwind's utility, without redefining unrelated `opacity-0`
classes. The optional AnimateCSS stylesheet also includes those guards.

Missing CSS/JavaScript, unknown or disabled animation modules, failed observer
setup and malformed tokens must leave content visible. Never replace that
fallback with a global `.js` class or save the pending class/data attribute.
Print, reduced motion (including a changed preference), and keyboard focus
must reveal content without waiting for scrolling. Do not use exit or infinite
animations to hide essential content after completion.

Editor content remains visible and unanimated. An iframe uses its own document;
a parent-document observer is not an ACF preview initializer. Use the ACF
contract in [Blocks and ACF](blocks_and_acf.md#block-scripts) for unrelated
interactive behavior. Elementals and FAQ must not require animation to render.
Keep project BEM roots; animation classes are optional behavior hooks.
