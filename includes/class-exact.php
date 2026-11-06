<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Snapshot -> "exact" model: full-width sections (height + background) holding absolutely positioned items.
 * All geometry is converted to viewport-relative units (x/w in %, y/h/font in vw) so the design scales like Canva does.
 */
class WPIFY_Exact {

	public static function to_model( $snap ) {
		$vw   = max( 320, (float) ( $snap['vw'] ?? 1440 ) );
		$docH = max( 1, (float) ( $snap['docH'] ?? 1 ) );
		$k    = 100 / $vw;
		$pbg  = $snap['pageBg'] ?? '';

		// --- top-level bands -> sections
		$raw = [];
		foreach ( $snap['bands'] ?? [] as $b ) {
			if ( ! isset( $b['y'], $b['h'] ) ) continue;
			$raw[ round( $b['y'] / 20 ) . '-' . round( $b['h'] / 20 ) ] = $b;
		}
		$raw = array_values( $raw );
		usort( $raw, function ( $a, $b ) { return [ $a['y'], -$a['h'] ] <=> [ $b['y'], -$b['h'] ]; } );
		$top = [];
		foreach ( $raw as $b ) {
			$inside = false;
			foreach ( $top as $t ) if ( $b['y'] >= $t['y'] - 2 && $b['y'] + $b['h'] <= $t['y'] + $t['h'] + 2 ) { $inside = true; break; }
			if ( ! $inside ) $top[] = $b;
		}
		$secs = []; $cur = 0;
		foreach ( $top as $b ) {
			if ( $b['y'] < $cur ) { $b['h'] -= $cur - $b['y']; $b['y'] = $cur; }
			if ( $b['h'] < 20 ) continue;
			if ( $b['y'] - $cur > 3 ) $secs[] = [ 'y0' => $cur, 'h' => $b['y'] - $cur, 'bg' => [ 'color' => $pbg, 'image' => '' ], 'band' => null, 'items' => [] ];
			$secs[] = [ 'y0' => $b['y'], 'h' => $b['h'], 'bg' => [ 'color' => $b['colorRaw'] ?? ( $b['color'] ?? '' ), 'image' => $b['image'] ?? '' ], 'band' => $b, 'items' => [] ];
			$cur = $b['y'] + $b['h'];
		}
		if ( $docH - $cur > 3 ) $secs[] = [ 'y0' => $cur, 'h' => $docH - $cur, 'bg' => [ 'color' => $pbg, 'image' => '' ], 'band' => null, 'items' => [] ];
		if ( ! $secs ) $secs[] = [ 'y0' => 0, 'h' => $docH, 'bg' => [ 'color' => $pbg, 'image' => '' ], 'band' => null, 'items' => [] ];

		// --- items (blocks + shapes), skipping shapes that are the section backgrounds themselves
		$items = [];
		foreach ( $snap['blocks'] ?? [] as $b ) if ( isset( $b['type'], $b['x'], $b['y'], $b['w'], $b['h'] ) ) $items[] = $b;
		foreach ( $snap['shapes'] ?? [] as $s ) {
			if ( ! isset( $s['x'], $s['y'], $s['w'], $s['h'] ) ) continue;
			$is_bg = false;
			foreach ( $secs as $sec ) if ( $sec['band'] && abs( $s['y'] - $sec['y0'] ) < 4 && abs( $s['h'] - $sec['h'] ) < 4 && $s['w'] >= $vw * 0.9 ) { $is_bg = true; break; }
			if ( ! $is_bg ) { $s['type'] = 'shape'; $items[] = $s; }
		}
		usort( $items, function ( $a, $b ) { return ( $a['z'] ?? 0 ) <=> ( $b['z'] ?? 0 ); } );
		$items = array_slice( $items, 0, 1500 );

		$h1 = false; $z = 0;
		foreach ( $items as $it ) {
			$cy = $it['y'] + $it['h'] / 2;
			$si = count( $secs ) - 1;
			foreach ( $secs as $i => $sec ) if ( $cy < $sec['y0'] + $sec['h'] ) { $si = $i; break; }
			$sec = $secs[ $si ];
			$geo = [
				'x' => round( $it['x'] / $vw * 100, 3 ),
				'y' => round( ( $it['y'] - $sec['y0'] ) * $k, 3 ),
				'w' => round( $it['w'] / $vw * 100, 3 ),
				'h' => round( $it['h'] * $k, 3 ),
				'z' => ++$z,
			];
			$e = self::item( $it, $geo, $k, $h1 );
			if ( $e ) $secs[ $si ]['items'][] = $e;
		}

		$out = [];
		foreach ( $secs as $sec ) {
			$out[] = [ 'bg' => $sec['bg'], 'h' => round( $sec['h'] * $k, 3 ), 'items' => $sec['items'], 'elements' => [] ];
		}
		return [ 'title' => trim( $snap['title'] ?? '' ), 'exact' => true, 'fonts' => $snap['fonts'] ?? [], 'sections' => $out ];
	}

