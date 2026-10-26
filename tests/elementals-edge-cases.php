<?php
/**
 * Seed owned browser edge cases and check hostile layout settings in WordPress.
 * Run through wp eval with require after elementals-wordpress.php.
 * Define ST_TOOLKIT_ELEMENTAL_EDGE_CHECKS_ONLY as true to skip fixture writes.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Run this test through wp eval with require.' );
}
$source = get_page_by_path( 'elemental-kitchen-sink' );
if ( ! $source || ! get_post_meta( $source->ID, '_st_toolkit_elemental_fixture', true ) ) {
	throw new RuntimeException( 'Seed the owned Elemental Kitchen Sink first.' );
}
$namespace = ST_WP_CORE_THEME_PATTERNS['block_namespace'];
$blocks    = parse_blocks( $source->post_content );
$token     = str_repeat( 'UnbrokenElementalContent', 18 );
$custom    = null;
$updated   = false;
$walk      = static function ( array &$nodes ) use ( &$walk, &$custom, &$updated, $namespace, $token ): void {
	foreach ( $nodes as &$node ) {
		if ( $namespace . '/el-container' === $node['blockName'] ) {
			foreach ( $node['attrs']['data'] ?? array() as $key => $value ) {
				if ( ! str_starts_with( $key, '_' ) && str_ends_with( $key, '__custom_width' ) ) {
					$custom = $node;
				}
			}
		}
		if ( ! $updated && $namespace . '/el-content-with-image' === $node['blockName'] ) {
			foreach ( $node['innerBlocks'] as &$child ) {
				if ( 'core/paragraph' === $child['blockName'] ) {
					$child['innerHTML']    = '<p>' . $token . '</p>';
					$child['innerContent'] = array( $child['innerHTML'] );
					$updated = true;
					break;
				}
			}
			unset( $child );
		}
		$walk( $node['innerBlocks'] );
	}
	unset( $node );
};
$walk( $blocks );
if ( ! $updated || null === $custom ) {
	throw new RuntimeException( 'The Kitchen Sink lacks required image and custom-width fixtures.' );
}
$render_width = static function ( mixed $width ) use ( $custom ): string {
	$copy = $custom;
	foreach ( $copy['attrs']['data'] as $key => &$value ) {
		if ( ! str_starts_with( $key, '_' ) && str_ends_with( $key, '__custom_width' ) ) {
			$value = $width;
		}
	}
	unset( $value );
	return render_block( $copy );
};
$checks = 0;
$has_width = static function ( string $html, string $width ) use ( $namespace ): bool {
	$document = new DOMDocument();
	@$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	$root = $document->getElementsByTagName( 'div' )->item( 0 );
	return $root instanceof DOMElement && 1 === preg_match(
		'/(?:^|;)\\s*--' . preg_quote( $namespace, '/' ) . '-el-container-custom-width:\\s*' . preg_quote( $width, '/' ) . '\\s*(?:;|$)/',
		$root->getAttribute( 'style' )
	);
};
foreach ( array( '10px;background:red', 'url(https://example.com)', 'calc(100% + 1px)', '-12rem', array( '40rem' ), '0rem', '0px', '0%', str_repeat( '9', 500 ) . 'px' ) as $invalid ) {
	$html = $render_width( $invalid );
	if ( ! $has_width( $html, '60rem' ) ) {
		throw new RuntimeException( 'Invalid custom width did not fall back to a safe length: ' . wp_json_encode( $invalid ) );
	}
	++$checks;
}
foreach ( array( '320px', '.5rem', '12em', '72ch', '80%', '90vw', ' 40rem ' ) as $valid ) {
	if ( ! $has_width( $render_width( $valid ), trim( $valid ) ) ) {
		throw new RuntimeException( 'A valid custom width was not preserved: ' . $valid );
	}
	++$checks;
}
// Preserve native horizontal margins without making the section wider than its parent.
$wrapper = $blocks[0] ?? array();
if ( $namespace . '/el-wrapper' !== ( $wrapper['blockName'] ?? '' ) ) {
	throw new RuntimeException( 'The Kitchen Sink lacks a root Wrapper fixture.' );
}
$wrapper['attrs']['style']['spacing']['margin']['left']  = '32px';
$wrapper['attrs']['style']['spacing']['margin']['right'] = '32px';
$wrapper_html = render_block( $wrapper );
foreach ( array( 'margin-left:32px', 'margin-right:32px' ) as $margin ) {
	if ( ! str_contains( $wrapper_html, $margin ) ) {
		throw new RuntimeException( 'Wrapper lost native horizontal spacing: ' . $margin );
	}
	++$checks;
}
foreach ( array( 'view-style', 'editor-style' ) as $style ) {
	$css = file_get_contents( get_stylesheet_directory() . '/blocks/' . $namespace . '-el-wrapper/assets/css/' . $style . '.min.css' );
	$selector = preg_quote( '.' . $namespace . '-el-wrapper', '/' );
	if ( false === $css || ! preg_match( '/' . $selector . '\\{[^}]*\\bwidth:auto(?:;|\\})/', $css ) ) {
		throw new RuntimeException( 'Wrapper must size around native horizontal margins in ' . $style . '.' );
	}
	++$checks;
}
$blocks[0] = $wrapper;
if ( defined( 'ST_TOOLKIT_ELEMENTAL_EDGE_CHECKS_ONLY' ) && true === ST_TOOLKIT_ELEMENTAL_EDGE_CHECKS_ONLY ) {
	WP_CLI::log( wp_json_encode( array( 'assertions' => $checks, 'checks_only' => true ) ) );
	return;
}
$existing = get_page_by_path( 'elemental-edge-cases' );
if ( $existing && ! get_post_meta( $existing->ID, '_st_toolkit_elemental_edge_fixture', true ) ) {
	throw new RuntimeException( 'Refusing to overwrite an unrelated edge-case page.' );
}
$post_id = wp_insert_post( array(
	'ID'           => $existing ? $existing->ID : 0,
	'post_type'    => 'page',
	'post_status'  => 'draft',
	'post_author'  => get_current_user_id(),
	'post_title'   => 'Elemental Edge Cases',
	'post_name'    => 'elemental-edge-cases',
	'post_content' => wp_slash( serialize_blocks( $blocks ) ),
), true );
if ( is_wp_error( $post_id ) ) {
	throw new RuntimeException( $post_id->get_error_message() );
}
update_post_meta( $post_id, '_st_toolkit_elemental_edge_fixture', 1 );
WP_CLI::log( wp_json_encode( array( 'assertions' => $checks, 'page_id' => $post_id, 'url' => get_preview_post_link( $post_id ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
