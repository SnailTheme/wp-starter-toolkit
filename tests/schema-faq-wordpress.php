<?php
/**
 * Seed an owned FAQ acceptance page and check raw ACF/rendering invariants.
 * Run through wp eval with require; no unrelated content is modified.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Run through wp eval with require.' );
}
$namespace = ST_WP_CORE_THEME_PATTERNS['block_namespace'];
$name = $namespace . '/el-faq';
$root = $namespace . '-el-faq';
$directory = get_stylesheet_directory() . '/blocks/' . $root;
$checks = 0;
$assert = static function ( bool $ok, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$assert( WP_Block_Type_Registry::get_instance()->is_registered( $name ), 'FAQ not registered.' );
$groups = acf_get_field_groups( array( 'block' => $name ) );
$assert( 1 === count( $groups ), 'FAQ must have one self-contained ACF group.' );
$definitions = acf_get_fields( $groups[0] );
$question_name = $root . '-fields-item__question';
$answer_name = $root . '-fields-item__answer';
$rows = array(
	array( $question_name => 'How does this FAQ work?', $answer_name => '<p>Native disclosures work without JavaScript.</p><p><strong>Only one answer</strong> opens per block.</p>' ),
	array( $question_name => 'Can I use rich text?', $answer_name => '<p>Yes: <em>emphasis</em> and <a href="https://example.com/">links</a>.</p><ul><li>First item</li><li>Second item</li></ul>' ),
	array( $question_name => str_repeat( 'Long question that must wrap naturally. ', 8 ), $answer_name => '<p>' . str_repeat( 'Long answers remain readable at every viewport. ', 25 ) . '</p>' ),
	array( $question_name => 'Incomplete item', $answer_name => '' ),
);
$build_data = static function ( array $rows, bool $schema = true, bool $first = true ) use ( $definitions, $root ): array {
	$values = array(
		$root . '-fields' => array( $root . '-fields-items' => $rows ),
		$root . '-settings' => array( $root . '-settings__schema_enabled' => (int) $schema, $root . '-settings__first_open' => (int) $first ),
	);
	$data = array();
	$walk = static function ( array $fields, array $values, string $prefix = '' ) use ( &$walk, &$data ): void {
		foreach ( $fields as $field ) {
			if ( 'tab' === $field['type'] ) { continue; }
			$path = $prefix . $field['name'];
			$value = $values[ $field['name'] ] ?? '';
			$data[ '_' . $path ] = $field['key'];
			$children = $field['sub_fields'] ?? acf_get_fields( $field ) ?: array();
			if ( 'group' === $field['type'] ) {
				$data[ $path ] = '';
				$walk( $children, $value, $path . '_' );
			} elseif ( 'repeater' === $field['type'] ) {
				$data[ $path ] = count( $value );
				foreach ( $value as $index => $row ) { $walk( $children, $row, $path . '_' . $index . '_' ); }
			} else { $data[ $path ] = $value; }
		}
	};
	$walk( $definitions, $values );
	return $data;
};
$block = static fn ( array $data ): array => array(
	'blockName' => $name, 'attrs' => array( 'name' => $name, 'data' => $data ),
	'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
);
$data = $build_data( $rows );
$normalize = require $directory . '/block-data.php';
$ambient_id = acf_get_instance( 'ACF_Local_Meta' )->post_id;
$before_post = $GLOBALS['post'] ?? null;
$normalized = $normalize( array( 'name' => $name, 'data' => $data ) );
$assert( 3 === count( $normalized['items'] ) && 1 === $normalized['incomplete'], 'Incomplete rows leaked or complete rows were lost.' );
$assert( $normalized['first_open'] && $normalized['schema'], 'Default settings changed.' );
$assert( str_contains( $normalized['items'][0]['text'], 'JavaScript. Only one answer' ), 'Paragraph boundaries were lost in schema text.' );
$assert( $ambient_id === acf_get_instance( 'ACF_Local_Meta' )->post_id && $before_post === ( $GLOBALS['post'] ?? null ), 'Normalization changed ambient ACF/WordPress context.' );
$html = do_blocks( serialize_blocks( array( $block( $data ), $block( $data ) ) ) );
$assert( 6 === substr_count( $html, '<details ' ), 'Wrong rendered FAQ count.' );
preg_match_all( '/<details[^>]*name="([^"]+)"/', $html, $matches );
$assert( 2 === count( array_unique( $matches[1] ) ), 'Identical duplicate blocks share a disclosure group.' );
$assert( 2 === preg_match_all( '/<details[^>]*\sopen="open"/', $html ), 'First item should be open independently in each instance.' );
$assert( ! str_contains( $html, 'Incomplete item' ) && ! str_contains( $html, '<script' ), 'Incomplete rows/schema leaked into body rendering.' );

$shortcode_calls = 0;
add_shortcode( 'st_faq_probe', static function () use ( &$shortcode_calls ): string { ++$shortcode_calls; return 'EXECUTED'; } );
$unsafe = $normalize( array( 'name' => $name, 'data' => $build_data( array( array( $question_name => 'Safety', $answer_name => '<script>alert(1)</script><p>[st_faq_probe] <a href="javascript:alert(1)">safe link</a></p>' ) ) ) ) );
remove_shortcode( 'st_faq_probe' );
$assert( 0 === $shortcode_calls && ! str_contains( $unsafe['items'][0]['answer'], '<script' ) && ! str_contains( $unsafe['items'][0]['answer'], 'javascript:' ), 'Unsafe markup or shortcode execution.' );
$off = $normalize( array( 'name' => $name, 'data' => $build_data( $rows, false, false ) ) );
$assert( ! $off['schema'] && ! $off['first_open'], 'False settings were replaced by defaults.' );

$literal_questions = array(
	'What does <p> mean?',
	'What does &lt;p&gt; mean?',
	'What does &amp;lt;p&amp;gt; mean?',
	'Is &nbsp; literal text?',
	'</summary><script>alert("faq-question")</script><img src=x onerror=alert(1)>',
	'" onclick="alert(1)" & \'literal quotes\'',
	'Whitespace is normalized.',
);
$literal_answer = '<p>A visible <strong>answer</strong> &amp; example.</p>';
$literal_rows = array_map( static fn ( string $question ): array => array( $question_name => $question, $answer_name => $literal_answer ), $literal_questions );
$literal_rows[ count( $literal_rows ) - 1 ][ $question_name ] = " \tWhitespace\xc2\xa0is\nnormalized. ";
$literal_rows[] = array( $question_name => " \t\n\xc2\xa0", $answer_name => $literal_answer );
$literal_data = $build_data( $literal_rows );
$literal_normalized = $normalize( array( 'name' => $name, 'data' => $literal_data ) );
$assert( $literal_questions === array_column( $literal_normalized['items'], 'question' ), 'Plain questions lost literal markup/entities or did not normalize whitespace.' );
$assert( 1 === $literal_normalized['incomplete'], 'A whitespace-only plain question was not excluded.' );

// Parse real rendered markup rather than inferring text from escape helpers.
$read_dom = static function ( string $markup ): DOMDocument {
	$previous = libxml_use_internal_errors( true );
	try {
		$document = new DOMDocument();
		if ( ! $document->loadHTML( '<?xml encoding="UTF-8">' . $markup, LIBXML_NONET ) ) {
			throw new RuntimeException( 'Could not parse rendered FAQ HTML.' );
		}
		return $document;
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
};
$literal_dom = $read_dom( do_blocks( serialize_blocks( array( $block( $literal_data ) ) ) ) );
$literal_summaries = $literal_dom->getElementsByTagName( 'summary' );
$assert( count( $literal_questions ) === $literal_summaries->length, 'Rendered literal questions were dropped or injection created extra rows.' );
foreach ( $literal_summaries as $index => $summary ) {
	$assert( $literal_questions[ $index ] === $summary->textContent, 'Rendered question text differs from its plain input.' );
	$assert( 0 === $summary->getElementsByTagName( '*' )->length, 'Literal question markup became executable/rendered HTML.' );
}
$assert( 0 === $literal_dom->getElementsByTagName( 'script' )->length && 0 === $literal_dom->getElementsByTagName( 'img' )->length, 'Question injection escaped its summary.' );

$native_heading = static fn ( string $title ): array => array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2 class="wp-block-heading">' . esc_html( $title ) . '</h2>', 'innerContent' => array( '<h2 class="wp-block-heading">' . esc_html( $title ) . '</h2>' ) );
$content = serialize_blocks(
	array(
		$native_heading( 'Questions and answers' ),
		$block( $data ),
		$native_heading( 'Independent duplicate' ),
		$block( $data ),
		$native_heading( 'Starts closed; schema disabled' ),
		$block( $build_data( array_slice( $rows, 0, 2 ), false, false ) ),
		$native_heading( 'Literal text and safe escaping' ),
		$block( $literal_data ),
	)
);
$existing = get_page_by_path( 'schema-faq-kitchen-sink' );
if ( $existing && ! get_post_meta( $existing->ID, '_st_toolkit_schema_faq_fixture', true ) ) { throw new RuntimeException( 'Unrelated page uses acceptance slug.' ); }
$post_id = wp_insert_post( array( 'ID' => $existing ? $existing->ID : 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Schema FAQ Kitchen Sink', 'post_name' => 'schema-faq-kitchen-sink', 'post_content' => wp_slash( $content ) ), true );
if ( is_wp_error( $post_id ) ) { throw new RuntimeException( $post_id->get_error_message() ); }
update_post_meta( $post_id, '_st_toolkit_schema_faq_fixture', 1 );
$assert( $content === get_post( $post_id )->post_content, 'Save/reload changed FAQ data.' );

// Fetch a fresh frontend request so the actual early provider and wp_head
// renderer must agree with the DOM, including script-termination payloads.
$response = wp_remote_get( get_permalink( $post_id ), array( 'timeout' => 30 ) );
if ( is_wp_error( $response ) ) { throw new RuntimeException( 'FAQ frontend request failed: ' . $response->get_error_message() ); }
$assert( 200 === wp_remote_retrieve_response_code( $response ), 'FAQ frontend request did not succeed.' );
$frontend_dom = $read_dom( wp_remote_retrieve_body( $response ) );
$xpath = new DOMXPath( $frontend_dom );
$scripts = $xpath->query( '//head/script[@id="st-wp-core-schema" and @type="application/ld+json"]' );
$assert( 1 === $scripts->length, 'Expected exactly one theme schema graph in the actual head.' );
$graph = json_decode( $scripts->item( 0 )->textContent, true, 512, JSON_THROW_ON_ERROR );
$faq_nodes = array_values( array_filter( $graph['@graph'] ?? array(), static fn ( array $node ): bool => 'FAQPage' === ( $node['@type'] ?? '' ) ) );
$assert( 1 === count( $faq_nodes ), 'Expected one aggregated FAQPage.' );
$head_questions = array_column( $faq_nodes[0]['mainEntity'], 'name' );
$frontend_summaries = $frontend_dom->getElementsByTagName( 'summary' );
foreach ( $literal_questions as $question ) {
	$position = array_search( $question, $head_questions, true );
	$assert( false !== $position, 'Actual head changed/omitted a literal question: ' . $question );
	$matching = array();
	foreach ( $frontend_summaries as $summary ) {
		if ( $question === $summary->textContent ) { $matching[] = $summary; }
	}
	$assert( 1 === count( $matching ), 'Actual frontend question text differs from the head/plain input: ' . $question );
	$assert( 0 === $matching[0]->getElementsByTagName( '*' )->length, 'Actual frontend interpreted literal question markup.' );
	$answer_node = $matching[0]->parentNode->getElementsByTagName( 'div' )->item( 0 );
	$answer_text = trim( $answer_node->textContent );
	$assert( 'A visible answer & example.' === $answer_text && $answer_text === $faq_nodes[0]['mainEntity'][ $position ]['acceptedAnswer']['text'], 'Actual visible answer and head schema disagree.' );
}
WP_CLI::log( wp_json_encode( array( 'assertions' => $checks, 'page_id' => $post_id, 'url' => get_permalink( $post_id ), 'namespace' => $namespace, 'wordpress' => get_bloginfo( 'version' ), 'acf' => ACF_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
