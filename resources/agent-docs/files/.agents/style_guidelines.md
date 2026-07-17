<!-- st-toolkit-agent-doc-version: 1.1.0 -->
# Style Guidelines

## CSS Classes

Use BEM for project CSS classes. Name blocks from the feature or block slug,
then add elements and modifiers around that stable root.

Examples:

- Block: `.<block-name>`
- Nested block: `.<block-name>-media`
- Element: `.<block-name>__heading`
- Nested element: `.<block-name>-media__image`
- Modifier: `.<block-name>--featured`

Use modifiers for state or settings that affect a whole component. Put those
modifiers on the root class when the setting belongs to the whole block.

## SCSS Shape

SCSS should stay highly nested around the owning block class. Prefer local BEM
nesting with `&__element` and `&--modifier` so styles remain readable and easy
to move with the component.

```scss
.<block-name> {

  &__heading {
    margin: 0;
  }

  &--featured {

    .<block-name>__heading {
      font-weight: 700;
    }
  }
}
```

Avoid broad global selectors unless you are intentionally targeting WordPress,
editor, or third-party library markup.

## Third-Party Classes

When styling a third-party library, keep the project-owned class as the anchor
and target library classes underneath it. This keeps library overrides local to
the block or feature that needs them.

```scss
.<block-name> {

  .splide__track {
    overflow: hidden;
  }
}
```

Do not put project-specific styling in `/core/` assets. Core styles are shared
toolkit-owned assets.

## UI Profiles

Treat the active UI profile as a project starting point, not a runtime API.
Project blocks and reusable components must not assume Blueprint or Tailwind is
active. Share design values through `theme.json`, WordPress-generated custom
properties, or component-owned custom properties with fallbacks.

Choose and lock the UI profile before project styling. Replacing a locked
profile is destructive because the toolkit removes the previous profile's
managed scaffold. It is a recovery/migration path, not a normal development
workflow.

Use Sass modules (`@use` and `@forward`) for project SCSS. Do not introduce new
Sass `@import` statements. Tailwind CSS v4 entry points are native CSS and are
processed by the official Vite plugin rather than Sass.

For Tailwind projects, prefer utility classes in project templates and keep
shared design tokens in `/assets/styles/theme/_theme.css`. Put authored global
base rules in import-only files below `/assets/styles/base/`, named front-end
component rules below `/assets/styles/components/`, and project utilities below
`/assets/styles/utilities/`. Continue using BEM for authored component classes;
Tailwind utilities themselves follow Tailwind's naming system.

Use `@layer` to place authored Tailwind-profile rules in the intended cascade
layer. Use `@reference` from standalone native CSS entries that need the main
theme context for `@apply`, rather than importing Tailwind into every entry.
