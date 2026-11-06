<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Fetcher {

	public static function allowed( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) return false;
		$h = strtolower( $p['host'] );
		foreach ( [ 'canva.site', 'canva.com', 'canva.link' ] as $d ) {
			if ( $h === $d || substr( $h, -( strlen( $d ) + 1 ) ) === '.' . $d ) return true;
		}
		return false;
	}

	public static function is_design_link( $url ) {
		$p = wp_parse_url( $url );
		return ! empty( $p['host'] ) && preg_match( '/(^|\.)canva\.com$/i', $p['host'] );
	}

	public static function get( $url ) {
		if ( ! self::allowed( $url ) ) return new WP_Error( 'wpify_host', 'Only Canva links (canva.site, canva.com, canva.link) are allowed.' );
		$r = wp_safe_remote_get( $url, [
			'timeout'     => 30,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; WPIFY/' . WPIFY_VERSION . '; +WordPress)',
			'headers'     => [ 'Accept' => 'text/html,application/xhtml+xml' ],
		] );
		if ( is_wp_error( $r ) ) return $r;
		$code = wp_remote_retrieve_response_code( $r );
		if ( $code !== 200 ) return new WP_Error( 'wpify_http', 'Canva returned HTTP ' . $code . ' for ' . $url );
		$final = $url;
		if ( isset( $r['http_response'] ) && method_exists( $r['http_response'], 'get_response_object' ) ) {
			$o = $r['http_response']->get_response_object();
			if ( ! empty( $o->url ) ) $final = $o->url;
		}
		if ( ! self::allowed( $final ) ) return new WP_Error( 'wpify_redirect', 'Redirected outside Canva.' );
		return [ 'html' => wp_remote_retrieve_body( $r ), 'url' => $final ];
	}

	public static function norm( $url ) {
		$p    = wp_parse_url( $url );
		$path = rtrim( $p['path'] ?? '', '/' );
		return $p['scheme'] . '://' . $p['host'] . ( $path === '' ? '/' : $path );
	}

	public static function discover( $html, $base ) {
		$bp   = wp_parse_url( $base );
		$host = strtolower( $bp['host'] );
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$title = '';
		$t = $dom->getElementsByTagName( 'title' );
		if ( $t->length ) $title = trim( $t->item( 0 )->textContent );
		$home  = self::norm( $base );
		$pages = [ $home => [ 'url' => $home, 'title' => $title ?: 'Home' ] ];
		if ( self::is_design_link( $base ) ) return array_values( $pages );
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( $href === '' || $href[0] === '#' || preg_match( '#^(mailto|tel|javascript):#i', $href ) ) continue;
			$abs = WPIFY_Parser::absolutize( $href, $base );
			$p   = wp_parse_url( $abs );
			if ( empty( $p['host'] ) || strtolower( $p['host'] ) !== $host ) continue;
			if ( preg_match( '/\.(png|jpe?g|gif|webp|svg|pdf|zip|mp4|css|js|xml|txt)$/i', $p['path'] ?? '' ) ) continue;
			$n = self::norm( $abs );
			if ( isset( $pages[ $n ] ) ) continue;
			$label = trim( preg_replace( '/\s+/', ' ', $a->textContent ) );
			$pages[ $n ] = [ 'url' => $n, 'title' => $label ?: trim( $p['path'] ?? '', '/' ) ];
			if ( count( $pages ) >= 50 ) break;
		}
		return array_values( $pages );
	}

	public static function is_empty_shell( $html ) {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		foreach ( [ 'script', 'style', 'noscript' ] as $t ) {
			$nodes = $dom->getElementsByTagName( $t );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) $nodes->item( $i )->parentNode->removeChild( $nodes->item( $i ) );
		}
		$b = $dom->getElementsByTagName( 'body' );
		$text = $b->length ? trim( $b->item( 0 )->textContent ) : '';
		return strlen( $text ) < 20 && ! $dom->getElementsByTagName( 'img' )->length;
	}

	public static function discover_links( $links, $base, $title ) {
		$bp   = wp_parse_url( $base );
		$host = strtolower( $bp['host'] );
		$home = self::norm( $base );
		$pages = [ $home => [ 'url' => $home, 'title' => $title ?: 'Home' ] ];
		foreach ( $links as $l ) {
			$abs = $l['url'] ?? '';
			$p   = wp_parse_url( $abs );
			if ( empty( $p['host'] ) || empty( $p['scheme'] ) || strtolower( $p['host'] ) !== $host ) continue;
			if ( preg_match( '/\.(png|jpe?g|gif|webp|svg|pdf|zip|mp4|css|js|xml|txt)$/i', $p['path'] ?? '' ) ) continue;
			$n = self::norm( $abs );
			if ( isset( $pages[ $n ] ) ) continue;
			$label = trim( preg_replace( '/\s+/', ' ', $l['text'] ?? '' ) );
			$pages[ $n ] = [ 'url' => $n, 'title' => $label ?: trim( $p['path'] ?? '', '/' ) ];
			if ( count( $pages ) >= 50 ) break;
		}
		return array_values( $pages );
	}
}
