<!-- st-toolkit-agent-doc-version: 1.0.0 -->
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
