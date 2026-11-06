<?php
if ( ! defined( 'ABSPATH' ) ) exit;

abstract class WPIFY_Builder {
	abstract public function build( array $page, $post_id );

	public static function make( $key ) {
		switch ( $key ) {
			case 'gutenberg': return new WPIFY_Builder_Gutenberg();
			case 'elementor': return new WPIFY_Builder_Elementor();
			case 'bricks':    return new WPIFY_Builder_Bricks();
		}
		return null;
	}

	protected static function rid( $len ) {
		return substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz0123456789' ), 0, $len );
	}

	protected static function json( $v ) {
		return wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
