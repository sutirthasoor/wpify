<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Canva published-site HTML  ->  neutral model:
 * [ 'title', 'sections' => [ [ 'bg'=>['color','image'], 'elements'=>[ {type,...} ] ] ] ]
 * Element types: heading, text, list, image, button
 */
class WPIFY_Parser {

	private $rules = [];
	private $base  = '';
	private $h1    = false;
	private $inline = [ 'br', 'span', 'strong', 'b', 'em', 'i', 'u', 'a', 'sup', 'sub' ];

	public static function parse( $html, $base ) {
		$p = new self();
		return $p->run( $html, $base );
	}

	public static function absolutize( $u, $base ) {
		$u = trim( $u );
		if ( $u === '' || stripos( $u, 'data:' ) === 0 ) return $u;
		if ( preg_match( '#^https?://#i', $u ) ) return $u;
		$b = wp_parse_url( $base );
		if ( substr( $u, 0, 2 ) === '//' ) return $b['scheme'] . ':' . $u;
		$root = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( $u[0] === '/' ) return $root . $u;
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		return $root . $dir . $u;
	}

	private function run( $html, $base ) {
		$this->base = $base;
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xp = new DOMXPath( $dom );

		$title = '';
		$og = $xp->query( '//meta[@property="og:title"]/@content' );
		if ( $og->length ) $title = trim( $og->item( 0 )->nodeValue );
		if ( ! $title ) { $t = $xp->query( '//title' ); if ( $t->length ) $title = trim( $t->item( 0 )->textContent ); }

		// Plain canva.com/design/... link: only the preview image is public.
		if ( WPIFY_Fetcher::is_design_link( $base ) ) {
			$img = $xp->query( '//meta[@property="og:image"]/@content' );
			$els = [];
			if ( $img->length ) $els[] = [ 'type' => 'image', 'src' => $img->item( 0 )->nodeValue, 'alt' => $title ];
			return [ 'title' => $title, 'fallback' => true, 'sections' => [ [ 'bg' => [], 'elements' => $els ] ] ];
		}

		$css = '';
		foreach ( $xp->query( '//style' ) as $s ) $css .= $s->textContent . "\n";
		$this->rules = $this->parse_css( $css );

		foreach ( $xp->query( '//script|//style|//noscript|//head|//nav|//template' ) as $n ) {
			if ( $n->parentNode ) $n->parentNode->removeChild( $n );
		}
		$body = $xp->query( '//body' )->item( 0 );
		if ( ! $body ) return [ 'title' => $title, 'sections' => [] ];

		// Descend through single-child wrappers to find the real section list.
		$node = $body;
		for ( $i = 0; $i < 15; $i++ ) {
			$kids = $this->kids( $node );
			if ( count( $kids ) === 1 && ! $this->has_bg_image( $kids[0] ) ) $node = $kids[0]; else break;
		}
		$sections = [];
		foreach ( $this->kids( $node ) as $sec ) {
			$st  = $this->style_of( $sec );
			$els = [];
			$this->walk( $sec, [], $els, true );
			$els = $this->dedupe( $els );
			$bg  = [ 'color' => $this->color( $st['background-color'] ?? ( $st['background'] ?? '' ) ), 'image' => $this->bg_image( $st ) ];
			if ( $els || $bg['image'] ) $sections[] = [ 'bg' => $bg, 'elements' => $els ];
		}
		return [ 'title' => $title, 'sections' => $sections ];
	}

	/* ---------- walking ---------- */

