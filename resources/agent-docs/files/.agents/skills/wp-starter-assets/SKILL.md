---
name: wp-starter-assets
description: Add or change theme CSS, Sass, Tailwind, JavaScript, asset handles, or enqueue behavior using the theme's Vite pipeline and registry.
---
<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Work With Theme Assets

Read [Scripts and styles](../../scripts_and_styles.md) and
[Style guidelines](../../style_guidelines.md). Check `composer toolkit:ui-status`
and `st-toolkit.json`; do not assume the project uses Sass or Tailwind.

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

Use `npm run dev` while actively watching changes. Always run `npm run build`
before a commit and include the generated outputs. Check responsive rendering,
iframe scoping, long text and console errors. Replacing a UI profile is a
separate destructive migration, not a routine stylesheet fix.
