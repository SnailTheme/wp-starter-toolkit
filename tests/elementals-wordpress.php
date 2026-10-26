<?php
/**
 * Seed and validate the elemental kitchen sink in a local WordPress install.
 *
 * Run with: wp eval 'require "/path/to/toolkit/tests/elementals-wordpress.php";'
 * Only the fixture page owned by this test is updated; other pages and media
 * are never changed. Uses the installed namespace and local ACF definitions.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Run this integration check through wp eval with require.' );
}

$namespace = (string) ST_WP_CORE_THEME_PATTERNS['block_namespace'];
$members   = array( 'wrapper', 'container', 'heading', 'text', 'columns', 'column', 'grid', 'grid-cell', 'content-with-image' );
$checks    = 0;
$assert    = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

foreach ( $members as $member ) {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $namespace . '/el-' . $member );
	$assert( null !== $type, 'Element not registered: ' . $member );
	$assert( 3 === $type->api_version, 'Element does not use Block API v3: ' . $member );
}
$assert( WP_Block_Type_Registry::get_instance()->is_registered( 'core/group' ), 'Core layout blocks were globally removed.' );

// Emit the same name/reference pairs ACF stores when a block is saved. Walking
// the installed field definitions works with both source and hashed field keys.
$acf_data = static function ( string $name, array $values ): array {
	$data = array();
	$walk = static function ( array $fields, string $prefix = '' ) use ( &$walk, &$data, $values ): void {
		foreach ( $fields as $field ) {
			if ( 'tab' === $field['type'] ) {
				continue;
			}
			$path = $prefix . $field['name'];
			if ( 'group' === $field['type'] ) {
				$data[ $path ]       = '';
				$data[ '_' . $path ] = $field['key'];
				$walk( $field['sub_fields'] ?? acf_get_fields( $field ) ?: array(), $path . '_' );
				continue;
			}
			foreach ( $values as $suffix => $value ) {
				if ( str_ends_with( $field['name'], '__' . $suffix ) ) {
					$data[ $path ]       = $value;
					$data[ '_' . $path ] = $field['key'];
				}
			}
		}
	};
	foreach ( acf_get_field_groups( array( 'block' => $name ) ) as $group ) {
		$walk( acf_get_fields( $group ) ?: array() );
	}
	return $data;
};

$element = static function ( string $slug, array $children = array(), array $values = array(), array $attributes = array() ) use ( $namespace, $acf_data ): array {
	$name = $namespace . '/el-' . $slug;
	return array(
		'blockName'    => $name,
		'attrs'        => array_merge( array( 'name' => $name, 'data' => $acf_data( $name, $values ) ), $attributes ),
		'innerBlocks'  => $children,
		'innerHTML'    => '',
		'innerContent' => array_fill( 0, count( $children ), null ),
	);
};
$native = static function ( string $slug, string $html, array $attrs = array() ): array {
	return array( 'blockName' => 'core/' . $slug, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) );
};
$heading = static function ( string $text, int $level = 2 ) use ( $native ): array {
	return $native( 'heading', '<h' . $level . ' class="wp-block-heading">' . esc_html( $text ) . '</h' . $level . '>', array( 'level' => $level ) );
};
$paragraph = static fn ( string $text ): array => $native( 'paragraph', '<p>' . esc_html( $text ) . '</p>' );
$title     = static fn ( string $text ): array => $element( 'heading', array( $heading( $text ) ) );
$copy      = static fn ( string $text ): array => $element( 'text', array( $paragraph( $text ) ) );
$buttons   = array(
	'blockName'    => 'core/buttons',
	'attrs'        => array(),
	'innerBlocks'  => array( $native( 'button', '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#primary">Back to content</a></div>' ) ),
	'innerHTML'    => '<div class="wp-block-buttons"></div>',
	'innerContent' => array( '<div class="wp-block-buttons">', null, '</div>' ),
);
$images    = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => array( 'image/webp', 'image/jpeg', 'image/png', 'image/avif', 'image/gif' ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
if ( ! $images ) {
	$images = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => 1, 'fields' => 'ids' ) );
}
$image     = (int) ( $images[0] ?? 0 );
$assert( $image > 0 && wp_attachment_is_image( $image ), 'Add a local image attachment before running the elemental image acceptance check.' );
$image_copy = array( $heading( 'Content and image, composed together' ), $paragraph( 'Native WordPress copy stays editable. The image is an ACF field owned by the element; layout is independent of the project UI.' ), $buttons );
$sections  = array(
	$element( 'wrapper', array(
		$element( 'container', array(
			$element( 'heading', array( $heading( 'Elemental Kitchen Sink' ), $paragraph( 'A practical canvas for sections, layouts, native content, and responsive media.' ) ), array( 'eyebrow' => 'Elemental foundation' ) ),
			$element( 'content-with-image', $image_copy, array( 'image' => $image, 'image_position' => 'right', 'gap' => 'medium', 'alignment' => 'center' ) ),
		) ),
	), array(), array( 'align' => 'full', 'style' => array( 'color' => array( 'background' => '#edf4f4' ), 'spacing' => array( 'padding' => array( 'top' => '2rem', 'right' => '1rem', 'bottom' => '2rem', 'left' => '1rem' ), 'margin' => array( 'top' => '0', 'bottom' => '1rem' ) ) ) ) ),
	$element( 'wrapper', array(
		$element( 'container', array(
			$title( 'Weighted columns and responsive grids' ),
			$element( 'columns', array(
				$element( 'column', array( $copy( 'One part' ) ), array( 'weight' => 1 ) ),
				$element( 'column', array( $copy( 'Two parts: a longer column demonstrates proportional width, wrapping, and ordinary native paragraph content.' ) ), array( 'weight' => 2 ) ),
				$element( 'column', array( $copy( 'One part' ) ), array( 'weight' => 1 ) ),
			), array( 'gap' => 'medium', 'alignment' => 'stretch', 'collapse' => '48' ) ),
			$element( 'grid', array(
				$element( 'grid-cell', array( $copy( 'Single cell' ) ), array( 'span' => 1 ) ),
				$element( 'grid-cell', array( $copy( 'A two-track cell' ) ), array( 'span' => 2 ) ),
				$element( 'grid-cell', array( $copy( 'A full row: spans clamp to the available tablet and mobile tracks.' ) ), array( 'span' => 4 ) ),
			), array( 'desktop' => 4, 'tablet' => 2, 'mobile' => 1, 'gap' => 'medium' ) ),
		) ),
	), array(), array( 'align' => 'full', 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '2rem', 'right' => '1rem', 'bottom' => '2rem', 'left' => '1rem' ) ) ) ) ),
	$element( 'wrapper', array(
		$element( 'container', array(
			$title( 'Image on the left' ),
			$element( 'content-with-image', $image_copy, array( 'image' => $image, 'image_position' => 'left', 'gap' => 'large' ) ),
			$title( 'Missing media keeps the content usable' ),
			$element( 'content-with-image', array( $paragraph( 'There is deliberately no image selected for this element.' ) ), array( 'image' => 0 ) ),
		), array( 'width' => 'wide' ) ),
		$element( 'container', array(
			$title( 'Custom width, aligned to the end' ),
			$copy( str_repeat( 'Long content remains readable and wraps inside the owned styling root. ', 8 ) ),
		), array( 'width' => 'custom', 'custom_width' => '40rem', 'position' => 'end' ) ),
		$element( 'container', array(
			$title( 'Full width, aligned to the start' ),
			$copy( 'This container adds no automatic padding or background.' ),
			$element( 'text' ),
		), array( 'width' => 'full', 'position' => 'start' ) ),
	), array(), array( 'align' => 'full', 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '2rem', 'right' => '1rem', 'bottom' => '2rem', 'left' => '1rem' ) ) ) ) ),
);

$content  = serialize_blocks( $sections );
$existing = get_page_by_path( 'elemental-kitchen-sink' );
if ( $existing && ! get_post_meta( $existing->ID, '_st_toolkit_elemental_fixture', true ) ) {
	throw new RuntimeException( 'An unrelated page already uses the elemental-kitchen-sink slug.' );
}
$post_id = wp_insert_post( array(
	'ID'           => $existing ? $existing->ID : 0,
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Elemental Kitchen Sink',
	'post_name'    => 'elemental-kitchen-sink',
	'post_author'  => get_current_user_id() ?: (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ),
	'post_content' => wp_slash( $content ),
), true );
if ( is_wp_error( $post_id ) ) {
	throw new RuntimeException( $post_id->get_error_message() );
}
update_post_meta( $post_id, '_st_toolkit_elemental_fixture', 1 );
$saved = get_post( $post_id );
$assert( $saved->post_content === $content, 'Save/reload changed the serialized elemental content.' );
$parsed = parse_blocks( $saved->post_content );
$assert( 3 === count( array_filter( $parsed, static fn ( array $block ): bool => null !== $block['blockName'] ) ), 'Unexpected number of root Sections.' );
$html = do_blocks( $saved->post_content );
$assert( ! str_contains( $html, '<InnerBlocks' ), 'An InnerBlocks placeholder leaked into rendered HTML.' );
$assert( str_contains( $html, 'background-color:#edf4f4' ), 'Native wrapper background was not rendered.' );
$assert( str_contains( $html, 'padding-top:2rem' ), 'Native wrapper spacing was not rendered.' );
$assert( str_contains( $html, 'One part' ) && str_contains( $html, 'full row' ), 'Nested columns/grid content was lost.' );
$assert( str_contains( $html, 'There is deliberately no image selected' ), 'Missing media removed its native content.' );
$assert( str_contains( $html, 'Elemental foundation' ), 'ACF heading content was not resolved.' );
$assert( str_contains( $html, '--' . $namespace . '-el-container-custom-width:40rem' ), 'Custom container width was not rendered.' );
$assert( str_contains( $html, $namespace . '-el-container--width-full' ), 'Full-width container was not rendered.' );
$assert( str_contains( $html, $namespace . '-el-container--position-end' ), 'Container positioning was not rendered.' );
$assert( str_contains( $html, 'wp-image-' . $image ) || str_contains( $html, wp_get_attachment_url( $image ) ), 'ACF image was not rendered.' );
WP_CLI::log( wp_json_encode( array( 'assertions' => $checks, 'page_id' => $post_id, 'url' => get_permalink( $post_id ), 'namespace' => $namespace, 'wordpress' => get_bloginfo( 'version' ), 'acf' => ACF_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
