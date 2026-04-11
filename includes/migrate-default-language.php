<?php
/**
 * TQT — Remap translation storage when the site default language changes.
 *
 * Content is stored relative to "default": unsuffixed keys + post_title / term name / options_* hold the
 * default language; other languages use suffixed keys. Changing default in settings without moving data
 * leaves the wrong text in the slots the front end reads (e.g. Arabic default still reading English
 * from unsuffixed fields). This migration re-reads each logical language value using the OLD default,
 * then writes using the NEW default so slots match the updated setting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collect unique taxonomy slugs that have TQT term-name translations.
 *
 * @return string[]
 */
function tqt_collect_translatable_taxonomies(): array {
	$taxes = [];
	foreach ( tqt_translatable_fields() as $cfg ) {
		foreach ( ( $cfg['taxonomy_fields'] ?? [] ) as $tf ) {
			if ( ! empty( $tf['taxonomy'] ) ) {
				$taxes[] = (string) $tf['taxonomy'];
			}
		}
	}
	return array_values( array_unique( $taxes ) );
}

/**
 * @param string[] $enabled_codes Language codes from settings (enabled_languages).
 */
function tqt_migrate_translation_storage_for_default_change( string $old_default, string $new_default, array $enabled_codes ): void {
	if ( $old_default === $new_default ) {
		return;
	}

	if ( ! function_exists( 'tqt_translatable_fields' ) ) {
		return;
	}

	$enabled_codes = array_values(
		array_filter(
			array_map( 'sanitize_key', $enabled_codes ),
			static function ( $c ) {
				return $c !== '';
			}
		)
	);
	if ( empty( $enabled_codes ) ) {
		return;
	}

	$old_default = sanitize_key( $old_default );
	$new_default = sanitize_key( $new_default );

	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}

	@set_time_limit( 300 );

	$registry = tqt_translatable_fields();

	foreach ( $registry as $post_type => $cfg ) {
		if ( $post_type === 'options' ) {
			continue;
		}

		$post_ids = get_posts(
			[
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		foreach ( $post_ids as $post_id ) {
			tqt_migrate_post_flat_fields( (int) $post_id, $cfg, $old_default, $new_default, $enabled_codes );
			tqt_migrate_post_repeater_price_names( (int) $post_id, $cfg, $old_default, $new_default, $enabled_codes );
		}
	}

	if ( ! empty( $registry['options']['meta_fields'] ) ) {
		tqt_migrate_options_fields( $registry['options']['meta_fields'], $old_default, $new_default, $enabled_codes );
	}

	foreach ( tqt_collect_translatable_taxonomies() as $taxonomy ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			tqt_migrate_term_names( (int) $term->term_id, $taxonomy, $old_default, $new_default, $enabled_codes );
		}
	}

	unset( $GLOBALS['tqt_current_language_memo'] );
}

/**
 * @param array  $cfg              Post-type block from tqt_translatable_fields().
 * @param string[] $enabled_codes
 */
function tqt_migrate_post_flat_fields( int $post_id, array $cfg, string $old_default, string $new_default, array $enabled_codes ): void {
	foreach ( ( $cfg['post_fields'] ?? [] ) as $f ) {
		$name = (string) ( $f['name'] ?? '' );
		if ( $name === '' ) {
			continue;
		}
		$vals = [];
		foreach ( $enabled_codes as $code ) {
			$vals[ $code ] = tqt_read_post_field_logical( $post_id, $name, $code, $old_default );
		}
		foreach ( $enabled_codes as $code ) {
			tqt_write_post_field_logical( $post_id, $name, $code, (string) ( $vals[ $code ] ?? '' ), $new_default );
		}
	}

	foreach ( ( $cfg['meta_fields'] ?? [] ) as $f ) {
		$name = (string) ( $f['name'] ?? '' );
		if ( $name === '' ) {
			continue;
		}
		$vals = [];
		foreach ( $enabled_codes as $code ) {
			$vals[ $code ] = tqt_read_post_meta_logical( $post_id, $name, $code, $old_default );
		}
		foreach ( $enabled_codes as $code ) {
			tqt_write_post_meta_logical( $post_id, $name, $code, (string) ( $vals[ $code ] ?? '' ), $new_default );
		}
	}
}

