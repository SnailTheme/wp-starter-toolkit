<?php
/**
 * Seed owned browser edge cases and check hostile layout settings in WordPress.
 * Run through wp eval with require after elementals-wordpress.php.
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
foreach ( array( '10px;background:red', 'url(https://example.com)', 'calc(100% + 1px)', '-12rem', array( '40rem' ), '0rem', '0px', '0%', str_repeat( '9', 500 ) . 'px' ) as $invalid ) {
	$html = $render_width( $invalid );
	if ( ! str_contains( $html, '--' . $namespace . '-el-container-custom-width:60rem;' ) ) {
		throw new RuntimeException( 'Invalid custom width did not fall back to a safe length: ' . wp_json_encode( $invalid ) );
	}
	++$checks;
}
foreach ( array( '320px', '.5rem', '12em', '72ch', '80%', '90vw', ' 40rem ' ) as $valid ) {
	if ( ! str_contains( $render_width( $valid ), '--' . $namespace . '-el-container-custom-width:' . trim( $valid ) . ';' ) ) {
		throw new RuntimeException( 'A valid custom width was not preserved: ' . $valid );
	}
	++$checks;
}
$existing = get_page_by_path( 'elemental-edge-cases' );
if ( $existing && ! get_post_meta( $existing->ID, '_st_toolkit_elemental_edge_fixture', true ) ) {
	throw new RuntimeException( 'Refusing to overwrite an unrelated edge-case page.' );
}
$post_id = wp_insert_post( array(
	'ID'           => $existing ? $existing->ID : 0,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_author'  => get_current_user_id(),
	'post_title'   => 'Elemental Edge Cases',
	'post_name'    => 'elemental-edge-cases',
	'post_content' => wp_slash( serialize_blocks( $blocks ) ),
), true );
if ( is_wp_error( $post_id ) ) {
	throw new RuntimeException( $post_id->get_error_message() );
}
update_post_meta( $post_id, '_st_toolkit_elemental_edge_fixture', 1 );
WP_CLI::log( wp_json_encode( array( 'assertions' => $checks, 'page_id' => $post_id, 'url' => get_permalink( $post_id ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