	private function walk( $n, $inh, &$els, $root = false ) {
		if ( $n->nodeType !== XML_ELEMENT_NODE ) return;
		$tag = strtolower( $n->nodeName );
		if ( in_array( $tag, [ 'script', 'style', 'noscript', 'svg', 'head', 'nav', 'template' ], true ) ) return;
		$st = $this->style_of( $n );
		if ( ( $st['display'] ?? '' ) === 'none' ) return;

		$ctx = $inh;
		foreach ( [ 'color', 'text-align', 'font-size', 'font-weight' ] as $k ) if ( isset( $st[ $k ] ) ) $ctx[ $k ] = $st[ $k ];

		if ( ! $root && ( $bg = $this->bg_image( $st ) ) ) $els[] = [ 'type' => 'image', 'src' => $bg, 'alt' => '' ];

		if ( $tag === 'img' ) {
			$src = $this->img_src( $n );
			if ( $src ) $els[] = [ 'type' => 'image', 'src' => $src, 'alt' => $n->getAttribute( 'alt' ) ];
			return;
		}
		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			$txt = $this->plain( $n );
			if ( $txt !== '' ) $els[] = $this->mk( 'heading', $n, $ctx, [ 'level' => (int) $m[1], 'text' => $txt ] );
			return;
		}
		if ( $tag === 'p' ) {
			if ( $this->plain( $n ) !== '' ) $els[] = $this->mk( 'text', $n, $ctx, [ 'html' => $this->inner( $n ) ] );
			return;
		}
		if ( $tag === 'ul' || $tag === 'ol' ) {
			$items = [];
			foreach ( $n->getElementsByTagName( 'li' ) as $li ) { $h = $this->inner( $li ); if ( $h !== '' ) $items[] = $h; }
			if ( $items ) $els[] = [ 'type' => 'list', 'ordered' => $tag === 'ol', 'items' => $items ];
			return;
		}
		if ( $tag === 'a' || $tag === 'button' ) {
			$href = $tag === 'a' ? $n->getAttribute( 'href' ) : '#';
			$href = $href ? self::absolutize( $href, $this->base ) : '#';
			$imgs = $n->getElementsByTagName( 'img' );
			$txt  = $this->plain( $n );
			if ( $imgs->length && $txt === '' ) {
				$src = $this->img_src( $imgs->item( 0 ) );
				if ( $src ) $els[] = [ 'type' => 'image', 'src' => $src, 'alt' => $imgs->item( 0 )->getAttribute( 'alt' ), 'href' => $href ];
				return;
			}
			if ( $txt !== '' && $this->is_text_leaf( $n, 3 ) ) {
				$els[] = [ 'type' => 'button', 'text' => $txt, 'href' => $href,
					'bg' => $this->color( $st['background-color'] ?? ( $st['background'] ?? '' ) ), 'color' => $this->color( $ctx['color'] ?? '' ),
					'align' => $ctx['text-align'] ?? '' ];
				return;
			}
		}