/**
 * @param string[] $enabled_codes
 */
function tqt_migrate_post_repeater_price_names( int $post_id, array $cfg, string $old_default, string $new_default, array $enabled_codes ): void {
	$repeaters = $cfg['repeater_fields'] ?? [];
	if ( empty( $repeaters ) ) {
		return;
	}

	foreach ( $repeaters as $rep ) {
		$rep_name = (string) ( $rep['repeater'] ?? '' );
		if ( $rep_name === '' ) {
			continue;
		}
		$sub_fields = $rep['sub_fields'] ?? [];
		if ( empty( $sub_fields ) ) {
			continue;
		}

		$row_count = 0;
		if ( function_exists( 'get_field' ) ) {
			$rows = get_field( $rep_name, $post_id, false );
			if ( is_array( $rows ) ) {
				$row_count = count( $rows );
			}
		}
		if ( $row_count === 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( is_array( $all_meta ) ) {
				$max_i = -1;
				foreach ( array_keys( $all_meta ) as $meta_key ) {
					if ( preg_match( '/^' . preg_quote( $rep_name, '/' ) . '_(\d+)_/', (string) $meta_key, $m ) ) {
						$max_i = max( $max_i, (int) $m[1] );
					}
				}
				$row_count = $max_i + 1;
			}
		}

		for ( $i = 0; $i < $row_count; $i++ ) {
			foreach ( $sub_fields as $sf ) {
				$sf_name = (string) ( $sf['name'] ?? '' );
				if ( $sf_name === '' ) {
					continue;
				}
				$base_key = $rep_name . '_' . $i . '_' . $sf_name;
				$vals     = [];
				foreach ( $enabled_codes as $code ) {
					$vals[ $code ] = tqt_read_post_meta_key_logical( $post_id, $base_key, $code, $old_default, $i, $rep_name, $sf_name );
				}
				foreach ( $enabled_codes as $code ) {
					tqt_write_post_meta_key_logical( $post_id, $base_key, $code, (string) ( $vals[ $code ] ?? '' ), $new_default );
				}
			}
		}
	}
}

/**
 * Read repeater subfield with legacy 1-based meta keys (prices_1_price_name).
 *
 * @param string $rep_name Repeater name (e.g. prices).
 * @param string $sf_name  Subfield name.
 */
function tqt_read_post_meta_key_logical( int $post_id, string $base_key, string $lang, string $storage_default, int $i, string $rep_name, string $sf_name ): string {
	$direct = tqt_read_post_meta_logical( $post_id, $base_key, $lang, $storage_default );
	if ( $direct !== '' ) {
		return $direct;
	}
	$legacy_key = $rep_name . '_' . ( $i + 1 ) . '_' . $sf_name;
	return tqt_read_post_meta_logical( $post_id, $legacy_key, $lang, $storage_default );
}

/**
 * @param string[] $enabled_codes
 * @param array    $meta_fields From options registry.
 */
function tqt_migrate_options_fields( array $meta_fields, string $old_default, string $new_default, array $enabled_codes ): void {
	foreach ( $meta_fields as $f ) {
		$name = (string) ( $f['name'] ?? '' );
		if ( $name === '' ) {
			continue;
		}
		$vals = [];
		foreach ( $enabled_codes as $code ) {
			$vals[ $code ] = tqt_read_option_logical( $name, $code, $old_default );
		}
		foreach ( $enabled_codes as $code ) {
			tqt_write_option_logical( $name, $code, (string) ( $vals[ $code ] ?? '' ), $new_default );
		}
	}
}

/**
 * @param string[] $enabled_codes
 */
function tqt_migrate_term_names( int $term_id, string $taxonomy, string $old_default, string $new_default, array $enabled_codes ): void {
	$vals = [];
	foreach ( $enabled_codes as $code ) {
		$vals[ $code ] = tqt_read_term_logical( $term_id, $code, $old_default );
	}
	foreach ( $enabled_codes as $code ) {
		tqt_write_term_logical( $term_id, $taxonomy, $code, (string) ( $vals[ $code ] ?? '' ), $new_default );
	}
}

/* —— Logical read/write helpers (explicit storage default language) —— */

/**
 * @param string $field_name e.g. post_title, description
 */
