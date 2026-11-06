<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Media {

	private static $cache = [];

	/** Replace every remote image in the model with a Media Library copy (id + url). */
	public static function localize( array &$page, $post_id ) {
		foreach ( $page['sections'] as &$sec ) {
			if ( ! empty( $sec['bg']['image'] ) ) {
				$m = self::sideload( $sec['bg']['image'], $post_id );
				$sec['bg']['image_id'] = $m['id'];
				$sec['bg']['image']    = $m['url'];
			}
			self::walk( $sec['elements'], $post_id );
			if ( ! empty( $sec['items'] ) ) self::walk( $sec['items'], $post_id );
		}
		unset( $sec );
	}

	private static function walk( array &$els, $post_id ) {
		foreach ( $els as &$e ) {
			if ( $e['type'] === 'row' ) { foreach ( $e['cols'] as &$col ) self::walk( $col, $post_id ); unset( $col ); }
			elseif ( $e['type'] === 'image' ) { $m = self::sideload( $e['src'], $post_id ); $e['id'] = $m['id']; $e['src'] = $m['url']; }
		}
		unset( $e );
	}

	public static function sideload( $url, $post_id ) {
		if ( isset( self::$cache[ $url ] ) ) return self::$cache[ $url ];
		$fail = [ 'id' => 0, 'url' => $url ];

		$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
			'meta_key' => '_wpify_source_url', 'meta_value' => $url ] );
		if ( $existing ) return self::$cache[ $url ] = [ 'id' => $existing[0], 'url' => wp_get_attachment_url( $existing[0] ) ];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) return self::$cache[ $url ] = $fail;

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ?: 'image' ) );
		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
		$ext  = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
		if ( $mime && isset( $ext[ $mime ] ) && ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name ) ) $name = ( pathinfo( $name, PATHINFO_FILENAME ) ?: 'image' ) . '.' . $ext[ $mime ];

		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $post_id );
		if ( is_wp_error( $id ) ) { @unlink( $tmp ); return self::$cache[ $url ] = $fail; }
		update_post_meta( $id, '_wpify_source_url', $url );
		return self::$cache[ $url ] = [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ];
	}
}
