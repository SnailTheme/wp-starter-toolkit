<?php
/**
 * Strict schema integration against the active WordPress theme and real ACF.
 *
 * Run: wp eval 'require "/absolute/path/to/toolkit/tests/schema-wordpress.php";'
 * REST suppression, in a separate process (constants cannot be reset safely):
 * wp eval 'define("REST_REQUEST", true); require "/absolute/path/to/toolkit/tests/schema-wordpress.php";'
 *
 * Only uniquely named temporary wp_block posts are written, then deleted in
 * finally. No pages, options, menus, theme files, or existing posts are changed.
 * The real wp_head callback is isolated from unrelated plugins' head callbacks
 * to prevent their incidental writes; a second publisher proves independence.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Run this test through wp eval with require.' );
}

( static function (): void {
	$checks = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
		++$checks;
		if ( ! $condition ) {
			throw new RuntimeException( 'Schema WordPress assertion failed: ' . $message );
		}
	};
	$assert( function_exists( 'st_wp_core_schema_print' ), 'Install shared schema core 1.2.0 first.' );
	$assert( version_compare( ST_WP_CORE_VERSION, '1.2.0', '>=' ), 'Schema core must be at least 1.2.0.' );
	$assert( function_exists( 'acf_setup_meta' ), 'ACF PRO must be active.' );
	$assert( class_exists( 'DOMDocument' ), 'The DOM extension is required for visible-content checks.' );
	$namespace = (string) ST_WP_CORE_THEME_PATTERNS['block_namespace'];
	$name = $namespace . '/el-faq';
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
	$assert( null !== $type, 'Install the elemental FAQ before running the runtime test.' );
	$assert( 3 === $type->api_version, 'FAQ must use Block API v3.' );
	$state =& st_wp_core_schema_state();
	$initial_state = $state;
	$assert( isset( $state['providers'][ $name ] ), 'FAQ block-bootstrap.php did not register its schema provider.' );
	$assert( ! $state['flushed'], 'Run in a fresh WP-CLI process before any schema head output.' );

	// Save mutable request state, including whole hooks so closures are removed.
	$hook_names = array(
		'wp_head', 'pre_render_block', 'render_block', 'the_content',
		'acf/format_value/type=wysiwyg', 'pre_oembed_result', 'content_pagination',
		'st_wp_core_schema_enabled', 'st_wp_core_schema_type_enabled', 'st_wp_core_schema_graph',
		'st_wp_core_schema_synced_viewport_root',
		'doing_it_wrong_run', 'doing_it_wrong_trigger_error',
	);
	$saved_hooks = array();
	foreach ( $hook_names as $hook ) {
		$saved_hooks[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
	}
	$global_names = array( 'wp_query', 'wp_the_query', 'post', 'current_screen', 'shortcode_tags', 'wp_actions', 'wp_filters', 'wp_current_filter' );
	$saved_globals = array();
	foreach ( $global_names as $global ) {
		$saved_globals[ $global ] = array( 'exists' => array_key_exists( $global, $GLOBALS ), 'value' => $GLOBALS[ $global ] ?? null );
	}
	$local_meta = acf_get_instance( 'ACF_Local_Meta' );
	$saved_meta = $local_meta->meta;
	$saved_meta_post_id = $local_meta->post_id;
	$saved_get = $_GET;
	$saved_request_uri = array( 'exists' => isset( $_SERVER['REQUEST_URI'] ), 'value' => $_SERVER['REQUEST_URI'] ?? null );
	$created = array();
	$sentinel_name = 'st-schema-test/' . str_replace( '-', '', wp_generate_uuid4() );
	$dynamic_name = $sentinel_name . '-dynamic';
	$diagnostics = array();
	$counters = array( 'pre_render' => 0, 'render' => 0, 'shortcode' => 0, 'content' => 0, 'wysiwyg' => 0, 'embed' => 0, 'unrelated' => 0 );
	$visits = array();
	$scenarios = array();
	$ambient_id = 'st_schema_ambient_' . wp_generate_uuid4();
	$rest_mode = defined( 'REST_REQUEST' ) && REST_REQUEST;
	try {
		remove_all_actions( 'wp_head' );
		add_action( 'wp_head', 'st_wp_core_schema_print', PHP_INT_MAX );
		$other_publisher = '<script type="application/ld+json" id="st-schema-other-publisher">{"@context":"https://schema.org","@type":"Organization","name":"Independent publisher"}</script>';
		add_action( 'wp_head', static function () use ( $other_publisher ): void { echo $other_publisher; }, 20 );
		$head = static function () use ( $assert, $other_publisher ): array {
			ob_start();
			try {
				wp_head();
				$html = (string) ob_get_contents();
			} finally {
				ob_end_clean();
			}
			$assert( 1 === substr_count( $html, $other_publisher ), 'Theme schema modified another publisher.' );
			preg_match_all( '#<script type="application/ld\+json" id="st-wp-core-schema">(.*?)</script>#s', $html, $matches );
			$assert( count( $matches[1] ) <= 1, 'More than one theme JSON-LD script was printed.' );
			$graph = $matches[1] ? json_decode( $matches[1][0], true, 512, JSON_THROW_ON_ERROR ) : array();
			return array( 'html' => $html, 'graph' => $graph, 'scripts' => count( $matches[1] ) );
		};
		if ( $rest_mode ) {
			$assert( ! st_wp_core_schema_enabled(), 'REST_REQUEST did not suppress schema.' );
			$assert( ! st_wp_core_schema_add( array( '@type' => 'Thing' ), 'rest' ), 'REST accepted new schema.' );
			$assert( 0 === $head()['scripts'], 'REST printed theme schema.' );
			$scenarios[] = 'REST_REQUEST (separate process)';
		} else {
			$fields = array();
			foreach ( acf_get_field_groups( array( 'block' => $name ) ) as $group ) {
				$fields = array_merge( $fields, acf_get_fields( $group ) ?: array() );
			}
			$field_by_name = static function ( array $definitions, string $field_name ): array {
				foreach ( $definitions as $field ) {
					if ( $field_name === $field['name'] ) {
						return $field;
					}
				}
				throw new RuntimeException( 'FAQ field definition not found: ' . $field_name );
			};
			$root = str_replace( '/', '-', $name );
			$fields_group = $field_by_name( $fields, $root . '-fields' );
			$settings_group = $field_by_name( $fields, $root . '-settings' );
			$repeater = $field_by_name( acf_get_fields( $fields_group ), $root . '-fields-items' );
			$question_field = $field_by_name( acf_get_fields( $repeater ), $root . '-fields-item__question' );
			$answer_field = $field_by_name( acf_get_fields( $repeater ), $root . '-fields-item__answer' );
			$schema_field = $field_by_name( acf_get_fields( $settings_group ), $root . '-settings__schema_enabled' );
			$first_field = $field_by_name( acf_get_fields( $settings_group ), $root . '-settings__first_open' );
			$assert( 'repeater' === $repeater['type'], 'FAQ items are not a real ACF repeater.' );
			$assert( 'wysiwyg' === $answer_field['type'] && empty( $answer_field['media_upload'] ), 'FAQ answers must use media-free WYSIWYG.' );
			$block = static function ( string $block_name, array $attrs = array(), array $children = array(), string $html = '' ): array {
				return array(
					'blockName' => $block_name, 'attrs' => $attrs, 'innerBlocks' => $children,
					'innerHTML' => $html, 'innerContent' => $children ? array_fill( 0, count( $children ), null ) : array( $html ),
				);
			};
			$faq = static function ( array $items, bool $enabled = true ) use ( $block, $name, $fields_group, $settings_group, $repeater, $question_field, $answer_field, $schema_field, $first_field ): array {
				$data = array( $fields_group['name'] => '', '_' . $fields_group['name'] => $fields_group['key'] );
				$path = $fields_group['name'] . '_' . $repeater['name'];
				$data[ $path ] = count( $items );
				$data[ '_' . $path ] = $repeater['key'];
				foreach ( $items as $index => $item ) {
					foreach ( array( $question_field, $answer_field ) as $field ) {
						$item_path = $path . '_' . $index . '_' . $field['name'];
						$data[ $item_path ] = $item[ $field['name'] === $question_field['name'] ? 'question' : 'answer' ];
						$data[ '_' . $item_path ] = $field['key'];
					}
				}
				$data[ $settings_group['name'] ] = '';
				$data[ '_' . $settings_group['name'] ] = $settings_group['key'];
				foreach ( array( $schema_field, $first_field ) as $field ) {
					$path = $settings_group['name'] . '_' . $field['name'];
					$data[ $path ] = $field['name'] === $schema_field['name'] ? (int) $enabled : 1;
					$data[ '_' . $path ] = $field['key'];
				}
				return $block( $name, array( 'name' => $name, 'data' => $data ) );
			};
			$make_post = static function ( string $label, array $blocks = array(), string $status = 'publish', string $password = '' ) use ( &$created ): int {
				$id = wp_insert_post( wp_slash( array(
					'post_type' => 'wp_block', 'post_status' => $status, 'post_title' => 'Schema temporary acceptance ' . $label,
					'post_name' => 'st-schema-test-' . wp_generate_uuid4(), 'post_content' => serialize_blocks( $blocks ), 'post_password' => $password,
				) ), true );
				if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
				$created[] = (int) $id;
				return (int) $id;
			};
			$set_query = static function ( int $id, ?int $content_page = null ) use ( $assert ): WP_Query {
				$args = array( 'p' => $id, 'post_type' => 'wp_block', 'post_status' => 'publish', 'no_found_rows' => true );
				if ( null !== $content_page ) { $args['page'] = $content_page; }
				$query = new WP_Query( $args );
				$GLOBALS['wp_query'] = $query;
				$GLOBALS['wp_the_query'] = $query;
				$GLOBALS['post'] = $query->posts[0] ?? null;
				$assert( is_singular() && $id === get_queried_object_id(), 'Temporary wp_block did not establish a real singular WP_Query.' );
				return $query;
			};
			$first_items = array(
				array( 'question' => 'How & why?', 'answer' => '<p>First paragraph.</p><p>Second line.</p>' ),
				array( 'question' => 'Encoded examples?', 'answer' => '<p>&lt;b&gt;example&lt;/b&gt; &amp;amp;.</p><p>Next line.</p>' ),
				array( 'question' => 'Safe literals?', 'answer' => '<p>&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;</p>' ),
				array( 'question' => 'Incomplete must not appear', 'answer' => '<p> </p>' ),
				array( 'question' => '', 'answer' => '<p>No question.</p>' ),
			);
			$first = $faq( $first_items );
			$second = $faq( array( $first_items[0], array( 'question' => 'Second question?', 'answer' => '<p>Literal [st_schema_probe] stays literal.</p>' ) ) );
			$synced_a = $make_post( 'synced A' );
			$synced_b = $make_post( 'synced B' );
			$ref = static fn ( int $id ): array => $block( 'core/block', array( 'ref' => $id ) );
			$update_a = wp_update_post( wp_slash( array( 'ID' => $synced_a, 'post_content' => serialize_blocks( array( $faq( array( array( 'question' => 'Synced question?', 'answer' => '<p>Synced answer.</p>' ) ) ), $ref( $synced_b ) ) ) ) ), true );
			$update_b = wp_update_post( wp_slash( array( 'ID' => $synced_b, 'post_content' => serialize_blocks( array( $faq( array( array( 'question' => 'Nested synced question?', 'answer' => '<p>Nested synced answer.</p>' ) ) ), $ref( $synced_a ) ) ) ) ), true );
			$assert( ! is_wp_error( $update_a ) && ! is_wp_error( $update_b ), 'Unable to create synced cycle fixtures.' );
			$draft = $make_post( 'draft synced', array( $faq( array( array( 'question' => 'Draft must not appear', 'answer' => 'Hidden' ) ) ) ), 'draft' );
			$protected = $make_post( 'protected synced', array( $faq( array( array( 'question' => 'Protected must not appear', 'answer' => 'Hidden' ) ) ) ), 'publish', 'test-password' );
			$root_id = $make_post( 'queried root', array(
				$block( $namespace . '/el-wrapper', array(), array( $first ) ),
				$block( 'core/group', array(), array( $second ) ),
				$block( 'core/query', array(), array( $faq( array( array( 'question' => 'Query Loop must not appear', 'answer' => 'Hidden' ) ) ) ) ),
				$ref( $synced_a ), $ref( $synced_a ), $ref( $draft ), $ref( $protected ),
				$block( $sentinel_name ), $block( 'core/shortcode', array(), array(), '[st_schema_probe]' ),
				$faq( array( array( 'question' => 'Schema disabled must not appear', 'answer' => 'Visible without schema' ) ), false ),
				$faq( array( array( 'question' => 'Empty answer must not appear', 'answer' => '' ) ) ),
			) );
			$protected_root = $make_post( 'protected queried root', array( $first ), 'publish', 'test-password' );
			$query = $set_query( $root_id );
			$canonical = get_permalink( $root_id );
			$assert( is_string( $canonical ) && '' !== $canonical, 'WordPress did not provide a canonical permalink.' );
			$assert( ! is_admin(), 'The normal test must start in a frontend request context.' );

			$original_provider = $initial_state['providers'][ $name ];
			$providers = $initial_state['providers'];
			$providers[ $name ] = static function ( array $parsed, int $post_id, string $key ) use ( &$visits, $original_provider, $assert ): void {
				$assert( doing_action( 'wp_head' ), 'Provider did not collect inside the real head action.' );
				$visits[] = array( $post_id, $key );
				$original_provider( $parsed, $post_id, $key );
			};
			$reset = static function () use ( &$state, $providers, &$visits ): void {
				$state = array( 'nodes' => array(), 'faq' => array(), 'providers' => $providers, 'collected' => false, 'flushed' => false );
				$visits = array();
			};
			$reset();
			acf_setup_meta( array( 'st_schema_sentinel' => 'leave this alone', '_st_schema_sentinel' => 'field_st_schema_sentinel' ), $ambient_id, true );
			$context = static function () use ( $local_meta ): string {
				return serialize( array(
					'post' => $GLOBALS['post'], 'query_id' => spl_object_id( $GLOBALS['wp_query'] ), 'main_query_id' => spl_object_id( $GLOBALS['wp_the_query'] ),
					'query_flags' => array( is_singular(), is_preview(), is_feed(), get_queried_object_id() ),
					'acf_meta' => $local_meta->meta, 'acf_post_id' => $local_meta->post_id,
					'acf_valid_post_id' => acf_get_valid_post_id(), 'acf_block_version' => acf_get_data( 'acf_current_block_version' ),
					'acf_preview' => acf_get_data( 'acf_doing_block_preview' ),
				) );
			};
			foreach ( array( 'pre_render_block' => 'pre_render', 'render_block' => 'render', 'the_content' => 'content', 'acf/format_value/type=wysiwyg' => 'wysiwyg', 'pre_oembed_result' => 'embed' ) as $hook => $counter ) {
				add_filter( $hook, static function ( $value ) use ( &$counters, $counter ) { ++$counters[ $counter ]; return $value; } );
			}
			add_shortcode( 'st_schema_probe', static function () use ( &$counters ): string { ++$counters['shortcode']; return 'SHORTCODE EXECUTED'; } );
			register_block_type( $sentinel_name, array( 'api_version' => 3, 'render_callback' => static function () use ( &$counters ): string { ++$counters['unrelated']; return 'UNRELATED RENDER EXECUTED'; } ) );
			add_action( 'doing_it_wrong_run', static function ( string $function, string $message, string $version ) use ( &$diagnostics ): void {
				if ( str_starts_with( $function, 'st_wp_core_schema_' ) ) { $diagnostics[] = array( $function, $version ); }
			}, 10, 3 );
			add_filter( 'doing_it_wrong_trigger_error', static fn ( bool $trigger, string $function ): bool => str_starts_with( $function, 'st_wp_core_schema_' ) ? false : $trigger, 10, 2 );
			$trail = array( array( 'name' => 'Home', 'url' => home_url( '/' ) ), array( 'name' => 'Invalid row', 'url' => 'javascript:alert(1)' ), array( 'name' => 'FAQ acceptance', 'url' => $canonical ) );
			$assert( st_wp_core_schema_add_breadcrumbs( $trail ), 'Breadcrumb trail was not accepted before head.' );
			$before = $context();
			$source_content = get_post_field( 'post_content', $root_id, 'raw' );
			$result = $head();
			$assert( $before === $context(), 'Head collection changed the ambient WordPress or ACF context.' );
			$assert( $source_content === get_post_field( 'post_content', $root_id, 'raw' ), 'Head collection changed source content.' );
			$assert( 0 === array_sum( $counters ), 'Head collection rendered content, formatted WYSIWYG, ran shortcodes, or executed embeds: ' . wp_json_encode( $counters ) );
			$assert( array() === $diagnostics, 'Provider failed or registered too late during normal head collection.' );
			$assert( 1 === $result['scripts'], 'Expected one real theme head graph.' );
			$assert( 'https://schema.org' === $result['graph']['@context'], 'Incorrect JSON-LD context.' );
			$graphs = $result['graph']['@graph'];
			$assert( array( 'BreadcrumbList', 'FAQPage' ) === array_column( $graphs, '@type' ), 'Expected one breadcrumb node and one aggregated FAQPage.' );
			$assert( $canonical . '#breadcrumb' === $graphs[0]['@id'] && $canonical . '#faq' === $graphs[1]['@id'], 'Schema IDs did not use the queried canonical permalink.' );
			$assert( array( 1, 2 ) === array_column( $graphs[0]['itemListElement'], 'position' ), 'Breadcrumb positions have gaps after invalid rows.' );
			$questions = $graphs[1]['mainEntity'];
			$expected_names = array( 'How & why?', 'Encoded examples?', 'Safe literals?', 'Second question?', 'Synced question?', 'Nested synced question?' );
			$assert( $expected_names === array_column( $questions, 'name' ), 'FAQ visible names/order/deduplication mismatch: ' . wp_json_encode( array_column( $questions, 'name' ) ) );
			$assert( 'First paragraph. Second line.' === $questions[0]['acceptedAnswer']['text'], 'Paragraph boundaries collapsed in schema.' );
			$assert( '<b>example</b> &amp;. Next line.' === $questions[1]['acceptedAnswer']['text'], 'Literal HTML/entity answer was decoded more than once.' );
			$assert( '</script><img src=x onerror=alert(1)>' === $questions[2]['acceptedAnswer']['text'], 'Safe literal answer changed.' );
			$assert( ! str_contains( $result['html'], '</script><img' ), 'Literal answer terminated the JSON-LD script.' );
			$assert( 'Literal [st_schema_probe] stays literal.' === $questions[3]['acceptedAnswer']['text'], 'FAQ schema executed or removed literal shortcodes.' );
			$assert( array(
				array( $root_id, 'post:' . $root_id . '/0/0' ), array( $root_id, 'post:' . $root_id . '/1/0' ),
				array( $synced_a, 'post:' . $root_id . '/3/ref:' . $synced_a . '/0' ), array( $synced_b, 'post:' . $root_id . '/3/ref:' . $synced_a . '/1/ref:' . $synced_b . '/0' ),
				array( $synced_a, 'post:' . $root_id . '/4/ref:' . $synced_a . '/0' ), array( $synced_b, 'post:' . $root_id . '/4/ref:' . $synced_a . '/1/ref:' . $synced_b . '/0' ),
				array( $root_id, 'post:' . $root_id . '/9' ), array( $root_id, 'post:' . $root_id . '/10' ),
			) === $visits, 'Real parsed synced occurrences, cycles, Query Loops, or content-owner IDs were incorrect.' );
			$visit_count = count( $visits );
			$assert( st_wp_core_schema_add_breadcrumbs( $trail ), 'Identical late breadcrumb registration was not idempotent.' );
			$assert( ! st_wp_core_schema_add_breadcrumbs( array( $trail[0] ) ), 'Changed late trail was accepted.' );
			$assert( ! st_wp_core_schema_add( array( '@type' => 'Thing' ), 'late-new' ), 'New late node was accepted.' );
			$assert( 2 === count( $diagnostics ), 'Late calls did not emit development diagnostics.' );
			$assert( 0 === $head()['scripts'] && $visit_count === count( $visits ), 'Repeated real wp_head duplicated schema or providers.' );
			$scenarios[] = 'singular real head; nested/synced/cyclic/Query Loop traversal; late calls; context isolation';

			// Render only this FAQ after proving the collector did no rendering.
			$html = render_block( $first );
			$document = new DOMDocument( '1.0', 'UTF-8' );
			$document->loadHTML( '<meta charset="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
			$xpath = new DOMXPath( $document );
			$summaries = $xpath->query( '//summary' );
			$answers = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $root . '__answer ")]' );
			$assert( 3 === $summaries->length && 3 === $answers->length, 'Incomplete FAQ rows appeared in frontend rendering.' );
			$visible = static function ( DOMNode $node ) use ( &$visible ): string {
				if ( $node instanceof DOMText ) { return $node->data; }
				if ( $node instanceof DOMElement && 'br' === $node->tagName ) { return ' '; }
				$text = '';
				foreach ( $node->childNodes as $child ) { $text .= $visible( $child ); }
				return $node instanceof DOMElement && in_array( $node->tagName, array( 'p', 'ul', 'ol', 'li', 'blockquote' ), true ) ? ' ' . $text . ' ' : $text;
			};
			for ( $index = 0; $index < $summaries->length; ++$index ) {
				$question = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $visible( $summaries->item( $index ) ) ) );
				$answer = trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $visible( $answers->item( $index ) ) ) );
				$assert( $question === $questions[ $index ]['name'], 'Visible summary and schema question differ.' );
				$assert( $answer === $questions[ $index ]['acceptedAnswer']['text'], 'Visible answer and schema answer differ.' );
			}
			$scenarios[] = 'actual FAQ render and DOM-visible question/answer parity';

			// Compare early collection with real WP7 render-time visibility. The
			// pagination filter supplies fixture content without rewriting a page.
			$viewport_queries = WP_Theme_JSON::get_viewport_media_queries( wp_get_global_settings( array( 'viewport' ) ), array( 'include_desktop' => true ) );
			$known_viewports = array_map( static fn ( string $key ): string => ltrim( $key, '@' ), array_keys( $viewport_queries ) );
			$assert( count( $known_viewports ) >= 2, 'WordPress must provide a small viewport and desktop query.' );
			$viewport = static fn ( array $hidden ): array => array( 'viewport' => array_fill_keys( $hidden, false ) );
			$visibility = static function ( array $parsed, $value ): array {
				$parsed['attrs']['metadata']['blockVisibility'] = $value;
				return $parsed;
			};
			$visibility_faq = $faq( array( array( 'question' => 'Visibility test?', 'answer' => '<p>Visible answer.</p>' ) ) );
			$all_hidden = $viewport( $known_viewports );
			$first_hidden = $viewport( array( $known_viewports[0] ) );
			$rest_hidden = $viewport( array_slice( $known_viewports, 1 ) );
			$visibility_synced = $make_post( 'visibility reference', array( $visibility( $visibility_faq, $rest_hidden ) ) );
			$wrap = static fn ( array $child, $value, ?string $wrapper_name = null ): array => $visibility( $block( $wrapper_name ?? $namespace . '/el-wrapper', array(), array( $child ) ), $value );
			$visible_viewports_in_markup = static function ( string $rendered ) use ( $known_viewports ): array {
				$viewports = array();
				if ( '' === trim( $rendered ) ) { return $viewports; }
				$document = new DOMDocument( '1.0', 'UTF-8' );
				$document->loadHTML( '<meta charset="UTF-8">' . $rendered, LIBXML_NOERROR | LIBXML_NOWARNING );
				$xpath = new DOMXPath( $document );
				foreach ( $known_viewports as $viewport_name ) {
					$selector = '//summary[not(ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " wp-block-hidden-' . $viewport_name . ' ")])]';
					if ( $xpath->query( $selector )->length > 0 ) { $viewports[] = $viewport_name; }
				}
				return $viewports;
			};
			$visibility_cases = array(
				'boolean-hidden FAQ' => array( $visibility( $visibility_faq, false ), false, true ),
				'boolean-hidden ancestor' => array( $wrap( $visibility_faq, false ), false, true ),
				'boolean-hidden synced reference' => array( $visibility( $ref( $visibility_synced ), false ), false, true ),
				'all known viewports hidden' => array( $visibility( $visibility_faq, $all_hidden ), false, false ),
				'partially visible FAQ' => array( $visibility( $visibility_faq, $first_hidden ), true, false ),
				'ancestor intersection empty' => array( $wrap( $visibility( $visibility_faq, $rest_hidden ), $first_hidden ), false, false ),
				'ancestor intersection nonempty' => array( $wrap( $visibility_faq, $first_hidden ), true, false ),
				'synced ancestor intersection empty' => array( $wrap( $ref( $visibility_synced ), $first_hidden ), false, false ),
				'synced partial visibility' => array( $ref( $visibility_synced ), true, false ),
				'boolean-hidden FAQ with support off' => array( $visibility( $visibility_faq, false ), false, true, $name ),
				'viewport-hidden FAQ with support off' => array( $visibility( $visibility_faq, $all_hidden ), true, false, $name ),
				'boolean-hidden ancestor with support off' => array( $wrap( $visibility_faq, false ), false, true, $namespace . '/el-wrapper' ),
				'viewport-hidden ancestor with support off' => array( $wrap( $visibility_faq, $all_hidden ), true, false, $namespace . '/el-wrapper' ),
				'unknown parent boolean ignored' => array( $wrap( $visibility_faq, false, $sentinel_name . '-unknown' ), true, false ),
				'unknown parent viewport ignored' => array( $wrap( $visibility_faq, $all_hidden, $sentinel_name . '-unknown' ), true, false ),
				'unknown viewport ignored' => array( $visibility( $visibility_faq, $viewport( array( 'unknown-test-viewport' ) ) ), true, false ),
				'malformed viewport ignored' => array( $visibility( $visibility_faq, array( 'viewport' => 'false' ) ), true, false ),
				'non-boolean hiding ignored' => array( $visibility( $visibility_faq, 'false' ), true, false ),
			);
			$visibility_content = '';
			$visibility_page = static function ( array $pages, WP_Post $post ) use ( $root_id, &$visibility_content ): array {
				return $root_id === $post->ID ? array( $visibility_content ) : $pages;
			};
			add_filter( 'content_pagination', $visibility_page, 20, 2 );
			try {
				foreach ( $visibility_cases as $label => $case ) {
					$visibility_content = serialize_blocks( array( $case[0] ) );
					$changed_type = isset( $case[3] ) ? WP_Block_Type_Registry::get_instance()->get_registered( $case[3] ) : null;
					$supports = $changed_type ? $changed_type->supports : null;
					try {
						if ( $changed_type ) { $changed_type->supports['visibility'] = false; }
						$reset();
						$before = $context();
						$counters_before = $counters;
						$styles_before = serialize( WP_Style_Engine_CSS_Rules_Store::get_stores() );
						$result = $head();
						$assert( $before === $context() && $counters_before === $counters, $label . ': collection changed context or invoked rendering.' );
						$assert( $styles_before === serialize( WP_Style_Engine_CSS_Rules_Store::get_stores() ), $label . ': collection generated visibility styles.' );
						$assert( $case[1] === ( 1 === $result['scripts'] ), $label . ': incorrect schema visibility.' );
						$assert( (int) $case[1] === count( $visits ), $label . ': provider ran for a fully hidden branch.' );
						$rendered = render_block( $case[0] );
						$assert( $case[2] === ( '' === trim( $rendered ) ), $label . ': unexpected WordPress boolean visibility output.' );
						$visible_viewports = $visible_viewports_in_markup( $rendered );
						$assert( $case[1] === ( array() !== $visible_viewports ), $label . ': schema disagrees with actual WordPress rendered visibility classes.' );
					} finally {
						if ( $changed_type ) { $changed_type->supports = $supports; }
					}
				}

				// A viewport-conditioned reference applies its classes only to the
				// first tag. Unsupported roots deliberately omit schema, not HTML.
				$single_pattern = $make_post( 'single FAQ root', array( $visibility_faq ) );
				$whitespace = $block( 'core/paragraph' );
				$whitespace['blockName'] = null;
				$whitespace['innerHTML'] = "\n \t";
				$whitespace['innerContent'] = array( "\n \t" );
				$wrapper_pattern = $make_post( 'whitespace and wrapper', array( $whitespace, $wrap( $visibility_faq, null ), $whitespace ) );
				$multi_pattern = $make_post( 'paragraph and FAQ siblings', array( $block( 'core/paragraph', array(), array(), '<p>First emitted root.</p>' ), $visibility_faq ) );
				register_block_type( $dynamic_name, array( 'api_version' => 3, 'render_callback' => static function ( array $attrs, string $content ) use ( &$counters ): string { ++$counters['unrelated']; return '<div>' . $content . '</div>'; } ) );
				$dynamic_pattern = $make_post( 'uncertified dynamic root', array( $block( $dynamic_name, array(), array( $visibility_faq ) ) ) );
				$nested_pattern = $make_post( 'certified wrapper with nested reference', array( $wrap( $visibility( $ref( $single_pattern ), $rest_hidden ), null ) ) );
				$outside = $faq( array( array( 'question' => 'Unaffected outside FAQ?', 'answer' => '<p>Unrelated contribution.</p>' ) ) );
				$certify_wrapper = static function ( bool $certified, array $root, array $reference, int $post_id ) use ( $namespace, $assert ): bool {
					$assert( 'core/block' === $reference['blockName'] && $post_id > 0, 'Root certificate lost its reference context.' );
					return $certified || $namespace . '/el-wrapper' === $root['blockName'];
				};
				$assert( true === apply_filters( 'st_wp_core_schema_synced_viewport_root', false, $visibility_faq, $ref( $single_pattern ), $single_pattern ), 'FAQ bootstrap must certify its own single-root output contract.' );
				add_filter( 'st_wp_core_schema_synced_viewport_root', $certify_wrapper, 20, 4 );
				$policy_cases = array(
					'single FAQ certified by bootstrap' => array( $visibility( $ref( $single_pattern ), $first_hidden ), true, true, false ),
					'single FAQ all hidden' => array( $visibility( $ref( $single_pattern ), $all_hidden ), false, false, false ),
					'whitespace around certified wrapper' => array( $visibility( $ref( $wrapper_pattern ), $first_hidden ), true, true, false ),
					'outer ancestor constraints preserved' => array( $wrap( $visibility( $ref( $wrapper_pattern ), $rest_hidden ), $first_hidden ), false, false, false ),
					'nested reference intersection preserved' => array( $visibility( $ref( $nested_pattern ), $first_hidden ), false, false, false ),
					'multi-root partial viewport unsupported' => array( $visibility( $ref( $multi_pattern ), $first_hidden ), false, true, true ),
					'multi-root all viewports unsupported' => array( $visibility( $ref( $multi_pattern ), $all_hidden ), false, true, true ),
					'unknown dynamic root unsupported' => array( $visibility( $ref( $dynamic_pattern ), $first_hidden ), false, true, true ),
					'boolean hides multi-root normally' => array( $visibility( $ref( $multi_pattern ), false ), false, false, false ),
					'viewport support off ignores reference metadata' => array( $visibility( $ref( $multi_pattern ), $all_hidden ), true, true, false, 'core/block' ),
					'boolean hides with reference support off' => array( $visibility( $ref( $multi_pattern ), false ), false, false, false, 'core/block' ),
					'unknown viewport reference metadata ignored' => array( $visibility( $ref( $multi_pattern ), $viewport( array( 'unknown-test-viewport' ) ) ), true, true, false ),
					'ordinary ancestor can contain multi-root reference' => array( $wrap( $ref( $multi_pattern ), $first_hidden ), true, true, false ),
				);
				foreach ( $policy_cases as $label => $case ) {
					$visibility_content = serialize_blocks( array( $case[0], $outside ) );
					$changed_type = isset( $case[4] ) ? WP_Block_Type_Registry::get_instance()->get_registered( $case[4] ) : null;
					$supports = $changed_type ? $changed_type->supports : null;
					try {
						if ( $changed_type ) { $changed_type->supports['visibility'] = false; }
						$reset();
						$before = $context();
						$counters_before = $counters;
						$styles_before = serialize( WP_Style_Engine_CSS_Rules_Store::get_stores() );
						$diagnostics_before = count( $diagnostics );
						$result = $head();
						$expected_names = $case[1] ? array( 'Visibility test?', 'Unaffected outside FAQ?' ) : array( 'Unaffected outside FAQ?' );
						$assert( $expected_names === array_column( $result['graph']['@graph'][0]['mainEntity'] ?? array(), 'name' ), $label . ': affected reference/unrelated schema isolation failed.' );
						$assert( ( $case[1] ? 2 : 1 ) === count( $visits ), $label . ': provider ran on a rejected reference.' );
						$assert( (int) $case[3] === count( $diagnostics ) - $diagnostics_before, $label . ': missing/unexpected unsupported-root diagnostic.' );
						$assert( $before === $context() && $counters_before === $counters, $label . ': root certification rendered or changed ambient context.' );
						$assert( $styles_before === serialize( WP_Style_Engine_CSS_Rules_Store::get_stores() ), $label . ': certification generated styles.' );
						$rendered = render_block( $case[0] );
						$assert( $case[2] === ( array() !== $visible_viewports_in_markup( $rendered ) ), $label . ': actual WordPress visibility did not reproduce the expected first-root behavior.' );
					} finally {
						if ( $changed_type ) { $changed_type->supports = $supports; }
					}
				}
			} finally {
				remove_filter( 'content_pagination', $visibility_page, 20 );
				if ( isset( $certify_wrapper ) ) { remove_filter( 'st_wp_core_schema_synced_viewport_root', $certify_wrapper, 20 ); }
			}
			$scenarios[] = 'WP7 actual rendering: boolean/viewport hiding; hidden ancestors and synced references; viewport intersections; disabled support; unknown/malformed metadata; no collection styles';
			$scenarios[] = 'conditioned synced references require a certified sole root; multi-root/dynamic omission is diagnostic, not universal parity; unrelated contributions preserved';

			$paginated_id = $make_post( 'nested nextpage boundary', array(
				$block( $namespace . '/el-wrapper', array(), array(
					$faq( array( array( 'question' => 'First paginated question?', 'answer' => '<p>First page answer.</p>' ) ) ),
					$block( 'core/nextpage', array(), array(), '<!--nextpage-->' ),
					$faq( array( array( 'question' => 'Second paginated question?', 'answer' => '<p>Second page answer.</p>' ) ) ),
				) ),
			) );
			$paginated_canonical = get_permalink( $paginated_id );
			foreach ( array( null, 1, 2, 99 ) as $content_page ) {
				$reset();
				$query = $set_query( $paginated_id, $content_page );
				$_GET['page'] = '42';
				$_GET['showall'] = '1';
				$_SERVER['REQUEST_URI'] = '/not-the-canonical/42/?page=42&showall=1';
				$before = $context();
				$counters_before = $counters;
				$result = $head();
				$assert( $before === $context(), 'Pagination collection changed WordPress/ACF ambient context.' );
				$assert( $counters_before === $counters, 'Pagination collection executed render/content paths.' );
				$selected = null === $content_page || 1 === $content_page ? 1 : 2;
				$expected = 1 === $selected ? 'First paginated question?' : 'Second paginated question?';
				$pagination_graph = $result['graph']['@graph'][0] ?? array();
				$assert( array( $expected ) === array_column( $pagination_graph['mainEntity'] ?? array(), 'name' ), 'Schema leaked a different content page: ' . (string) $content_page );
				$assert( $paginated_canonical . '#faq' === ( $pagination_graph['@id'] ?? '' ), 'Content page/raw URL replaced the queried canonical schema ID.' );
				$assert( 1 === count( $visits ) && str_starts_with( $visits[0][1], 'post:' . $paginated_id . '/page:' . $selected . '/' ), 'Pagination source key did not identify the selected occurrence.' );
				$visible_content = get_the_content( '', false, get_post( $paginated_id ) );
				$assert( ! str_contains( $visible_content, 'wp:nextpage' ), 'WordPress did not remove nextpage metadata before parsing.' );
				$selected_html = do_blocks( $visible_content );
				$selected_document = new DOMDocument( '1.0', 'UTF-8' );
				$selected_document->loadHTML( '<meta charset="UTF-8">' . $selected_html, LIBXML_NOERROR | LIBXML_NOWARNING );
				$selected_summaries = ( new DOMXPath( $selected_document ) )->query( '//summary' );
				$assert( 1 === $selected_summaries->length && $expected === trim( $selected_summaries->item( 0 )->textContent ), 'Schema differs from actual paginated get_the_content() rendering.' );
			}
			// WordPress has no built-in showall flag. A project's content_pagination
			// filter can combine pages; collection must follow that same public API.
			$show_all = static fn ( array $pages, WP_Post $post ): array => $paginated_id === $post->ID ? array( implode( '', $pages ) ) : $pages;
			add_filter( 'content_pagination', $show_all, 10, 2 );
			$reset();
			$query = $set_query( $paginated_id, 2 );
			$before = $context();
			$counters_before = $counters;
			$result = $head();
			$assert( $before === $context() && $counters_before === $counters, 'Project pagination filter caused context/render side effects.' );
			$assert( array( 'First paginated question?', 'Second paginated question?' ) === array_column( $result['graph']['@graph'][0]['mainEntity'] ?? array(), 'name' ), 'Collection did not honor the same content_pagination filter as WordPress.' );
			remove_filter( 'content_pagination', $show_all, 10 );
			$scenarios[] = 'nested nextpage boundaries; default/page 1/page 2/out-of-range; canonical query ID; raw showall ignored; content_pagination filter';

			// Reset only this request's internal fixture registry between scenarios.
			foreach ( array( 'preview', 'feed', 'admin', 'password', 'global-disabled', 'faq-disabled' ) as $scenario ) {
				$reset();
				$query = $set_query( 'password' === $scenario ? $protected_root : $root_id );
				unset( $GLOBALS['current_screen'] );
				if ( 'preview' === $scenario ) { $query->is_preview = true; }
				if ( 'feed' === $scenario ) { $query->is_feed = true; $query->query_vars['feed'] = 'rss2'; }
				if ( 'admin' === $scenario ) {
					if ( ! function_exists( 'get_current_screen' ) ) { require_once ABSPATH . 'wp-admin/includes/screen.php'; }
					if ( ! class_exists( 'WP_Screen' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php'; }
					$GLOBALS['current_screen'] = WP_Screen::get( 'dashboard' );
				}
				$off = static fn (): bool => false;
				$type_off = static fn ( bool $enabled, string $schema_type ): bool => 'FAQPage' !== $schema_type;
				if ( 'global-disabled' === $scenario ) { add_filter( 'st_wp_core_schema_enabled', $off ); }
				if ( 'faq-disabled' === $scenario ) {
					add_filter( 'st_wp_core_schema_type_enabled', $type_off, 10, 2 );
					$assert( st_wp_core_schema_add_breadcrumbs( $trail ), 'FAQ ownership switch disabled breadcrumbs too.' );
				}
				$before = $context();
				$counters_before = $counters;
				$result = $head();
				$assert( $before === $context(), $scenario . ' changed ambient context.' );
				$assert( $counters_before === $counters, $scenario . ' executed render/content paths.' );
				if ( 'faq-disabled' === $scenario ) {
					$assert( array( 'BreadcrumbList' ) === array_column( $result['graph']['@graph'] ?? array(), '@type' ), 'FAQ type-disable did not preserve independent types.' );
				} else {
					$assert( 0 === $result['scripts'] && array() === $visits, $scenario . ' did not suppress output/collection.' );
					$assert( ! st_wp_core_schema_add( array( '@type' => 'Thing' ), 'suppressed' ), $scenario . ' accepted a new contribution.' );
				}
				remove_filter( 'st_wp_core_schema_enabled', $off );
				remove_filter( 'st_wp_core_schema_type_enabled', $type_off, 10 );
				$scenarios[] = $scenario;
			}
		}
	} finally {
		$_GET = $saved_get;
		if ( $saved_request_uri['exists'] ) { $_SERVER['REQUEST_URI'] = $saved_request_uri['value']; } else { unset( $_SERVER['REQUEST_URI'] ); }
		$state = $initial_state;
		$local_meta->meta = $saved_meta;
		$local_meta->post_id = $saved_meta_post_id;
		foreach ( $saved_globals as $global => $saved ) {
			if ( $saved['exists'] ) { $GLOBALS[ $global ] = $saved['value']; } else { unset( $GLOBALS[ $global ] ); }
		}
		foreach ( $saved_hooks as $hook => $saved ) {
			if ( null !== $saved ) { $GLOBALS['wp_filter'][ $hook ] = $saved; } else { unset( $GLOBALS['wp_filter'][ $hook ] ); }
		}
		if ( WP_Block_Type_Registry::get_instance()->is_registered( $sentinel_name ) ) { unregister_block_type( $sentinel_name ); }
		if ( WP_Block_Type_Registry::get_instance()->is_registered( $dynamic_name ) ) { unregister_block_type( $dynamic_name ); }
		foreach ( array_reverse( $created ) as $id ) {
			if ( ! wp_delete_post( $id, true ) || get_post( $id ) ) {
				throw new RuntimeException( 'Unable to delete owned temporary wp_block fixture: ' . $id );
			}
		}
	}
	WP_CLI::log( wp_json_encode( array(
		'assertions' => $checks, 'scenarios' => $scenarios, 'temporary_posts_removed' => count( $created ),
		'theme' => get_stylesheet(), 'namespace' => $namespace, 'wordpress' => get_bloginfo( 'version' ), 'acf' => ACF_VERSION, 'core' => ST_WP_CORE_VERSION,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
} )();
