# Changelog

## 1.2.0

### Tailwind Sources

- Share variables through `assets/styles/abstracts/_root.css`, imported by the
  front-end and editor entries. Base, components, utilities, and editor rules
  remain separate source partials.
- Keep independent register/enqueue stylesheets and parallel Sass support.
  Imported partials produce no standalone assets; `@reference` provides the
  Tailwind context without duplicating framework CSS.
- Document `@theme`, runtime custom properties, native CSS nesting, and the
  difference between CSS nesting and Sass BEM selector concatenation.

### Mega Menu Fixes

- Prevent delayed desktop close handlers from hiding mobile submenus after a
  responsive mode change.
- Keep desktop panels closed when Escape returns focus to their trigger.
- Exclude inert or hidden descendants when trapping mobile drawer focus.
- Apply reduced-motion state changes instantly so focus can enter the newly
  visible drawer.

### Resource Versions

- Tailwind profile: `1.1.0`.
- Mega-menu component: `1.1.1`.
- Agent docs package: `1.2.0`; only the edited scripts/styles and style guides
  receive new file markers. Other guides retain their existing versions.
- Shared core remains `1.1.0`.

### Validation

- Toolkit PHP acceptance: 112 assertions.
- Isolated Tailwind build/watch acceptance: 20 assertions.
- Menu checks at 320, 390, 567, 568, 768, 1024, and 1440 px, including nested
  drawers, keyboard focus, Escape, search, resize, and reduced motion.
- PHP/JavaScript syntax, Composer validation, and installed-resource checksums.
- Browser tests used local WordPress 6.9.4, PHP 8.2.29, and Node 24.14.1 on
  Linux. An authenticated Gutenberg browser pass and Windows/macOS execution
  remain outstanding.

### Publishing

Merge the reviewed `next` branch into `main` before creating the annotated
`v1.2.0` tag on that final commit. Existing release tags remain immutable.
Theme UI workbenches are validation fixtures and are not merged wholesale
into the clean theme release branches.
