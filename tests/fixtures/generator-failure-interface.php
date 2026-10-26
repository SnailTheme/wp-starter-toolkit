<?php
/**
 * Interface stub for offline failure-receipt safety checks, NOT generator tests.
 *
 * It only supplies the Composer identity needed to reach fake Composer's exit
 * 23. Replacement/name generation and source cleanup deliberately do nothing.
 * Real generated-theme acceptance must continue to use the real generator.
 */

declare(strict_types=1);

if ( '1' !== getenv( 'ST_ELEMENTAL_FAILURE_INTERFACE' ) ) {
	throw new RuntimeException( 'This generator-interface fixture is only for the offline failure-receipt test.' );
}

function theme_generator_validate_inputs( array $inputs ): array {
	return array(
		'errors' => array(),
		'validated' => array( 'composer_package' => 'acme-inc/elemental-acceptance' ),
	);
}

function theme_generator_get_name_variations( string $name, string $namespace ): array {
	return array();
}

function theme_generator_update_composer_package( string $theme, string $package ): void {
	$path = $theme . '/composer.json';
	$manifest = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	$manifest['name'] = $package;
	file_put_contents( $path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . PHP_EOL );
}

function theme_generator_create_replacement_map( array $data ): array {
	return array();
}

function theme_generator_process_files( string $theme, array $replacements, string $source_namespace, string $target_namespace ): void {
	// No production replacements are simulated by this failure-only fixture.
}

function theme_generator_remove_source_only_files( string $theme ): void {
	// No production cleanup is simulated by this failure-only fixture.
}
