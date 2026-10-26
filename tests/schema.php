<?php
/**
 * Isolated core schema contracts. No WordPress bootstrap, database, or network.
 *
 * The parser stub accepts fixture arrays encoded as JSON; real Gutenberg/ACF
 * parsing and frontend parity are covered separately by WordPress integration.
 */

declare(strict_types=1);

$schema_test_root = sys_get_temp_dir() . '/st-schema-' . bin2hex( random_bytes( 8 ) );
mkdir( $schema_test_root . '/inc', 0777, true );
define( 'ABSPATH', $schema_test_root . '/' );

$schema_test_context = array();
$schema_test_hooks = array();
$schema_test_actions = array();
$schema_test_diagnostics = array();
$schema_test_posts = array();
$schema_test_assertions = 0;
$schema_test_registered = array();
$schema_test_parse_calls = 0;
$schema_test_block_types = array();

class WP_Block_Type_Registry {
	public static function get_instance(): self { static $registry; return $registry ??= new self(); }
	public function get_registered( string $name ): ?object { return $GLOBALS['schema_test_block_types'][ $name ] ?? null; }
}

class WP_Theme_JSON {
	public static function get_viewport_media_queries( $settings, array $options = array() ): array {
		schema_test_assert( true === ( $options['include_desktop'] ?? false ), 'Visibility includes desktop queries.' );
		return $GLOBALS['schema_test_context']['viewport_queries'] ?? array( '@mobile' => '@media (width <= 600px)', '@tablet' => '@media (600px < width <= 960px)', '@desktop' => '@media (width > 960px)' );
	}
}

function block_has_support( object $type, string $feature, bool $default = false ): bool { return $type->supports[ $feature ] ?? $default; }
function wp_get_global_settings( array $path ): array {
	schema_test_assert( array( 'viewport' ) === $path, 'Visibility reads only global viewport settings.' );
	return array( 'mobile' => '600px', 'tablet' => '960px' );
}

function schema_test_assert( bool $condition, string $message ): void {
	global $schema_test_assertions;
	++$schema_test_assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_template_directory(): string { return $GLOBALS['schema_test_root']; }
function get_stylesheet_directory(): string { return get_template_directory(); }
function is_admin(): bool { return $GLOBALS['schema_test_context']['admin'] ?? false; }
function is_feed(): bool { return $GLOBALS['schema_test_context']['feed'] ?? false; }
function is_preview(): bool { return $GLOBALS['schema_test_context']['preview'] ?? false; }
function is_singular(): bool { return $GLOBALS['schema_test_context']['singular'] ?? true; }
function wp_is_serving_rest_request(): bool { return $GLOBALS['schema_test_context']['rest'] ?? false; }
function get_queried_object_id(): int { return 10; }
function get_permalink( int $id ): string|false { return $GLOBALS['schema_test_context']['permalink'] ?? 'https://example.test/canonical/'; }
function get_post( int|object $id ): ?object { return is_object( $id ) ? $id : ( $GLOBALS['schema_test_posts'][ $id ] ?? null ); }
function generate_postdata( object $post ): array|false {
	return $GLOBALS['schema_test_context']['postdata'] ?? array( 'page' => 1, 'pages' => array( $post->post_content ) );
}
function post_password_required( int|object $post ): bool { return $GLOBALS['schema_test_context']['password_required'] ?? false; }
function esc_url_raw( string $url, array $protocols ): string { return filter_var( $url, FILTER_SANITIZE_URL ); }
function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function wp_strip_all_tags( string $text ): string {
	return strip_tags( (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $text ) );
}
function parse_blocks( string $content ): array {
	++$GLOBALS['schema_test_parse_calls'];
	return json_decode( $content, true, 512, JSON_THROW_ON_ERROR );
}
function _doing_it_wrong( string $function, string $message, string $version ): void {
	$GLOBALS['schema_test_diagnostics'][] = array( $function, $message, $version );
}
function add_filter( string $hook, callable|string $callback, int $priority = 10, int $arguments = 1 ): void {
	$GLOBALS['schema_test_hooks'][ $hook ][ $priority ][] = array( $callback, $arguments );
}
function add_action( string $hook, callable|string $callback, int $priority = 10, int $arguments = 1 ): void {
	add_filter( $hook, $callback, $priority, $arguments );
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$callbacks = $GLOBALS['schema_test_hooks'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as [ $callback, $arguments ] ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $arguments ) );
		}
	}
	return $value;
}
function do_action( string $hook ): void {
	$GLOBALS['schema_test_actions'][ $hook ] = did_action( $hook ) + 1;
	$callbacks = $GLOBALS['schema_test_hooks'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as [ $callback ] ) {
			$callback();
		}
	}
}
function did_action( string $hook ): int { return $GLOBALS['schema_test_actions'][ $hook ] ?? 0; }
function acf_register_block_type(): void {}
function register_block_type( string $path ): object|false {
	$GLOBALS['schema_test_registered'][] = basename( $path );
	return 'failed' === basename( $path ) ? false : (object) array( 'name' => 'test/' . basename( $path ) );
}

