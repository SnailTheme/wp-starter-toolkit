<!-- st-toolkit-agent-doc-version: 1.0.0 -->
# Schemas And FAQ

## Ownership And Location

Shared core `1.2.0` provides `/core/schema.php`. Project policy and guarded
overrides belong in `/inc/schema.php`, which loads before the core fallbacks.
Keep stable `st_wp_core_*` names unchanged. Inspect that installed module for
the global/per-type disable hooks and final graph filter before adding policy.

The theme owns only its own graph. It does not disable, rewrite, or join an
SEO plugin's output automatically. Decide which system owns each schema type
for the project; use the theme's disable hooks when the plugin should own it.
Do not invent references to plugin-owned IDs or remove plugin output merely
because the theme can emit the same type.

## Public API

```php
st_wp_core_schema_add( array $node, string $key ): bool;
st_wp_core_schema_add_breadcrumbs( array $items ): bool;
st_wp_core_schema_add_faq( array $items, string $source_key ): bool;
st_wp_core_schema_register_block_provider( string $block_name, callable $provider ): void;
```

The request-local registry prints one safely encoded JSON-LD `@graph` late in
`wp_head`. Reusing a contribution key replaces its previous value rather than
duplicating it. Use stable keys for contributions, not random IDs. Check the
boolean registration result; never hide a failure by printing another script.
No footer fallback is provided.

Output is suppressed in admin, feeds, REST, editor previews, and
password-protected content. Registration does not override those protections.
Use canonical page permalinks for identifiers, not raw request URLs, query
strings, or hashes copied from previews. The final filter must preserve valid,
safe graph data; let core perform JSON encoding instead of concatenating JSON.

## Supplied Breadcrumbs

Breadcrumbs are logic only: this API creates no navigation markup or styles
and does not discover a hierarchy. Supply an ordered trail of `name`/`url`
pairs in project code **before `get_header()`**:

```php
st_wp_core_schema_add_breadcrumbs(
	array(
		array( 'name' => 'Home', 'url' => home_url( '/' ) ),
		array( 'name' => get_the_title(), 'url' => get_permalink() ),
	)
);
get_header();
```

Validate that the trail describes the visible page and intended navigation.
Core validates entries, assigns consecutive positions after invalid entries
are discarded, and uses the canonical page permalink for the schema ID.
Do not derive the trail from arbitrary URLs or add blank placeholder entries.

After head output, new or changed registrations are rejected with a development
diagnostic. Moving a call from a render template to `wp_footer` cannot repair
the timing. Move supplied trails before `get_header()` and block discovery to
an early provider. Repeated identical calls are not a reason to emit duplicates.

## Early Block Providers

An optional `block-bootstrap.php` lives beside `block.json`. Core loads it once
after successful registration, from the trusted registered block directory.
Register providers there, not inside `block-render.php`:

```php
st_wp_core_schema_register_block_provider(
	$block_type->name,
	static function ( array $parsed_block, int $post_id, string $source_key ): void {
		// Use the block's own raw-data normalizer; do not render the block.
		$items = project_normalize_faq_items( $parsed_block, $post_id );
		st_wp_core_schema_add_faq( $items, $source_key );
	}
);
```

Core supplies the successfully registered `WP_Block_Type` as `$block_type`
inside this file. This is a contract illustration, not a copy-ready bootstrap:
provide the project/block-local normalizer in the actual block.
Use `<namespace>` from `ST_WP_CORE_THEME_PATTERNS['block_namespace']`, or derive
it from the registered name. Do not hardcode the starter's namespace.

The provider contract is `(array $parsed_block, int $post_id, string
$source_key): void`. Core supplies a stable contribution key for that block
occurrence. The provider reads raw ACF data, normalizes complete visible items,
honors the block's schema setting, and calls `st_wp_core_schema_add_faq()`.
Preserve ambient ACF meta and WordPress post/query state; do not set global
post context, run the Loop, or leave temporary ACF state behind.

Before output, core traverses queried singular content without rendering it.
Nested blocks and synced-pattern references are supported with cycle
protection; Query Loop contents are skipped. Providers must not render HTML,
execute shortcodes or embeds, trigger unrelated block callbacks, or query an
unrelated page to fabricate content. Do not pre-render a whole page just to
collect schema. A query-generated or separately rendered FAQ needs an explicit
early contribution only when it actually belongs to the current visible page.

