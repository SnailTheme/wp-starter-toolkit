<!-- st-toolkit-agent-doc-version: 1.1.0 -->
# CLI And Toolkit

## Composer Commands

```bash
composer install
composer lint:php
composer lint:wpcs
composer lint:wpcs:fix
composer make-pot
```

`composer make-pot` generates the POT file in `/languages/` and normalizes
project-owned headers through `/core/tools/normalize-pot.php`.

## Node Commands

```bash
npm install
npm run dev
npm run build
```

Use `npm run dev` during active development to watch source assets for file
changes and keep sourcemaps available.

Use `npm run build` before committing. It creates production assets and removes
sourcemaps.

## Toolkit

The toolkit is installed as a Composer development dependency.

Public repository:

https://github.com/snailtheme/wp-starter-toolkit

Install local development notes:

```bash
composer st-toolkit init
composer toolkit:init
```

Refresh toolkit-managed local development notes without updating core files:

```bash
composer toolkit:docs-update
composer toolkit:docs-update -- --force --yes
```

Common commands:

```bash
composer toolkit:doctor
composer toolkit:init
composer toolkit:docs-update
composer toolkit:core-check
composer toolkit:core-update:dry-run
composer toolkit:core-update -- --yes
composer toolkit:core-rollback -- --yes
composer toolkit:blocks-list
composer toolkit:blocks-install:dry-run slider hero-slider
composer toolkit:blocks-install slider hero-slider -- --yes
composer toolkit:ui-list
composer toolkit:ui-status
composer toolkit:ui-install:dry-run blueprint
composer toolkit:ui-install blueprint
composer toolkit:components-list
composer toolkit:components-install mega-menu -- --dry-run
composer toolkit:components-install mega-menu -- --yes
```

These Composer scripts are project wrappers around the toolkit binary installed
in `/vendor/bin/`. Prefer them in documentation and day-to-day usage so
developers do not need to know the vendor binary path.

For less-common toolkit commands, use the generic passthrough script:

```bash
composer st-toolkit core:check --theme=.
composer st-toolkit -- blocks:install slider hero-slider --theme=. --dry-run
```

Use `--` before toolkit arguments when an option name may also be a Composer
option, such as `--dry-run`.

Toolkit core updates are for package-owned files only.

Expected update targets:

- `/core/**`
- `/assets/scss/core/**`
- `/assets/scripts/core/**`
- `/assets/css/core/**`
- `/assets/js/core/**`

Do not use toolkit core updates as a way to change project-specific code in
`/inc/`, templates, generic assets, or local blocks.

Toolkit docs updates are separate from core updates. Use `docs:update` when
you only want the local `AGENTS.md` and `.agents/` guidance refreshed.

Toolkit block installs are separate from core updates and docs updates. Use
`blocks:install` when you want to copy a curated packaged block into the local
theme. Block installs can also install missing npm dependencies, copy required
shared source assets, and run `npm run build` when the packaged block declares
those requirements.

UI profile installs scaffold project-owned style sources and update the
committed `st-toolkit.json` profile receipt. Choose the profile once, before
project styling begins. Available starting points are:

- `bare` for the functional minimum
- `blueprint` for the conventional Sass theme layer
- `tailwind` for Tailwind CSS v4 through its official Vite plugin

The first `ui:install` locks the selection. Do not treat UI profiles as themes
to toggle during development. Replacing a locked profile is an exceptional,
destructive operation:

```bash
composer toolkit:ui-install:dry-run tailwind
composer toolkit:ui-replace tailwind
```

The `toolkit:ui-replace` wrapper supplies the required `--replace` and `--force`
flags. The toolkit still asks for confirmation, creates a backup before
changing profile files, and runs `npm run build` afterward. Restart a running
`npm run dev` watcher because Vite reads profile integration from
`st-toolkit.json` when it starts.

Component installs add reusable non-block behavior separately from core,
profiles, and blocks. Select the project UI first so the committed asset
pipeline is available. Toolkit-owned component integrations use
`/inc/components/toolkit/`, with their registered Sass and JavaScript sources
isolated below matching `toolkit/` directories. The installer checks the
component's required shared core version before writing files; run
`composer toolkit:core-update` first when instructed.
