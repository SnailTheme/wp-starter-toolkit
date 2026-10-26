<?php
/**
 * Read-only registry, empty-render, and layout-settings checks on WP 7.
 * Hostile values exercise matching layout fields, not FAQ repeater content.
 * FAQ answer safety and schema parity belong to schema-faq-wordpress.php
 * and schema-wordpress.php.
 * Run with wp eval 'require "/absolute/path/to/tests/wp7-elementals.php";'.
 * Does not write posts, change themes, or modify the registered definitions.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'Run through wp eval with require.' );
}

( static function (): void {
	$checks    = 0;
	$namespace = ST_WP_CORE_THEME_PATTERNS['block_namespace'];
	$members   = array( 'wrapper', 'container', 'heading', 'text', 'columns', 'column', 'grid', 'grid-cell', 'content-with-image', 'faq' );
	$assert    = static function ( bool $condition, string $message ) use ( &$checks ): void {
		++$checks;
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};
	$assert( version_compare( get_bloginfo( 'version' ), '7.0', '>=' ), 'This suite requires actual WordPress 7.' );
	$registry = WP_Block_Type_Registry::get_instance();
	foreach ( $members as $member ) {
		$name = $namespace . '/el-' . $member;
		$type = $registry->get_registered( $name );
		$path = get_stylesheet_directory() . '/blocks/' . $namespace . '-el-' . $member;
		$meta = json_decode( (string) file_get_contents( $path . '/block.json' ), true, 512, JSON_THROW_ON_ERROR );
		$acf  = acf_get_block_type( $name );
		$assert( $type instanceof WP_Block_Type, $member . ': missing WP registration.' );
		$assert( 3 === $type->api_version && 3 === $acf['acf_block_version'], $member . ': not API v3 / ACF v3.' );
		$assert( ( $meta['allowedBlocks'] ?? null ) === $type->allowed_blocks, $member . ': allowed children differ from metadata.' );
		$assert( ( $meta['parent'] ?? null ) === $type->parent, $member . ': parent restriction differs from metadata.' );
		$expected_parent = match ( $member ) {
			'column' => array( $namespace . '/el-columns' ),
			'grid-cell' => array( $namespace . '/el-grid' ),
			default => null,
		};
		$assert( $expected_parent === $type->parent, $member . ': unexpected parent-only policy.' );
		foreach ( $type->allowed_blocks ?? array() as $child ) {
			$assert( $registry->is_registered( $child ), $member . ': unregistered allowed child ' . $child );
		}
		if ( in_array( $member, array( 'wrapper', 'container', 'column', 'grid-cell' ), true ) ) {
			$assert( in_array( $namespace . '/el-faq', $type->allowed_blocks, true ), $member . ': cannot compose FAQ.' );
		}
		foreach ( array_merge( $type->view_style_handles, $type->editor_style_handles ) as $handle ) {
			$assert( wp_style_is( $handle, 'registered' ), $member . ': missing registered style ' . $handle );
		}
		foreach ( array( 'view-style', 'editor-style' ) as $style ) {
			$assert( is_file( $path . '/assets/css/' . $style . '.min.css' ), $member . ': missing compiled ' . $style );
		}
	}
	foreach ( array( 'core/group', 'core/columns', 'core/column' ) as $native ) {
		$assert( $registry->is_registered( $native ), 'Native layout removed: ' . $native );
	}
	$assert( array( $namespace . '/el-column' ) === $registry->get_registered( $namespace . '/el-columns' )->allowed_blocks, 'Columns accepts unrelated children.' );
	$assert( array( $namespace . '/el-grid-cell' ) === $registry->get_registered( $namespace . '/el-grid' )->allowed_blocks, 'Grid accepts unrelated children.' );

	$data_for = static function ( string $name, array $values ): array {
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
	$render = static function ( string $member, array $values = array() ) use ( $namespace, $data_for ): string {
		$name = $namespace . '/el-' . $member;
		return render_block( array(
			'blockName' => $name, 'attrs' => array( 'name' => $name, 'data' => $data_for( $name, $values ) ),
			'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
		) );
	};
	$root_attribute = static function ( string $html, string $attribute ): string {
		$document = new DOMDocument();
		@$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		$root = $document->getElementsByTagName( 'body' )->item( 0 )?->firstElementChild;
		if ( ! $root instanceof DOMElement ) {
			throw new RuntimeException( 'Rendered block has no root element.' );
		}
		return $root->getAttribute( $attribute );
	};
	$has_class = static function ( string $html, string $class ) use ( $root_attribute ): bool {
		return in_array( $class, preg_split( '/\s+/', trim( $root_attribute( $html, 'class' ) ) ), true );
	};
	$has_declaration = static function ( string $html, string $property, string $value ) use ( $root_attribute ): bool {
		return 1 === preg_match(
			'/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '\s*(?:;|$)/',
			$root_attribute( $html, 'style' )
		);
	};
	$assert( ! $has_class( '<div class="el-column--weight-120"></div>', 'el-column--weight-12' ), 'Class assertion accepted a numeric prefix.' );
	$assert( ! $has_declaration( '<div style="--tracks:60"></div>', '--tracks', '6' ), 'Declaration assertion accepted a numeric prefix.' );
	$assert( $has_declaration( '<div style="--tracks:6"></div>', '--tracks', '6' ), 'Declaration assertion rejected the last declaration without a semicolon.' );
	$assert( $has_declaration( '<div style="color:red; --tracks: 6; color:blue"></div>', '--tracks', '6' ), 'Declaration assertion rejected a delimited declaration.' );
	$hostile = array( 'gap', 'alignment', 'collapse', 'width', 'custom_width', 'position', 'weight', 'span', 'desktop', 'tablet', 'mobile', 'image_position', 'image' );
	foreach ( $members as $member ) {
		foreach ( array( array(), array_fill_keys( $hostile, '" onmouseover="alert(1)' ), array_fill_keys( $hostile, array( 'invalid' ) ) ) as $values ) {
			$html = $render( $member, $values );
			$assert( ! str_contains( $html, '<InnerBlocks' ), $member . ': unresolved InnerBlocks.' );
			$assert( ! str_contains( $html, 'onmouseover' ) && ! str_contains( $html, '<script' ), $member . ': hostile setting reached output.' );
			$assert( 'faq' === $member ? '' === trim( $html ) : $has_class( $html, $namespace . '-el-' . $member ), $member . ': empty/hostile render lost its expected root.' );
		}
	}
	foreach ( array( '10px;background:red', 'url(https://example.com)', '40rem;--injected-width:999px' ) as $width ) {
		$html = $render( 'container', array( 'width' => 'custom', 'custom_width' => $width ) );
		$assert( $has_class( $html, $namespace . '-el-container--width-custom' ), 'Custom-width injection case did not reach the custom branch.' );
		$assert( $has_declaration( $html, '--' . $namespace . '-el-container-custom-width', '60rem' ), 'Unsafe custom width did not fall back to 60rem.' );
		$assert( ! str_contains( $root_attribute( $html, 'style' ), $width ), 'Unsafe custom-width input leaked into inline styles.' );
	}
	foreach ( array( array( -9, 1 ), array( 500, 12 ), array( 'invalid', 1 ), array( array( 8 ), 1 ) ) as list( $value, $expected ) ) {
		$assert( $has_class( $render( 'column', array( 'weight' => $value ) ), $namespace . '-el-column--weight-' . $expected ), 'Column weight did not clamp/fall back.' );
	}
	foreach ( array( array( -9, 1 ), array( 500, 6 ), array( 'invalid', 1 ), array( array( 8 ), 1 ) ) as list( $value, $expected ) ) {
		$assert( $has_class( $render( 'grid-cell', array( 'span' => $value ) ), $namespace . '-el-grid-cell--span-' . $expected ), 'Grid span did not clamp/fall back.' );
	}
	foreach ( array( array( -1, 1, 1, 1 ), array( 500, 6, 6, 6 ), array( 'invalid', 3, 2, 1 ), array( array( 8 ), 3, 2, 1 ) ) as list( $value, $desktop, $tablet, $mobile ) ) {
		$html = $render( 'grid', array( 'desktop' => $value, 'tablet' => $value, 'mobile' => $value ) );
		foreach ( array( 'desktop' => $desktop, 'tablet' => $tablet, 'mobile' => $mobile ) as $device => $expected ) {
			$assert( $has_declaration( $html, '--' . $namespace . '-el-grid-' . $device, (string) $expected ), 'Grid ' . $device . ' did not clamp/fall back.' );
		}
	}
	WP_CLI::log( wp_json_encode( array( 'assertions' => $checks, 'members' => $members, 'wordpress' => get_bloginfo( 'version' ), 'acf' => ACF_VERSION, 'theme' => get_stylesheet(), 'writes' => 0 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
} )();