function schema_test_remove( string $directory ): void {
	foreach ( new FilesystemIterator( $directory ) as $file ) {
		if ( $file->isDir() && ! $file->isLink() ) {
			schema_test_remove( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}
	rmdir( $directory );
}

function schema_test_reset(): void {
	$state =& st_wp_core_schema_state();
	$state = array( 'nodes' => array(), 'faq' => array(), 'providers' => array(), 'collected' => false, 'flushed' => false );
	$GLOBALS['schema_test_context'] = array();
	$GLOBALS['schema_test_hooks'] = array();
	$GLOBALS['schema_test_actions'] = array();
	$GLOBALS['schema_test_diagnostics'] = array();
	$GLOBALS['schema_test_parse_calls'] = 0;
	$GLOBALS['schema_test_posts'] = array( 10 => schema_test_post( array() ) );
	$GLOBALS['schema_test_block_types'] = array_fill_keys(
		array( 'test/faq', 'test/wrapper', 'core/block' ), (object) array( 'supports' => array() )
	);
	add_action( 'wp_head', 'st_wp_core_schema_print', PHP_INT_MAX );
}

function schema_test_post( array $blocks, string $type = 'page', string $status = 'publish', string $password = '' ): object {
	return (object) array( 'post_content' => json_encode( $blocks, JSON_THROW_ON_ERROR ), 'post_type' => $type, 'post_status' => $status, 'post_password' => $password );
}

function schema_test_block( string $name, array $children = array(), array $attrs = array() ): array {
	return array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children );
}

/** @return array{html:string,graph:array} */
function schema_test_head(): array {
	ob_start();
	do_action( 'wp_head' );
	$html = ob_get_clean();
	$graph = array();
	if ( preg_match( '#<script type="application/ld\+json" id="st-wp-core-schema">(.*?)</script>#s', $html, $matches ) ) {
		$graph = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
	}
	return array( 'html' => $html, 'graph' => $graph );
}

try {
	if ( 'rest' === ( $argv[1] ?? '' ) ) {
		define( 'REST_REQUEST', true );
	}
	if ( 'override' === ( $argv[1] ?? '' ) ) {
		file_put_contents( $schema_test_root . '/inc/schema.php', '<?php function st_wp_core_schema_add(array $node, string $key): bool { return $key === "override"; }' );
	}
	require dirname( __DIR__ ) . '/resources/core/files/core/schema.php';
	if ( 'rest' === ( $argv[1] ?? '' ) ) {
		schema_test_assert( ! st_wp_core_schema_enabled(), 'REST_REQUEST constant suppresses output.' );
		echo "REST constant passed.\n";
	} elseif ( 'override' === ( $argv[1] ?? '' ) ) {
		schema_test_assert( st_wp_core_schema_add( array(), 'override' ), 'Project override loads before guarded defaults.' );
		schema_test_assert( ! st_wp_core_schema_add( array(), 'default' ), 'Override is not replaced.' );
		echo "Override passed.\n";
	} else {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/resources/core/st-wp-core-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		schema_test_assert( '1.2.0' === $manifest['version'], 'Packaged core declares the schema API version.' );
		foreach ( $manifest['checksums'] as $file => $checksum ) {
			schema_test_assert( $checksum === hash_file( 'sha256', dirname( __DIR__ ) . '/resources/core/files/' . $file ), 'Packaged checksum matches: ' . $file );
		}
		foreach ( array( 'st_wp_core_schema_add', 'st_wp_core_schema_add_breadcrumbs', 'st_wp_core_schema_add_faq', 'st_wp_core_schema_register_block_provider' ) as $helper ) {
			schema_test_assert( in_array( $helper, $manifest['required_public_helpers'], true ), 'Manifest requires public helper ' . $helper );
		}
		schema_test_reset();
		schema_test_assert( '' === schema_test_head()['html'], 'Empty graph prints nothing.' );
		schema_test_assert( empty( $schema_test_hooks['wp_footer'] ), 'No footer fallback is registered.' );
		schema_test_assert( isset( $schema_test_hooks['wp_head'][ PHP_INT_MAX ] ), 'Head callback runs late.' );

		schema_test_reset();
		$recursive = array();
		$recursive['self'] =& $recursive;
		$resource = fopen( 'php://memory', 'r+' );
		$malformed = array(
			array(), array( '@type' => '' ), array( '@type' => array() ), array( '@type' => array( 'Thing', 1 ) ),
			array( '@type' => new stdClass() ), array( '@type' => 'Thing', 'bad' => new stdClass() ),
			array( '@type' => 'Thing', 'bad' => INF ), array( '@type' => 'Thing', 'bad' => $resource ),
			array( '@type' => 'Thing', 'bad' => $recursive ), array( '@type' => 'Thing', '@graph' => array() ),
			array( '@type' => 'Thing', '@context' => 'https://evil.test' ), array( '@type' => 'Bad Type' ),
			array( '@type' => array( 'named' => 'Thing' ) ), array( '@type' => array( 2 => 'Thing' ) ),
			array( '@type' => array( 1 => 'Thing', 0 => 'CreativeWork' ) ), array( '@type' => array( true ) ),
		);
		foreach ( $malformed as $index => $node ) {
			schema_test_assert( ! st_wp_core_schema_add( $node, 'bad-' . $index ), 'Malformed node rejected: ' . $index );
		}
		fclose( $resource );
		schema_test_assert( ! st_wp_core_schema_add( array( '@type' => 'Thing' ), ' ' ), 'Empty node key rejected.' );
		$payload = '</script><script>alert("x")</script>&\' literal';
		$node = array( '@type' => array( 'Thing', 'CreativeWork' ), 'name' => $payload, 'other' => array( null, true, 1, 1.5 ) );
		schema_test_assert( st_wp_core_schema_add( array( '@type' => 'Thing', 'name' => 'old' ), 'same' ), 'First node accepted.' );
		schema_test_assert( st_wp_core_schema_add( $node, 'same' ), 'Same key replaces the node.' );
		$result = schema_test_head();
		schema_test_assert( 1 === count( $result['graph']['@graph'] ), 'Replacement does not duplicate node.' );
		schema_test_assert( $payload === $result['graph']['@graph'][0]['name'], 'Safe encoding preserves data.' );
		schema_test_assert( 1 === substr_count( $result['html'], '<script' ), 'Payload cannot inject a second script.' );
		schema_test_assert( str_contains( $result['html'], '\\u003C' ), 'Angle brackets are JSON hex encoded.' );
		schema_test_assert( 'https://schema.org' === $result['graph']['@context'], 'One canonical graph context.' );
		schema_test_assert( st_wp_core_schema_add( $node, 'same' ), 'Identical late registration is idempotent.' );
		schema_test_assert( ! st_wp_core_schema_add( array( '@type' => 'Thing', 'name' => 'changed' ), 'same' ), 'Changed late node rejected.' );
		schema_test_assert( ! st_wp_core_schema_add( $node, 'new' ), 'New late node rejected.' );
		schema_test_assert( 2 === count( $schema_test_diagnostics ), 'Late mutations issue development diagnostics.' );
		schema_test_assert( '' === schema_test_head()['html'], 'Repeated head calls do not duplicate output.' );

		schema_test_reset();
		$_SERVER['REQUEST_URI'] = '/not-canonical/?preview=true';
		$trail = array(
			array( 'name' => ' Home ', 'url' => 'https://example.test/' ),
			array( 'name' => 'Invalid', 'url' => 'javascript:alert(1)' ),
			array( 'name' => 'Relative', 'url' => '/relative' ),
			array( 'name' => 'Host only', 'url' => 'example.test' ),
			array( 'name' => 'Auth', 'url' => 'https://user:secret@example.test/' ),
			array( 'name' => 'Protocol relative', 'url' => '//example.test' ),
			array( 'name' => 2, 'url' => 'https://example.test/' ),
			array( 'name' => '   ', 'url' => 'https://example.test/' ),
			array( 'name' => 'Current <example>', 'url' => 'https://example.test/canonical/' ), null,
		);
		schema_test_assert( ! st_wp_core_schema_add_breadcrumbs( array() ), 'Empty breadcrumb trail rejected.' );
		schema_test_assert( st_wp_core_schema_add_breadcrumbs( $trail ), 'Valid breadcrumb rows accepted.' );
		$result = schema_test_head()['graph']['@graph'][0];
		schema_test_assert( 'https://example.test/canonical/#breadcrumb' === $result['@id'], 'Breadcrumb ID uses permalink, not request.' );
		schema_test_assert( array( 1, 2 ) === array_column( $result['itemListElement'], 'position' ), 'Invalid rows do not leave position gaps.' );
		schema_test_assert( 'Home' === $result['itemListElement'][0]['name'], 'Breadcrumb name whitespace normalized.' );
		schema_test_assert( 'Current <example>' === $result['itemListElement'][1]['name'], 'Plain-text literal names preserved.' );
		schema_test_assert( st_wp_core_schema_add_breadcrumbs( $trail ), 'Identical late trail accepted.' );
		schema_test_assert( ! st_wp_core_schema_add_breadcrumbs( array( $trail[0] ) ), 'Changed late trail rejected.' );
		schema_test_assert( 1 === count( $schema_test_diagnostics ), 'Late trail diagnostic emitted.' );

		schema_test_reset();
		$pairs = array(
			array( 'question' => ' A <b>literal</b> &amp;?', 'answer' => '<p>First&nbsp;paragraph.</p><p>Second<br>line.</p>' ),
			array( 'question' => 'Incomplete', 'answer' => '<p> </p><script>alert(1)</script>' ),
			array( 'question' => '', 'answer' => 'No question' ), array( 'question' => array(), 'answer' => 'Malformed' ), null,
			array( 'question' => 'Encoded examples?', 'answer' => '<p>&lt;b&gt;example&lt;/b&gt; &amp;amp; &amp;lt;</p>' ),
		);
		schema_test_assert( ! st_wp_core_schema_add_faq( array(), 'empty' ), 'Empty FAQ rejected.' );
		schema_test_assert( ! st_wp_core_schema_add_faq( $pairs, ' ' ), 'Empty FAQ source key rejected.' );
		schema_test_assert( st_wp_core_schema_add_faq( $pairs, 'one' ), 'Complete FAQ rows accepted.' );
		schema_test_assert( st_wp_core_schema_add_faq( $pairs, 'two' ), 'Multiple FAQ sources accepted.' );
		schema_test_assert( st_wp_core_schema_add_faq( array( array( 'question' => 'Temporary', 'answer' => 'Previous answer' ) ), 'three' ), 'Third FAQ source registered.' );
		schema_test_assert( st_wp_core_schema_add_faq( array( array( 'question' => 'New', 'answer' => 'New answer' ) ), 'three' ), 'Same source replaces FAQ contribution.' );
		$result = schema_test_head()['graph']['@graph'];
		schema_test_assert( 1 === count( $result ) && 'FAQPage' === $result[0]['@type'], 'Sources become one FAQPage.' );
		schema_test_assert( 'https://example.test/canonical/#faq' === $result[0]['@id'], 'FAQ uses canonical page ID.' );
		$questions = $result[0]['mainEntity'];
		schema_test_assert( 3 === count( $questions ), 'Identical complete pairs deduplicated across sources.' );
		schema_test_assert( 'A <b>literal</b> &amp;?' === $questions[0]['name'], 'Plain question is not stripped or decoded twice.' );
		schema_test_assert( 'First paragraph. Second line.' === $questions[0]['acceptedAnswer']['text'], 'HTML boundaries preserve word separation.' );
		schema_test_assert( '<b>example</b> &amp; &lt;' === $questions[1]['acceptedAnswer']['text'], 'Escaped HTML literals decoded only once.' );
		schema_test_assert( 'New' === $questions[2]['name'], 'Replaced source retains source order.' );
		schema_test_assert( st_wp_core_schema_add_faq( $pairs, 'one' ), 'Identical late FAQ accepted.' );
		schema_test_assert( ! st_wp_core_schema_add_faq( array( array( 'question' => 'Different', 'answer' => 'Answer' ) ), 'one' ), 'Changed late FAQ rejected.' );

		foreach ( array( 'admin', 'feed', 'preview', 'rest', 'password_required', 'protected_unlocked' ) as $context ) {
			schema_test_reset();
			if ( 'protected_unlocked' === $context ) {
				$schema_test_posts[10]->post_password = 'protected';
			} else {
				$schema_test_context[ $context ] = true;
			}
			schema_test_assert( ! st_wp_core_schema_add( array( '@type' => 'Thing' ), 'blocked' ), $context . ': node rejected.' );
			schema_test_assert( ! st_wp_core_schema_add_breadcrumbs( $trail ), $context . ': breadcrumbs rejected.' );
			schema_test_assert( ! st_wp_core_schema_add_faq( $pairs, 'blocked' ), $context . ': FAQ rejected.' );
			schema_test_assert( '' === schema_test_head()['html'], $context . ': no output.' );
			schema_test_assert( 0 === $schema_test_parse_calls, $context . ': no collection.' );
		}

		schema_test_reset();
		$schema_test_context['singular'] = false;
		schema_test_assert( ! st_wp_core_schema_add_faq( $pairs, 'archive' ), 'No invented canonical FAQ on archive.' );
		schema_test_assert( ! st_wp_core_schema_add_breadcrumbs( $trail ), 'No invented canonical breadcrumbs on archive.' );
		schema_test_assert( st_wp_core_schema_add( array( '@type' => 'CollectionPage' ), 'archive' ), 'Generic archive nodes remain possible.' );
		schema_test_assert( 1 === count( schema_test_head()['graph']['@graph'] ), 'Generic archive graph outputs normally.' );

		schema_test_reset();
		add_filter( 'st_wp_core_schema_enabled', static fn(): bool => false );
		schema_test_assert( ! st_wp_core_schema_add_faq( $pairs, 'disabled' ), 'Global disable rejects contributions.' );
		schema_test_assert( '' === schema_test_head()['html'], 'Global disable suppresses output.' );

		schema_test_reset();
		st_wp_core_schema_add_faq( $pairs, 'before-disable' );
		add_filter( 'st_wp_core_schema_type_enabled', static fn( bool $enabled, string $type ): bool => 'FAQPage' !== $type, 10, 2 );
		schema_test_assert( ! st_wp_core_schema_add_faq( $pairs, 'disabled' ), 'Per-type disable rejects FAQ.' );
		schema_test_assert( st_wp_core_schema_add_breadcrumbs( $trail ), 'Disabling FAQ does not disable breadcrumbs.' );
		add_filter( 'st_wp_core_schema_graph', static function( array $graph ): array {
			$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => array() );
			$graph[] = array( '@type' => new stdClass() );
			$graph[] = array( '@type' => array( 'associative' => 'Thing' ) );
			$graph[] = 'invalid';
			return $graph;
		} );
		$result = schema_test_head()['graph']['@graph'];
		schema_test_assert( array( 'BreadcrumbList' ) === array_column( $result, '@type' ), 'Final filter cannot reintroduce disabled or malformed nodes.' );
		foreach ( array( null, 'bad', array() ) as $filter_result ) {
			schema_test_reset();
			st_wp_core_schema_add( array( '@type' => 'Thing' ), 'test' );
			add_filter( 'st_wp_core_schema_graph', static fn() => $filter_result );
			schema_test_assert( '' === schema_test_head()['html'], 'Final graph filter can suppress output safely.' );
		}

		schema_test_reset();
		$visits = array();
		$provider = static function( array $block, int $post_id, string $source_key ) use ( &$visits ): void {
			schema_test_assert( did_action( 'wp_head' ) > 0, 'Providers collect inside wp_head before flush.' );
			$visits[] = array( $post_id, $source_key );
			st_wp_core_schema_add_faq( array( array( 'question' => $block['attrs']['question'], 'answer' => 'Answer' ) ), $source_key );
		};
		st_wp_core_schema_register_block_provider( 'test/faq', $provider );
		st_wp_core_schema_register_block_provider( 'invalid block', $provider );
		schema_test_assert( 1 === count( st_wp_core_schema_state()['providers'] ), 'Invalid provider name rejected.' );
		$faq = static fn( string $question ): array => schema_test_block( 'test/faq', array(), array( 'question' => $question ) );
		$ref = static fn( int $id ): array => schema_test_block( 'core/block', array(), array( 'ref' => $id ) );
		$schema_test_posts[10] = schema_test_post( array(
			schema_test_block( 'test/wrapper', array( $faq( 'First' ), schema_test_block( 'core/query', array( $faq( 'Must skip' ) ) ) ) ),
			$ref( 20 ), $ref( 20 ), $ref( 30 ), $ref( 31 ), $ref( 32 ), $ref( 999 ),
			schema_test_block( 'core/shortcode' ), schema_test_block( 'unknown/render' ),
		) );
		$schema_test_posts[20] = schema_test_post( array( $faq( 'Synced' ), $ref( 21 ) ), 'wp_block' );
		$schema_test_posts[21] = schema_test_post( array( $faq( 'Nested synced' ), $ref( 20 ) ), 'wp_block' );
		$schema_test_posts[30] = schema_test_post( array( $faq( 'Draft' ) ), 'wp_block', 'draft' );
		$schema_test_posts[31] = schema_test_post( array( $faq( 'Protected' ) ), 'wp_block', 'publish', 'secret' );
		$schema_test_posts[32] = schema_test_post( array( $faq( 'Not synced' ) ) );
		$GLOBALS['post'] = (object) array( 'ID' => 999 );
		$GLOBALS['acf_context_fixture'] = array( 'post_id' => 777 );
		$before = serialize( array( $GLOBALS['post'], $GLOBALS['acf_context_fixture'], $schema_test_posts ) );
		$result = schema_test_head()['graph']['@graph'][0]['mainEntity'];
		schema_test_assert( array( 'First', 'Synced', 'Nested synced' ) === array_column( $result, 'name' ), 'Nested and synced FAQs collect in order, skipping query/private/unrelated blocks.' );
		schema_test_assert( array(
			array( 10, 'post:10/0/0' ), array( 20, 'post:10/1/ref:20/0' ), array( 21, 'post:10/1/ref:20/1/ref:21/0' ),
			array( 20, 'post:10/2/ref:20/0' ), array( 21, 'post:10/2/ref:20/1/ref:21/0' ),
		) === $visits, 'Provider receives deterministic per-occurrence synced paths and correct content owner.' );
		schema_test_assert( $before === serialize( array( $GLOBALS['post'], $GLOBALS['acf_context_fixture'], $schema_test_posts ) ), 'Collector preserves ambient WordPress/ACF state and source posts.' );
		schema_test_assert( 5 === $schema_test_parse_calls, 'Synced cycle detection is per branch, not global deduplication.' );
		st_wp_core_schema_register_block_provider( 'test/faq', $provider );
		schema_test_assert( 0 === count( $schema_test_diagnostics ), 'Identical late provider registration is harmless.' );
		st_wp_core_schema_register_block_provider( 'test/new', $provider );
		schema_test_assert( 1 === count( $schema_test_diagnostics ), 'New late provider rejected with diagnostic.' );
		schema_test_assert( '' === schema_test_head()['html'] && 5 === $schema_test_parse_calls, 'Second head does not rerun providers.' );

		$visibility = static function( array $block, mixed $value ): array {
			$block['attrs']['metadata']['blockVisibility'] = $value;
			return $block;
		};
		$viewport = static fn( array $hidden ): array => array( 'viewport' => array_fill_keys( $hidden, false ) );
		$all_viewports = $viewport( array( 'mobile', 'tablet', 'desktop' ) );
		$plain_faq = $faq( 'Visible' );
		$wrapper = static fn( array $child, mixed $value, string $name = 'test/wrapper' ): array => $visibility( schema_test_block( $name, array( $child ) ), $value );
		$visibility_cases = array(
			'hidden FAQ' => array( $visibility( $plain_faq, false ), false ),
			'hidden ancestor' => array( $wrapper( $plain_faq, false ), false ),
			'hidden synced reference' => array( $visibility( $ref( 20 ), false ), false ),
			'all viewports hidden' => array( $visibility( $plain_faq, $all_viewports ), false ),
			'partially visible' => array( $visibility( $plain_faq, $viewport( array( 'mobile' ) ) ), true ),
			'ancestor intersection empty' => array( $wrapper( $visibility( $plain_faq, $viewport( array( 'tablet', 'desktop' ) ) ), $viewport( array( 'mobile' ) ) ), false ),
			'ancestor intersection nonempty' => array( $wrapper( $visibility( $plain_faq, $viewport( array( 'tablet' ) ) ), $viewport( array( 'mobile' ) ) ), true ),
			'all-hidden ancestor' => array( $wrapper( $plain_faq, $all_viewports ), false ),
			'synced intersection empty' => array( $wrapper( $visibility( $ref( 20 ), $viewport( array( 'tablet' ) ) ), $viewport( array( 'mobile' ) ) ), false, null, $visibility( $plain_faq, $viewport( array( 'desktop' ) ) ) ),
			'synced intersection nonempty' => array( $wrapper( $ref( 20 ), $viewport( array( 'mobile' ) ) ), true, null, $visibility( $plain_faq, $viewport( array( 'tablet' ) ) ) ),
			'boolean hide with support disabled' => array( $visibility( $plain_faq, false ), false, 'test/faq' ),
			'viewport hide with support disabled' => array( $visibility( $plain_faq, $all_viewports ), true, 'test/faq' ),
			'ancestor boolean hide with support disabled' => array( $wrapper( $plain_faq, false ), false, 'test/wrapper' ),
			'ancestor viewport hide with support disabled' => array( $wrapper( $plain_faq, $all_viewports ), true, 'test/wrapper' ),
			'unregistered ancestor boolean ignored' => array( $wrapper( $plain_faq, false, 'unknown/wrapper' ), true ),
			'unregistered ancestor viewport ignored' => array( $wrapper( $plain_faq, $all_viewports, 'unknown/wrapper' ), true ),
			'unknown viewport ignored' => array( $visibility( $plain_faq, $viewport( array( 'watch' ) ) ), true ),
			'malformed viewport ignored' => array( $visibility( $plain_faq, array( 'viewport' => 'false' ) ), true ),
			'non-boolean hide ignored' => array( $visibility( $plain_faq, 'false' ), true ),
			'non-boolean viewport hide ignored' => array( $visibility( $plain_faq, array( 'viewport' => array( 'mobile' => 0, 'tablet' => 'false', 'desktop' => null ) ) ), true ),
		);
		foreach ( $visibility_cases as $label => $case ) {
			schema_test_reset();
			add_filter( 'st_wp_core_schema_synced_viewport_root', static fn( bool $certified, array $root ): bool => in_array( $root['blockName'], array( 'test/faq', 'test/wrapper' ), true ), 10, 2 );
			$visibility_visits = 0;
			if ( isset( $case[2] ) ) {
				$schema_test_block_types[ $case[2] ] = (object) array( 'supports' => array( 'visibility' => false ) );
			}
			$schema_test_posts[10] = schema_test_post( array( $case[0] ) );
			$schema_test_posts[20] = schema_test_post( array( $case[3] ?? $plain_faq ), 'wp_block' );
			st_wp_core_schema_register_block_provider( 'test/faq', static function( array $block, int $post_id, string $key ) use ( &$visibility_visits ): void {
				++$visibility_visits;
				st_wp_core_schema_add_faq( array( array( 'question' => 'Visible', 'answer' => 'Answer' ) ), $key );
			} );
			$graph = schema_test_head()['graph']['@graph'] ?? array();
			schema_test_assert( $case[1] === ( array() !== $graph ), $label . ': schema matches effective visibility.' );
			schema_test_assert( (int) $case[1] === $visibility_visits, $label . ': hidden branches never invoke providers.' );
			if ( 'hidden synced reference' === $label ) {
				schema_test_assert( 1 === $schema_test_parse_calls, 'Hidden reference is pruned before parsing its content.' );
			}
		}
		foreach ( array( '@mobile', '@tablet' ) as $only_small_viewport ) {
			schema_test_reset();
			$schema_test_context['viewport_queries'] = array( $only_small_viewport => '@media (width <= 600px)', '@desktop' => '@media (width > 600px)' );
			$hidden = array( 'viewport' => array( ltrim( $only_small_viewport, '@' ) => false, 'desktop' => false ) );
			$schema_test_posts[10] = schema_test_post( array( $visibility( $plain_faq, $hidden ) ) );
			st_wp_core_schema_register_block_provider( 'test/faq', static function(): void { throw new RuntimeException( 'All known viewports are hidden.' ); } );
			schema_test_assert( '' === schema_test_head()['html'] && ! $schema_test_diagnostics, 'Only queries emitted by WordPress count towards the visibility intersection.' );
			schema_test_assert( null === st_wp_core_schema_visible_viewports( $visibility( $plain_faq, $viewport( array( '@mobile' === $only_small_viewport ? 'tablet' : 'mobile' ) ) ), null ), 'Absent viewport metadata is ignored.' );
		}
		schema_test_reset();
		$schema_test_posts[10] = schema_test_post( array( $visibility( $plain_faq, $all_viewports ), $plain_faq ) );
		st_wp_core_schema_register_block_provider( 'test/faq', static function( array $block, int $post_id, string $key ): void {
			st_wp_core_schema_add_faq( array( array( 'question' => 'Visible sibling', 'answer' => 'Answer' ) ), $key );
		} );
		schema_test_assert( array( 'Visible sibling' ) === array_column( schema_test_head()['graph']['@graph'][0]['mainEntity'], 'name' ), 'Sibling visibility does not leak across branches.' );

		$inside_faq = $faq( 'Inside pattern' );
		$outside_faq = $faq( 'Outside pattern' );
		$single_root = schema_test_block( 'test/wrapper', array( $inside_faq ) );
		$whitespace = array( 'blockName' => null, 'innerHTML' => "\n \t", 'innerBlocks' => array() );
		$multi_root = array( schema_test_block( 'core/paragraph' ), $inside_faq );
		$reference_viewports = $viewport( array( 'mobile' ) );
		$synced_cases = array(
			'certified wrapper' => array( array( $single_root ), $reference_viewports, true, true, false ),
			'whitespace around wrapper' => array( array( $whitespace, $single_root, $whitespace ), $reference_viewports, true, true, false ),
			'certified FAQ root' => array( array( $inside_faq ), $reference_viewports, true, true, false ),
			'uncertified wrapper' => array( array( $single_root ), $reference_viewports, false, false, true ),
			'uncertified FAQ root' => array( array( $inside_faq ), $reference_viewports, false, false, true ),
			'paragraph then FAQ' => array( $multi_root, $reference_viewports, true, false, true ),
			'two FAQ roots' => array( array( $inside_faq, $inside_faq ), $reference_viewports, true, false, true ),
			'unknown dynamic root' => array( array( schema_test_block( 'test/dynamic', array( $inside_faq ) ) ), $reference_viewports, true, false, true ),
			'all viewports on multi-root' => array( $multi_root, $all_viewports, true, false, true ),
			'boolean hides multi-root' => array( $multi_root, false, false, false, false ),
			'viewport support disabled' => array( $multi_root, $all_viewports, false, true, false, false ),
			'boolean hides despite disabled support' => array( $multi_root, false, false, false, false, false ),
			'unknown viewport has no restriction' => array( $multi_root, $viewport( array( 'watch' ) ), false, true, false ),
			'unconditioned multi-root' => array( $multi_root, null, false, true, false ),
		);
		foreach ( $synced_cases as $label => $case ) {
			schema_test_reset();
			$schema_test_block_types['test/dynamic'] = (object) array( 'supports' => array() );
			if ( isset( $case[5] ) ) { $schema_test_block_types['core/block'] = (object) array( 'supports' => array( 'visibility' => $case[5] ) ); }
			if ( $case[2] ) {
				add_filter( 'st_wp_core_schema_synced_viewport_root', static function( bool $certified, array $root, array $reference, int $post_id ): bool {
					schema_test_assert( false === $certified && 20 === $post_id && 'core/block' === $reference['blockName'], 'Certification receives only the sole root and its owning reference.' );
					return in_array( $root['blockName'], array( 'test/faq', 'test/wrapper' ), true );
				}, 10, 4 );
			}
			$schema_test_posts[20] = schema_test_post( $case[0], 'wp_block' );
			$schema_test_posts[10] = schema_test_post( array( $visibility( $ref( 20 ), $case[1] ), $outside_faq ) );
			$provider_calls = 0;
			st_wp_core_schema_register_block_provider( 'test/faq', static function( array $block, int $post_id, string $key ) use ( &$provider_calls ): void {
				++$provider_calls;
				st_wp_core_schema_add_faq( array( array( 'question' => $block['attrs']['question'], 'answer' => 'Answer' ) ), $key );
			} );
			$names = array_column( schema_test_head()['graph']['@graph'][0]['mainEntity'], 'name' );
			schema_test_assert( ( $case[3] ? array( 'Inside pattern', 'Outside pattern' ) : array( 'Outside pattern' ) ) === $names, $label . ': reject only the affected occurrence, preserving unrelated schema.' );
			schema_test_assert( ( $case[3] ? 2 : 1 ) === $provider_calls, $label . ': certification happens before provider side effects.' );
			schema_test_assert( (int) $case[4] === count( $schema_test_diagnostics ), $label . ': unsupported reference emits one development diagnostic.' );
			if ( 'boolean hides multi-root' === $label || 'boolean hides despite disabled support' === $label ) {
				schema_test_assert( 1 === $schema_test_parse_calls, $label . ': boolean hiding does not parse the reference.' );
			}
		}

		foreach ( array( 1 => 'First page', 2 => 'Second page', 999 => 'Second page', 0 => 'First page' ) as $page_number => $expected_question ) {
			schema_test_reset();
			$pagination_visits = array();
			st_wp_core_schema_register_block_provider( 'test/faq', static function( array $block, int $post_id, string $source_key ) use ( &$pagination_visits ): void {
				$pagination_visits[] = $source_key;
				st_wp_core_schema_add_faq( array( array( 'question' => $block['attrs']['question'], 'answer' => 'Visible answer' ) ), $source_key );
			} );
			$schema_test_context['postdata'] = array(
				'page' => $page_number,
				'pages' => array( json_encode( array( $faq( 'First page' ) ), JSON_THROW_ON_ERROR ), json_encode( array( $faq( 'Second page' ) ), JSON_THROW_ON_ERROR ) ),
			);
			// A conflicting raw URL must never select a page or become an identifier.
			$_SERVER['REQUEST_URI'] = '/not-canonical/42/?page=42&showall=1';
			$result = schema_test_head()['graph']['@graph'][0];
			schema_test_assert( array( $expected_question ) === array_column( $result['mainEntity'], 'name' ), 'Only selected content page collected: ' . $page_number );
			schema_test_assert( 'https://example.test/canonical/#faq' === $result['@id'], 'Paginated FAQ keeps queried canonical permalink.' );
			$selected = 'First page' === $expected_question ? 1 : 2;
			schema_test_assert( array( 'post:10/page:' . $selected . '/0' ) === $pagination_visits, 'Pagination is included in deterministic source key.' );
			schema_test_assert( 1 === $schema_test_parse_calls, 'Non-visible content pages were not parsed.' );
		}
		foreach ( array( array(), array( 'page' => 1, 'pages' => 'invalid' ), array( 'page' => 1, 'pages' => array( 'named' => 'invalid' ) ), array( 'page' => 1, 'pages' => array( false ) ) ) as $postdata ) {
			schema_test_reset();
			st_wp_core_schema_register_block_provider( 'test/faq', static function(): void { throw new RuntimeException( 'Invalid pagination must not invoke providers.' ); } );
			$schema_test_context['postdata'] = $postdata;
			schema_test_assert( '' === schema_test_head()['html'] && 0 === $schema_test_parse_calls, 'Malformed pagination data skipped without parsing/rendering.' );
		}

		schema_test_reset();
		st_wp_core_schema_register_block_provider( 'test/failing', static function( array $block, int $post_id, string $key ): void {
			st_wp_core_schema_add( array( '@type' => 'Thing' ), 'partial' );
			st_wp_core_schema_add_faq( array( array( 'question' => 'Partial', 'answer' => 'Partial' ) ), $key );
			throw new RuntimeException( 'Do not leak this private failure.' );
		} );
		$schema_test_posts[10] = schema_test_post( array( schema_test_block( 'test/failing' ) ) );
		schema_test_assert( '' === schema_test_head()['html'], 'Failed provider contributions rolled back.' );
		schema_test_assert( 1 === count( $schema_test_diagnostics ), 'Failed provider emits a development diagnostic.' );

		// Exercise the real loader, including failed registration and symlink escapes.
		schema_test_reset();
		foreach ( array( 'valid', 'failed', 'unsafe-bootstrap', 'unsafe-metadata' ) as $directory ) {
			mkdir( $schema_test_root . '/blocks/' . $directory, 0777, true );
			file_put_contents( $schema_test_root . '/blocks/' . $directory . '/block.json', '{}' );
		}
		$bootstrap = '<?php $GLOBALS["schema_test_bootstraps"][] = $block_type->name;';
		file_put_contents( $schema_test_root . '/blocks/valid/block-bootstrap.php', $bootstrap );
		file_put_contents( $schema_test_root . '/blocks/failed/block-bootstrap.php', $bootstrap );
		mkdir( $schema_test_root . '/outside' );
		file_put_contents( $schema_test_root . '/outside/block.json', '{}' );
		file_put_contents( $schema_test_root . '/outside/block-bootstrap.php', $bootstrap );
		symlink( $schema_test_root . '/outside', $schema_test_root . '/blocks/escaped' );
		symlink( $schema_test_root . '/outside/block-bootstrap.php', $schema_test_root . '/blocks/unsafe-bootstrap/block-bootstrap.php' );
		unlink( $schema_test_root . '/blocks/unsafe-metadata/block.json' );
		symlink( $schema_test_root . '/outside/block.json', $schema_test_root . '/blocks/unsafe-metadata/block.json' );
		$GLOBALS['schema_test_bootstraps'] = array();
		require dirname( __DIR__ ) . '/resources/core/files/core/core.php';
		st_wp_core_register_blocks();
		st_wp_core_register_blocks();
		schema_test_assert( array( 'test/valid' ) === $GLOBALS['schema_test_bootstraps'], 'Bootstrap loads only once after successful, trusted registration.' );
		schema_test_assert( ! in_array( 'escaped', $schema_test_registered, true ), 'Escaped directory is never registered.' );
		schema_test_assert( ! in_array( 'unsafe-metadata', $schema_test_registered, true ), 'Escaped metadata is never registered.' );
		schema_test_assert( in_array( 'unsafe-bootstrap', $schema_test_registered, true ), 'Block can register without loading an unsafe bootstrap.' );

		foreach ( array( 'rest', 'override' ) as $mode ) {
			$process = proc_open( array( PHP_BINARY, __FILE__, $mode ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to start isolated schema test.' ); }
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			schema_test_assert( 0 === proc_close( $process ), $mode . ' child failed: ' . $output );
		}
		echo 'Schema tests passed (' . $schema_test_assertions . " assertions).\n";
	}
} finally {
	schema_test_remove( $schema_test_root );
}