	private static function font( $it, $k ) {
		return [
			'family' => $it['font'] ?? '', 'size' => ( $it['size'] ?? 16 ) * $k, 'weight' => (string) ( $it['weight'] ?? 400 ),
			'style' => $it['fstyle'] ?? 'normal', 'transform' => $it['transform'] ?? 'none',
			'lh' => max( 0.1, ( $it['lh'] ?? ( ( $it['size'] ?? 16 ) * 1.2 ) ) * $k ), 'ls' => ( $it['ls'] ?? 0 ) * $k,
		];
	}

	private static function item( $it, $geo, $k, &$h1 ) {
		switch ( $it['type'] ) {
			case 'image':
				if ( empty( $it['src'] ) ) return null;
				return $geo + [ 'type' => 'image', 'src' => $it['src'], 'alt' => $it['alt'] ?? '', 'href' => $it['href'] ?? '', 'radius' => $it['radius'] ?? '', 'opacity' => $it['opacity'] ?? 1 ];
			case 'shape':
				return $geo + [ 'type' => 'shape', 'bg' => $it['bg'] ?? '', 'grad' => $it['grad'] ?? '', 'radius' => $it['radius'] ?? '', 'bw' => ( $it['bw'] ?? 0 ) * $k, 'bstyle' => $it['bstyle'] ?? 'solid', 'bcolor' => $it['bcolor'] ?? '', 'shadow' => $it['shadow'] ?? '', 'opacity' => $it['opacity'] ?? 1 ];
			case 'button':
				$t = trim( preg_replace( '/\s+/u', ' ', $it['text'] ?? '' ) );
				if ( $t === '' ) return null;
				return $geo + [ 'type' => 'button', 'text' => $t, 'href' => ! empty( $it['href'] ) ? $it['href'] : '#', 'bg' => $it['bgRaw'] ?? ( $it['bg'] ?? '' ), 'color' => $it['colorRaw'] ?? '', 'radius' => $it['radius'] ?? '', 'bw' => ( $it['bw'] ?? 0 ) * $k, 'bcolor' => $it['bcolor'] ?? '', 'font' => self::font( $it, $k ) ];
			case 'text':
				$html  = (string) ( $it['html'] ?? '' );
				$plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $html ) ) ) );
				if ( $plain === '' ) return null;
				$html = trim( wp_kses( $html, [ 'a' => [ 'href' => true ], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [] ] ) );
				if ( ! empty( $it['href'] ) && stripos( $html, '<a' ) === false ) $html = '<a href="' . esc_url( $it['href'] ) . '">' . $html . '</a>';
				$size = (float) ( $it['size'] ?? 16 );
				$tag  = 'p';
				if ( $size >= 44 ) { $tag = $h1 ? 'h2' : 'h1'; $h1 = true; } elseif ( $size >= 30 ) $tag = 'h2'; elseif ( $size >= 22 ) $tag = 'h3';
				$geo['w'] = round( min( 100 - $geo['x'], $geo['w'] + 0.6 ), 3 );
				return $geo + [ 'type' => 'text', 'html' => $html, 'tag' => $tag, 'color' => $it['colorRaw'] ?? '', 'align' => ( $it['align'] ?? '' ) ?: 'left', 'font' => self::font( $it, $k ) ];
		}
		return null;
	}
}