		$kids = $this->kids( $n );
		// div wrapping a single link -> let the link become the button
		if ( count( $kids ) === 1 && strtolower( $kids[0]->nodeName ) === 'a' && $this->own_text( $n ) === '' ) {
			$this->walk( $kids[0], $ctx, $els );
			return;
		}
		if ( ! $root && $this->is_text_leaf( $n, 1 ) ) {
			$size = $this->px( $ctx['font-size'] ?? '' );
			if ( $size >= 44 ) { $lvl = $this->h1 ? 2 : 1; $this->h1 = true; $els[] = $this->mk( 'heading', $n, $ctx, [ 'level' => $lvl, 'text' => $this->plain( $n ) ] ); }
			elseif ( $size >= 30 ) $els[] = $this->mk( 'heading', $n, $ctx, [ 'level' => 2, 'text' => $this->plain( $n ) ] );
			elseif ( $size >= 22 ) $els[] = $this->mk( 'heading', $n, $ctx, [ 'level' => 3, 'text' => $this->plain( $n ) ] );
			else $els[] = $this->mk( 'text', $n, $ctx, [ 'html' => $this->inner( $n ) ] );
			return;
		}
		foreach ( $kids as $k ) $this->walk( $k, $ctx, $els );
	}

	private function mk( $type, $n, $ctx, $extra ) {
		return array_merge( [ 'type' => $type, 'color' => $this->color( $ctx['color'] ?? '' ), 'align' => $this->align( $ctx['text-align'] ?? '' ) ], $extra );
	}

	private function dedupe( $els ) {
		$out = []; $prev = '';
		foreach ( $els as $e ) {
			$k = md5( wp_json_encode( $e ) );
			if ( $k !== $prev ) $out[] = $e;
			$prev = $k;
		}
		return $out;
	}

	/* ---------- helpers ---------- */

	private function kids( $n ) {
		$o = [];
		foreach ( $n->childNodes as $c ) if ( $c->nodeType === XML_ELEMENT_NODE ) $o[] = $c;
		return $o;
	}

	private function own_text( $n ) {
		$t = '';
		foreach ( $n->childNodes as $c ) if ( $c->nodeType === XML_TEXT_NODE ) $t .= $c->nodeValue;
		return trim( $t );
	}

	private function plain( $n ) {
		return trim( preg_replace( '/\s+/u', ' ', $n->textContent ) );
	}

	private function inner( $n ) {
		$h = '';
		foreach ( $n->childNodes as $c ) $h .= $n->ownerDocument->saveHTML( $c );
		$h = wp_kses( $h, [ 'a' => [ 'href' => true ], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [] ] );
		return trim( $h );
	}

	private function is_text_leaf( $n, $maxdiv ) {
		foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'img', 'section', 'article', 'table', 'form', 'video', 'picture', 'button' ] as $t ) {
			if ( $n->getElementsByTagName( $t )->length ) return false;
		}
		if ( $n->getElementsByTagName( 'div' )->length > $maxdiv ) return false;
		return $this->plain( $n ) !== '';
	}

	private function img_src( $img ) {
		$src = $img->getAttribute( 'src' );
		if ( ! $src || stripos( $src, 'data:' ) === 0 ) $src = $img->getAttribute( 'data-src' );
		$set = $img->getAttribute( 'srcset' );
		if ( $set ) { $parts = array_filter( array_map( 'trim', explode( ',', $set ) ) ); $last = end( $parts ); $src = preg_split( '/\s+/', $last )[0]; }
		if ( ! $src || stripos( $src, 'data:' ) === 0 ) return '';
		return self::absolutize( $src, $this->base );
	}

	private function has_bg_image( $n ) { return (bool) $this->bg_image( $this->style_of( $n ) ); }

	private function bg_image( $st ) {
		$v = ( $st['background-image'] ?? '' ) . ' ' . ( $st['background'] ?? '' );
		if ( preg_match( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $v, $m ) && stripos( $m[1], 'data:' ) !== 0 ) return self::absolutize( html_entity_decode( $m[1] ), $this->base );
		return '';
	}

	private function parse_css( $css ) {
		$css = preg_replace( '!/\*.*?\*/!s', '', $css );
		$css = preg_replace( '/@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css );
		$rules = [];
		if ( preg_match_all( '/([^{}@]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $r ) {
				$d = $this->decl( $r[2] );
				foreach ( explode( ',', $r[1] ) as $sel ) {
					if ( preg_match( '/^\s*\.([A-Za-z0-9_-]+)\s*$/', $sel, $c ) ) $rules[ $c[1] ] = array_merge( $rules[ $c[1] ] ?? [], $d );
				}
			}
		}
		return $rules;
	}

	private function decl( $s ) {
		$o = [];
		foreach ( explode( ';', $s ) as $d ) {
			$p = strpos( $d, ':' );
			if ( $p === false ) continue;
			$o[ strtolower( trim( substr( $d, 0, $p ) ) ) ] = trim( substr( $d, $p + 1 ) );
		}
		return $o;
	}

	private function style_of( $n ) {
		$st = [];
		foreach ( preg_split( '/\s+/', trim( $n->getAttribute( 'class' ) ) ) as $c ) if ( $c !== '' && isset( $this->rules[ $c ] ) ) $st = array_merge( $st, $this->rules[ $c ] );
		return array_merge( $st, $this->decl( $n->getAttribute( 'style' ) ) );
	}

	private function px( $v ) {
		if ( preg_match( '/([\d.]+)\s*(px|rem|em)/i', $v, $m ) ) return strtolower( $m[2] ) === 'px' ? (float) $m[1] : (float) $m[1] * 16;
		return 0;
	}

	private function align( $v ) { $v = strtolower( trim( $v ) ); return in_array( $v, [ 'left', 'center', 'right' ], true ) ? $v : ''; }

	private function color( $v ) {
		if ( ! $v ) return '';
		if ( preg_match( '/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3})\b/i', $v, $m ) ) return '#' . strtolower( substr( $m[1], 0, 6 ) );
		if ( preg_match( '/rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,\/]+([\d.]+%?))?/i', $v, $m ) ) {
			if ( isset( $m[4] ) && (float) $m[4] === 0.0 ) return '';
			return sprintf( '#%02x%02x%02x', min( 255, $m[1] ), min( 255, $m[2] ), min( 255, $m[3] ) );
		}
		return '';
	}
}
