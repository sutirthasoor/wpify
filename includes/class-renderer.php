<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Renderer {

	public static function url() { return untrailingslashit( (string) get_option( 'wpify_renderer_url', '' ) ); }
	public static function configured() { return self::url() !== ''; }

	public static function snapshot( $url ) {
		if ( ! WPIFY_Fetcher::allowed( $url ) ) return new WP_Error( 'wpify_host', 'Only Canva links are allowed.' );
		$r = wp_remote_post( self::url() . '/snapshot', [
			'timeout' => 120,
			'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . get_option( 'wpify_renderer_token', '' ) ],
			'body'    => wp_json_encode( [ 'url' => $url ] ),
		] );
		if ( is_wp_error( $r ) ) return new WP_Error( 'wpify_renderer', 'Renderer unreachable: ' . $r->get_error_message() );
		$code = wp_remote_retrieve_response_code( $r );
		$body = wp_remote_retrieve_body( $r );
		$j    = json_decode( $body, true );
		if ( $code !== 200 || ! is_array( $j ) || ! isset( $j['blocks'] ) ) {
			return new WP_Error( 'wpify_renderer', 'Renderer error (' . $code . '): ' . ( is_array( $j ) && isset( $j['error'] ) ? $j['error'] : substr( wp_strip_all_tags( $body ), 0, 200 ) ) );
		}
		return $j;
	}
}