Native WordPress block visibility is respected during collection, including
hidden ancestors and synced references. Viewport restrictions are intersected
along each branch: content visible on at least one supported viewport remains
eligible, while fully hidden content contributes nothing. Project-specific
conditional-render filters still need an explicit matching provider/policy;
do not render blocks early to discover their visibility.

WordPress applies a synced reference's viewport classes to its first HTML root,
not every sibling. For predictable pre-head collection, put viewport-conditioned
synced content inside one outer `el-wrapper` or `el-container` (a single FAQ is
also supported). Ambiguous multi-root or unrecognized dynamic roots are skipped
with a development diagnostic, not rendered early to guess their output.
Boolean Hide still removes the whole reference. To certify another proven
single-root renderer, use `st_wp_core_schema_synced_viewport_root` with its
parsed root; never approve arbitrary third-party blocks without checking their
empty and multi-root output. Keep any renderer override and certification in sync.

## FAQ Content And Interaction

`<namespace>/el-faq` is both the tenth `elementals` bundle member and a
standalone block; see [Blocks and ACF](blocks_and_acf.md) and
[CLI and toolkit](cli_and_toolkit.md). Its schema helpers require core `1.2.0`.
The original nine members remain bundle-only. Native-only elements still need
no dummy ACF groups.

FAQ Fields contain an items repeater with a plain-text question and basic
WYSIWYG answer; media uploads are disabled. Settings contain schema enabled
and first item initially open, both defaulting on. Put headings in an external
`el-heading`, and compose width/background with other elements. FAQ owns no
built-in section or container styling.

Use one block-local normalizer for early collection and visible rendering.
Read unformatted ACF values; allow only basic rich text, without executing
shortcodes or embeds. Preserve paragraph/word separation when deriving schema
text. An incomplete row appears only as editor guidance, with no frontend row
or schema. Complete answers in collapsed native disclosures are still visible
content users can open; never add answers only to the JSON-LD.

Core combines complete contributions into one canonical `FAQPage`, preserving
order and deduplicating identical question/answer pairs across instances.
Pass plain-text `question` values and sanitized rich-HTML `answer` values to
`st_wp_core_schema_add_faq()`. Core derives answer text once. Do not decode
entities or strip the answer to text first: that would reinterpret literal
markup examples on the second normalization pass.
Do not manually add a second `FAQPage` via the generic node API or output one
script per FAQ. Schema opt-out affects structured data, not the visible FAQ.

Interaction uses native `<details>/<summary>`, with a unique `name` per rendered
instance. First complete item open is the default; the shared name makes items
exclusive within that instance. Duplicate blocks and editor previews must have
independent names: ACF IDs can repeat. Keep this interaction identity separate
from stable schema contribution keys. No accordion JavaScript, animation
dependency, hidden checkbox trick, or global page-wide accordion grouping.
Use profile-neutral BEM styles with inherited typography, separators, wrapping,
focus-visible indication, and a CSS caret driven by `[open]`.

`FAQPage` remains structured data, not a promise of a Google rich result.
Google stopped showing FAQ rich results on May 7, 2026, and announced it in its
[documentation updates](https://developers.google.com/search/updates).

## Verification

- Inspect the actual head response: one theme graph, parseable JSON, canonical
  IDs, complete visible answers, and no duplicate scripts on repeated calls.
  Distinguish independent SEO-plugin output rather than counting all scripts
  as theme duplicates.
- Test malformed entries, consecutive breadcrumb positions, repeated keys,
  late registrations, empty answers, schema opt-out, multiple FAQ blocks,
  nested/synced references and cycles, Query Loop exclusion, and suppression
  contexts. Ensure raw data collection leaves ACF/WordPress context unchanged.
- Test native Hide, hidden ancestors/synced references, and responsive visibility
  combinations so content hidden at every viewport cannot leak into FAQ schema.
  Include ambiguous multi-root synced references and their diagnostic, plus a
  supported single-wrapper equivalent; do not assert universal render prediction.
- Test encoding with quotes, markup and `</script>`-like content; no data may
  escape the JSON-LD script or execute shortcodes/embeds during discovery.
- Check FAQ keyboard interaction, first-open and single-open behavior,
  duplicate/editor-preview isolation, save/reload, long text, and responsive
  editor/frontend parity on Bare, Blueprint, and Tailwind.
- Use disposable themes for standalone/bundle install, custom namespaces,
  old-core rejection, dry runs, cancellation, conflicts, backups, and final
  build failures. Follow the normal PHP/WPCS, build, POT and diff checks; record
  actual versions and untested platforms rather than claiming blanket support.