function tqt_read_post_field_logical( int $post_id, string $field_name, string $lang, string $storage_default ): string {
	if ( $field_name === 'post_title' ) {
		if ( $lang === $storage_default ) {
			return (string) get_post_field( 'post_title', $post_id, 'raw' );
		}
		return (string) get_post_meta( $post_id, 'tqt_post_title_' . $lang, true );
	}
	return tqt_read_post_meta_logical( $post_id, $field_name, $lang, $storage_default );
}

/**
 * @param string $field_name Base meta key (no language suffix).
 */
function tqt_read_post_meta_logical( int $post_id, string $field_name, string $lang, string $storage_default ): string {
	if ( $lang === $storage_default ) {
		return (string) get_post_meta( $post_id, $field_name, true );
	}
	return (string) get_post_meta( $post_id, $field_name . '_' . $lang, true );
}

/**
 * @param string $full_base Full meta key including repeater index (e.g. prices_0_price_name).
 */
function tqt_write_post_meta_key_logical( int $post_id, string $full_base, string $lang, string $value, string $storage_default ): void {
	tqt_write_post_meta_logical( $post_id, $full_base, $lang, $value, $storage_default, false );
}

/**
 * @param string $field_name Base meta key.
 * @param bool   $sync_acf   Call update_field() for top-level ACF fields only (not repeater sub-keys like prices_0_price_name).
 */
function tqt_write_post_meta_logical( int $post_id, string $field_name, string $lang, string $value, string $storage_default, bool $sync_acf = true ): void {
	if ( $lang === $storage_default ) {
		update_post_meta( $post_id, $field_name, $value );
		if ( $sync_acf && function_exists( 'update_field' ) ) {
			update_field( $field_name, $value, $post_id );
		}
		return;
	}
	$suffixed = $field_name . '_' . $lang;
	if ( $value === '' ) {
		delete_post_meta( $post_id, $suffixed );
	} else {
		update_post_meta( $post_id, $suffixed, $value );
	}
}

/**
 * @param string $field_name e.g. post_title, description
 */
function tqt_write_post_field_logical( int $post_id, string $field_name, string $lang, string $value, string $storage_default ): void {
	if ( $field_name !== 'post_title' ) {
		tqt_write_post_meta_logical( $post_id, $field_name, $lang, $value, $storage_default, true );
		return;
	}
	if ( $lang === $storage_default ) {
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => $value,
			]
		);
		return;
	}
	if ( $value === '' ) {
		delete_post_meta( $post_id, 'tqt_post_title_' . $lang );
	} else {
		update_post_meta( $post_id, 'tqt_post_title_' . $lang, $value );
	}
}

function tqt_read_option_logical( string $field_name, string $lang, string $storage_default ): string {
	if ( $lang === $storage_default ) {
		return (string) get_option( 'options_' . $field_name, '' );
	}
	return (string) get_option( 'options_' . $field_name . '_' . $lang, '' );
}

function tqt_write_option_logical( string $field_name, string $lang, string $value, string $storage_default ): void {
	if ( $lang === $storage_default ) {
		update_option( 'options_' . $field_name, $value );
		if ( function_exists( 'update_field' ) ) {
			update_field( $field_name, $value, 'option' );
		}
		return;
	}
	$opt = 'options_' . $field_name . '_' . $lang;
	if ( $value === '' ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $value );
	}
	if ( function_exists( 'update_field' ) ) {
		update_field( $field_name . '_' . $lang, $value, 'option' );
	}
}

function tqt_read_term_logical( int $term_id, string $lang, string $storage_default ): string {
	if ( $lang === $storage_default ) {
		$t = get_term( $term_id );
		return ( $t && ! is_wp_error( $t ) ) ? (string) $t->name : '';
	}
	return (string) get_term_meta( $term_id, 'tqt_name_' . $lang, true );
}

function tqt_write_term_logical( int $term_id, string $taxonomy, string $lang, string $value, string $storage_default ): void {
	if ( $lang === $storage_default ) {
		wp_update_term(
			$term_id,
			$taxonomy,
			[
				'name' => $value,
			]
		);
		return;
	}
	if ( $value === '' ) {
		delete_term_meta( $term_id, 'tqt_name_' . $lang );
	} else {
		update_term_meta( $term_id, 'tqt_name_' . $lang, $value );
	}
}
