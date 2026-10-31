# Changelog

## 1.5.1 (Unreleased)

- Clarify BEM naming, nested Sass and ACF-driven block style variants, including
  root modifiers, validated settings and per-instance script options.
- Add focused AnimateCSS guidance for activation, selected modules, in-view
  markup, reduced motion, iframe limits and visible-content failure safeguards.
- Add the shared `wp-starter-updates` skill and thin Claude adapter, covering
  independent dependency/docs/core updates, version gates, partial-failure
  recovery and toolkit-first maintainer releases.
- Correct Customizer UI/runtime distinctions, singular-only breadcrumb/FAQ
  boundaries, generic schema node shape and JavaScript minification constraints.
- Update only changed guide content versions. Preserve markerless instructions
  and unrelated Claude settings; ignore the new adapter through an exact rule.
- Expand offline coverage to 2,579 assertions, including guidance upgrades from
  the preceding release, new skill installation and project-file preservation.
  Shared core and UI profile versions remain unchanged.

Merge the reviewed `next` branch into `main` before tagging `v1.5.1` on the
verified merge commit. Do not move existing public tags.

## 1.5.0

- Add `core:manifest` for checked, ownership-scoped core checksum generation.
- Add `ui:profile-export` and `ui:profile-verify` for allowlisted workbench
  resources, preserving raw source and installed-theme checksum distinctions.
- Validate profile markers and JSON types, reject unsafe paths/stale approvals,
  and retain backups with clear recovery diagnostics for failed exports.
- Extend agent safeguards and offline acceptance coverage to 2,413 assertions.
  Shared core remains `1.2.0`; Packagist distribution remains deferred.

## 1.4.0

- Package shared core `1.2.0` with head-only schema registration, supplied
  breadcrumb trails, early block providers, nested/synced content discovery,
  safe JSON-LD encoding and independent SEO-plugin ownership.
- Add native Elemental FAQ as the tenth bundle member and a standalone block.
  Share raw ACF normalization between visible answers and schema; preserve
  independent exclusive disclosures across duplicated/editor-cached previews.
- Respect native Hide and viewport visibility during schema collection.
  Viewport-conditioned synced references require a certified single root;
  ambiguous output is skipped with a development diagnostic, never pre-rendered.
- Add the schema guide, canonical schema skill and thin Claude adapter. Update
  relevant agent workflows and exact ignore rules while retaining unchanged
  guide versions. Docs updates remain separate from core/block installation.
- Fix slider iframe initialization using ACF v3's preview-render lifecycle and
  explicit library dependencies/cache versions in adjacent script asset files.
- Preserve canonical titles during same-slug block installation and retain the
  original nine Elementals' bundle-only restrictions.
- Expand offline coverage to 1,637 assertions, plus the slider lifecycle test.
  Generator smoke checks pass 492 assertions; a fresh custom-namespace project
  passes all twelve setup/validation commands. WordPress runtime suites pass
  across Bare, Blueprint and Tailwind, with 832 assertions on the development
  theme. Production builds, PHP/WPCS, POT regeneration and dependency audits pass.
- Actual runtime checks use WordPress 7.1.3, ACF PRO 6.8.10 and PHP 8.2.29 on
  Linux/Brave at desktop/mobile widths. Gutenberg's core-variable iframe warning
  and ACF's deprecated preference call remain upstream warnings, not suppressed
  or patched vendor code. Other PHP/OS/browser combinations remain release gates.

Published as `v1.4.0` before the corresponding clean theme dependency update.
Installed block/ACF fixtures stay on `blocks-dev`, never release-theme branches.

## 1.3.0

- Add the nine-block Elemental Foundation bundle with namespace-aware ACF keys,
  staged conflict checks, scoped native spacing opt-in, one backup/build, and
  file-scoped failure recovery.
- Add shared project guidance and six workflow skills for Codex and Claude Code.
  `init`/`docs:update` install canonical skills, Claude entrypoints and local
  ignore rules without overwriting markerless custom instructions.
- Keep skill YAML headers first while versioning their body markers. Reject
  unsafe docs paths before writes and preserve unrelated Claude configuration.
- Preserve customized instructions with malformed version markers rather than
  treating arbitrary version text as permission to overwrite them.
- Keep bundle installation locks in ignored runtime storage without editing
  project ignore rules.
- Ignore only the six installed Claude skill directories; migrate the old
  wildcard rule without hiding custom project skills that use the same prefix.
- Size Wrapper sections around their native horizontal margins without overflow.
- Transform namespace forms without rewriting already-inserted namespace values.
- Preserve generated GitHub repository identities when their owner matches a
  source author token but the project author has a different name.
- Agent docs package: `1.3.0`; edited AGENTS, block and CLI guides advance to
  `1.2.0`. New skills start at `1.0.0`; unchanged guides retain their versions.
  Shared core remains `1.1.0`.
- Extend offline regression suites and real generated/profile fixtures. Runtime
  checks used WordPress 6.9.4, ACF PRO 6.8.5, PHP 8.2.29 and Node 24.14.1 on
  Linux/Brave. Latest-WordPress and non-Linux runtime checks remain outstanding.

Merge the reviewed `next` branch into `main`, then tag that final commit
`v1.3.0`. Do not move existing tags. Theme dependency publication follows the
stable toolkit tag; installed block fixtures never merge into clean themes.

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

- Agent guidance now includes npm and Composer security audits for development
  tools. The theme's corresponding dependency/CI fixes are maintained in the
  theme repository rather than bundled into toolkit core resources.
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
