<?php
/**
 * Synchronize approved blocks-dev prototypes into toolkit resources.
 *
 * Build the source theme first, then pass its absolute path to this script.
 * This is development maintenance, not an installer or a release command.
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;

$theme = $argv[1] ?? '';
if ( ! is_file( $theme . '/core/bootstrap.php' ) ) {
	throw new RuntimeException( 'Usage: php tests/sync-elementals.php /path/to/blocks-dev-theme' );
}
$members = array( 'wrapper', 'container', 'heading', 'text', 'columns', 'column', 'grid', 'grid-cell', 'content-with-image' );
$fs      = new Filesystem();
$root    = dirname( __DIR__ ) . '/resources/blocks/';

foreach ( $members as $member ) {
	$slug    = 'el-' . $member;
	$source  = $theme . '/blocks/stwp-' . $slug;
	$package = $root . $slug;
	$block   = json_decode( (string) file_get_contents( $source . '/block.json' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( array( 'view-style', 'editor-style' ) as $style ) {
		if ( ! is_file( $source . '/assets/css/' . $style . '.min.css' ) ) {
			throw new RuntimeException( 'Build source assets before synchronization: ' . $slug );
		}
	}
	$fs->mkdir( $package );
	$fs->mirror( $source, $package . '/files', null, array( 'override' => true, 'delete' => true ) );
	$acf_file = 'acf-json/group_stwp_el_' . str_replace( '-', '_', $member ) . '.json';
	$helpers  = array( 'st_wp_core_get_block_wrapper_attributes' );
	if ( 'content-with-image' === $member ) {
		$helpers[] = 'st_wp_core_generate_img';
	}
	$theme_files = array();
	if ( is_file( $theme . '/' . $acf_file ) ) {
		$fs->mkdir( $package . '/theme-files/acf-json' );
		$fs->copy( $theme . '/' . $acf_file, $package . '/theme-files/' . $acf_file, true );
		$theme_files[] = $acf_file;
	}
	$manifest = array(
		'schema' => 1,
		'source_slug' => $slug,
		'default_title' => $block['title'],
		'source_namespace' => 'stwp',
		'source_category' => 'st-wp-starter',
		'bundle_only' => true,
		'required_core_version' => '>=1.1.0',
		'required_php_helpers' => $helpers,
		'npm_dependencies' => (object) array(),
		'shared_assets' => array(),
		'theme_files' => $theme_files,
	);
	$fs->dumpFile( $package . '/st-block.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
}
fwrite( STDOUT, "Synchronized nine elemental blocks, ACF sources and compiled CSS.\n" );
