<?php
/**
 * Accessible mega-menu component.
 *
 * This component enhances the existing `menu-1` navigation location without
 * replacing the theme template or its walker. Parent items receive disclosure
 * buttons, submenu classes are normalized for BEM styling, and assets load only
 * when the component source has been built.
 *
 * Add the optional `is-mega-menu` CSS class to a top-level WordPress menu item
 * when its submenu should use the full-width grid treatment.
 *
 * @package ST_WP_Starter
 */

declare(strict_types=1);

if ( ! function_exists( 'st_wp_starter_mega_menu_args' ) ) {
	/**
	 * Add the component container and list classes to the primary menu.
	 *
	 * @param array<string,mixed> $args wp_nav_menu() arguments.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string,mixed>
	 */
	function st_wp_starter_mega_menu_args( array $args ): array {
		if ( 'menu-1' !== ( $args['theme_location'] ?? '' ) ) {
			return $args;
		}

		$args['container']       = 'div';
		$args['container_class'] = trim( 'mega-menu ' . (string) ( $args['container_class'] ?? '' ) );
		$args['menu_class']      = trim( 'mega-menu__list ' . (string) ( $args['menu_class'] ?? 'menu' ) );

		return $args;
	}
}
add_filter( 'wp_nav_menu_args', 'st_wp_starter_mega_menu_args' );

if ( ! function_exists( 'st_wp_starter_mega_menu_submenu_classes' ) ) {
	/**
	 * Add a stable component class to primary-menu submenus.
	 *
	 * @param string[] $classes Existing submenu classes.
	 * @param stdClass $args    wp_nav_menu() arguments object.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	function st_wp_starter_mega_menu_submenu_classes( array $classes, stdClass $args ): array {
		if ( 'menu-1' === ( $args->theme_location ?? '' ) ) {
			$classes[] = 'mega-menu__sub-menu';
		}

		return array_values( array_unique( $classes ) );
	}
}
add_filter( 'nav_menu_submenu_css_class', 'st_wp_starter_mega_menu_submenu_classes', 10, 2 );

if ( ! function_exists( 'st_wp_starter_mega_menu_item_output' ) ) {
	/**
	 * Add a real button beside primary-menu links that own a submenu.
	 *
	 * JavaScript assigns `aria-controls` after pairing the button with its
	 * following submenu. Without JavaScript, no `hidden` attribute is added and
	 * the nested menu remains available as ordinary markup.
	 *
	 * @param string   $item_output Rendered menu-item link markup.
	 * @param WP_Post  $menu_item   Navigation menu item.
	 * @param int      $depth       Item depth.
	 * @param stdClass $args        wp_nav_menu() arguments object.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	function st_wp_starter_mega_menu_item_output( string $item_output, WP_Post $menu_item, int $depth, stdClass $args ): string {
		if (
			'menu-1' !== ( $args->theme_location ?? '' )
			|| ! in_array( 'menu-item-has-children', (array) $menu_item->classes, true )
		) {
			return $item_output;
		}

		$label = sprintf(
			/* translators: %s: navigation menu item label. */
			esc_html__( 'Toggle submenu for %s', 'st-wp-starter' ),
			wp_strip_all_tags( (string) $menu_item->title )
		);

		$button = sprintf(
			'<button class="mega-menu__toggle" type="button" aria-expanded="false"><span class="screen-reader-text">%1$s</span><span class="mega-menu__toggle-icon" aria-hidden="true"></span></button>',
			esc_html( $label )
		);

		return $item_output . $button;
	}
}
add_filter( 'walker_nav_menu_start_el', 'st_wp_starter_mega_menu_item_output', 10, 4 );

if ( ! function_exists( 'st_wp_starter_mega_menu_assets' ) ) {
	/**
	 * Enqueue the compiled component assets on the front end.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	function st_wp_starter_mega_menu_assets(): void {
		if ( ! has_nav_menu( 'menu-1' ) ) {
			return;
		}

		$style       = '/assets/css/components/mega-menu.min.css';
		$script      = '/assets/js/components/mega-menu.min.js';
		$style_path  = get_template_directory() . $style;
		$script_path = get_template_directory() . $script;

		if ( is_file( $style_path ) ) {
			$style_version = filemtime( $style_path );

			wp_enqueue_style(
				'st-wp-starter-mega-menu',
				get_template_directory_uri() . $style,
				array(),
				false === $style_version ? null : (string) $style_version
			);
		}

		$script_size = is_file( $script_path ) ? filesize( $script_path ) : false;

		if ( false !== $script_size && 0 < $script_size ) {
			$script_version = filemtime( $script_path );

			wp_enqueue_script(
				'st-wp-starter-mega-menu',
				get_template_directory_uri() . $script,
				array(),
				false === $script_version ? null : (string) $script_version,
				true
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'st_wp_starter_mega_menu_assets' );
