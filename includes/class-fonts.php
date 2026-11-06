<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Fonts {

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'print_css' ], 1 );
	}

	public static function print_css() {
		if ( ! is_singular() ) return;
		$css = get_post_meta( get_the_ID(), '_wpify_fontcss', true );
		if ( $css ) echo '<style id="wpify-fonts">' . $css . '</style>' . "\n"; // built by localize(): sanitized values only
	}

	/** Download @font-face files used by the design into uploads and store CSS on the post. */
	public static function localize( array $fonts, $post_id ) {
		if ( ! $fonts ) return 0;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$up  = wp_upload_dir();
		$dir = $up['basedir'] . '/wpify-fonts';
		wp_mkdir_p( $dir );
		$css = ''; $n = 0;
		foreach ( $fonts as $f ) {
			$url = $f['url'] ?? '';
			if ( ! preg_match( '#\.(woff2|woff|ttf|otf)(\?|$)#i', $url, $m ) ) continue;
			$ext  = strtolower( $m[1] );
			$name = md5( $url ) . '.' . $ext;
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				$tmp = download_url( $url, 30 );
				if ( is_wp_error( $tmp ) ) continue;
				@copy( $tmp, $path ); @unlink( $tmp );
				if ( ! file_exists( $path ) ) continue;
			}
			$fam    = preg_replace( '/[^A-Za-z0-9 _-]/', '', $f['family'] ?? '' );
			$weight = preg_replace( '/[^0-9a-z ]/i', '', $f['weight'] ?? '400' ) ?: '400';
			$fmt    = $ext === 'ttf' ? 'truetype' : ( $ext === 'otf' ? 'opentype' : $ext );
			$range  = ( ! empty( $f['range'] ) && preg_match( '/^[uU+0-9a-fA-F,\s?-]+$/', $f['range'] ) ) ? 'unicode-range:' . $f['range'] . ';' : '';
			$css   .= '@font-face{font-family:"' . $fam . '";src:url(' . esc_url_raw( $up['baseurl'] . '/wpify-fonts/' . $name ) . ') format("' . $fmt . '");font-weight:' . $weight . ';font-style:' . ( ( $f['style'] ?? '' ) === 'italic' ? 'italic' : 'normal' ) . ';font-display:swap;' . $range . '}';
			$n++;
		}
		if ( $css ) update_post_meta( $post_id, '_wpify_fontcss', $css );
		return $n;
	}
}
