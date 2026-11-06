<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Importer {

	public static function import_page( $url, $label, $builder, $status, $sideload, $opts = [] ) {
		if ( WPIFY_Renderer::configured() ) {
			$snap = WPIFY_Renderer::snapshot( $url );
			if ( is_wp_error( $snap ) ) return $snap;
			$exact = $builder === 'elementor' && ! empty( $opts['exact'] );
			$page  = $exact ? WPIFY_Exact::to_model( $snap ) : WPIFY_Snapshot::to_model( $snap, $url );
			$page['canvas'] = ! empty( $opts['canvas'] );
		} else {
			$res = WPIFY_Fetcher::get( $url );
			if ( is_wp_error( $res ) ) return $res;
			$page = WPIFY_Parser::parse( $res['html'], $res['url'] );
		}
		if ( empty( $page['sections'] ) || ( ! empty( $page['exact'] ) && ! array_filter( array_column( $page['sections'], 'items' ) ) ) ) {
			return new WP_Error( 'wpify_empty', WPIFY_Renderer::configured()
				? 'Renderer returned no visible content for ' . $url . '. Check the site is published and public.'
				: 'No content in the raw HTML of ' . $url . '. Canva Websites are rendered by JavaScript: set the Renderer URL in WPIFY settings.' );
		}
		$title = ! empty( $page['title'] ) ? $page['title'] : ( $label ?: 'Imported page' );

		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$slug = $path ? sanitize_title( basename( $path ) ) : sanitize_title( $label ?: 'home' );

		$post_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_name' => $slug, 'post_content' => '' ], true );
		if ( is_wp_error( $post_id ) ) return $post_id;

		if ( $sideload ) WPIFY_Media::localize( $page, $post_id );

		if ( ! empty( $page['fonts'] ) ) WPIFY_Fonts::localize( $page['fonts'], $post_id );

		$b = WPIFY_Builder::make( $builder );
		if ( ! $b ) return new WP_Error( 'wpify_builder', 'Unknown builder.' );
		$b->build( $page, $post_id );

		if ( $status === 'publish' ) wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_wpify_source', $url );

		$edit = get_edit_post_link( $post_id, 'raw' );
		if ( $builder === 'elementor' ) $edit = admin_url( 'post.php?post=' . $post_id . '&action=elementor' );
		if ( $builder === 'bricks' )    $edit = add_query_arg( 'bricks', 'run', get_permalink( $post_id ) );

		return [ 'id' => $post_id, 'title' => $title, 'edit' => $edit, 'fallback' => ! empty( $page['fallback'] ), 'sections' => count( $page['sections'] ) ];
	}
}
