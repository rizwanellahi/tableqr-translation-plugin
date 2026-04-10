<?php
/**
 * TQT — Shortcodes and optional front helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tailwind-friendly language dropdown (replaces legacy [wpg_switcher]).
 *
 * @return string
 */
function tqt_shortcode_language_switcher(): string {
	if ( ! function_exists( 'tqt_active_languages' ) || ! function_exists( 'tqt_get_current_language' ) ) {
		return '';
	}

	$langs = tqt_active_languages();
	if ( count( $langs ) <= 1 ) {
		return '';
	}

	$settings = tqt_get_settings();
	$default  = $settings['default_language'];
	$current  = tqt_get_current_language();

	$scheme = is_ssl() ? 'https' : 'http';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	if ( $host === '' ) {
		return '';
	}

	$here     = $scheme . '://' . $host . $uri;
	$base_url = function_exists( 'tqt_delocalize_url' ) ? tqt_delocalize_url( $here ) : $here;

	$uid = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'tqt-sw-' ) : uniqid( 'tqt-sw-', false );

	ob_start();
	?>
	<div class="grid grid-cols-1">
		<select id="<?php echo esc_attr( $uid ); ?>" name="tqt_language"
			class="col-start-1 row-start-1 w-full appearance-none shadow-md rounded-full cursor-pointer bg-white py-1.5 ltr:pr-10 ltr:pl-5 rtl:pr-5 rtl:pl-10 text-xs sm:text-base font-medium text-gray-900 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--brand-color)]"
			onchange="if(this.value) window.location.href=this.value" aria-label="<?php echo esc_attr__( 'Change language', 'tqt' ); ?>">
			<?php foreach ( $langs as $code => $info ) : ?>
				<?php
				if ( $code === $default ) {
					$url = $base_url;
				} else {
					$url = tqt_localize_url( $base_url, $code );
				}
				$label = isset( $info['native'] ) ? (string) $info['native'] : ( isset( $info['label'] ) ? (string) $info['label'] : strtoupper( $code ) );
				?>
				<option value="<?php echo esc_url( $url ); ?>" <?php selected( $code, $current ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<svg viewBox="0 0 16 16" fill="currentColor" data-slot="icon" aria-hidden="true" stroke-width="1"
			class="pointer-events-none col-start-1 row-start-1 ltr:mr-3 rtl:ml-3 size-4 self-center justify-self-end text-black sm:size-5">
			<path
				d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
				clip-rule="evenodd" fill-rule="evenodd" />
		</svg>
	</div>
	<?php
	return (string) ob_get_clean();
}

add_action(
	'init',
	static function () {
		add_shortcode( 'tqt_language_switcher', 'tqt_shortcode_language_switcher' );
		add_shortcode( 'wpg_switcher', 'tqt_shortcode_language_switcher' );
	}
);

/**
 * Browser tab title on menu category / section archives.
 */
function tqt_filter_document_title_term_translation( array $parts ): array {
	if ( ! is_tax( [ 'menu_category', 'menu_section' ] ) || ! function_exists( 'tqt_term_display_name' ) ) {
		return $parts;
	}
	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return $parts;
	}
	$parts['title'] = tqt_term_display_name( $term );
	return $parts;
}

add_filter( 'document_title_parts', 'tqt_filter_document_title_term_translation', 20 );
