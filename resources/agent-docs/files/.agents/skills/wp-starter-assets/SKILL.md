---
name: wp-starter-assets
description: Add or change theme CSS, Sass, Tailwind, JavaScript, asset handles, or enqueue behavior using the theme's Vite pipeline and registry.
---
<!-- st-toolkit-agent-doc-version: 1.0.4 -->
# Work With Theme Assets

Read [Scripts and styles](../../scripts_and_styles.md) and
[Style guidelines](../../style_guidelines.md). Check `composer toolkit:ui-status`
and `st-toolkit.json`; do not assume the project uses Sass or Tailwind.
Keep `assets/scss/_st-toolkit-profile.scss` a dedicated literal profile marker
matching that receipt, not a place for project Sass logic or nested overrides.
Commented examples do not establish which profile is active; use the profile
installer rather than editing state/markers to get past a failed preflight.

- Edit sources, never generated minified files. Sass entries live under
  `assets/scss/`, JavaScript under `assets/scripts/`, and Tailwind-native CSS
  under `assets/styles/` when that profile is active. Preserve relative output
  paths under `assets/css/` and `assets/js/`.
- Prefer the register/enqueue directories when their lifecycle fits. Derive
  handles from the guide's dot-notation rules. Use `inc/scripts.php` for custom
  conditions/dependencies, not for reimplementing the registry in `/core/`.
- Keep editor canvas styles separate from admin UI assets. Declare block
  assets in block.json; use the documented iframe-safe enqueue route for
  theme-wide editor content. Admin hooks do not target the canvas.
- Use Sass modules and nested BEM; keep native CSS partials underscored and
  imports explicit. Tailwind CSS is not Sass. Do not let native CSS and Sass
  entries claim the same output or hide collision failures with precedence.
- Respect project overrides and vendor feature selection. Do not replace
  selected animation modules with a whole-library import. Keep reusable block
  styles independent of the theme's UI profile.
- For animation work, read [AnimateCSS](../../animations.md) and inspect the
  installed project assets; docs/core updates do not replace them. Keep the
  always-enqueued reveal guard independent of optional animation activation.
  Arm hidden content only after usable CSS and observer setup succeed. Do not
  show content and then hide it to play an entrance: skip already-painted
  on-screen content on late initialization. Dynamic insertion uses scoped
  `window.stWpReveal.init()`/`destroy()`; `beforePaint: true` is only for a
  synchronous insertion before paint. Preserve fail-open, focus, print,
  reduced-motion and iframe behavior without global opacity-utility overrides.
  Validate the actual target's inline/tag/ID animation rules, not a generic
  clone. Late visibility checks must include nested overflowing children and
  text, not only the participant's border box or one ancestor range. Do not
  interrupt existing project animations while probing a selected reveal.
  Historical `entered` state is not current intersection. Adding children to
  old or still-running groups must request a current observation, not consume
  their entrances off screen or wait forever for a threshold change.
- Theme JavaScript is minified, not bundled; do not introduce unresolved npm
  imports or assume script ordering without real dependency declarations.

Use `npm run dev` while actively watching changes. Always run `npm run build`
before a commit and include the generated outputs. Check responsive rendering,
iframe scoping, long text and console errors. Replacing a UI profile is a
separate destructive migration, not a routine stylesheet fix.
