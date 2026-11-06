<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Renderer snapshot (positioned blocks) -> neutral model with sections, stacked elements and multi-column rows. */
class WPIFY_Snapshot {

	public static function to_model( $snap, $base ) {
		$vw   = max( 320, (float) ( $snap['vw'] ?? 1440 ) );
		$docH = max( 1, (float) ( $snap['docH'] ?? 1 ) );

		$blocks = array_values( array_filter( $snap['blocks'] ?? [], function ( $b ) { return isset( $b['type'], $b['x'], $b['y'], $b['w'], $b['h'] ); } ) );
		usort( $blocks, function ( $a, $b ) { return [ round( $a['y'] / 8 ), $a['x'] ] <=> [ round( $b['y'] / 8 ), $b['x'] ]; } );
		$bands = self::bands( $snap['bands'] ?? [] );

		// 1. group blocks into sections by background band / vertical gaps
		$secs = []; $cur = null; $bottom = 0;
		foreach ( $blocks as $b ) {
			$bi  = self::band_for( $bands, $b['y'] + $b['h'] / 2 );
			$key = $bi === null ? 'n' : $bi;
			if ( $cur === null || $cur['key'] !== $key || ( $key === 'n' && $b['y'] - $bottom > 100 ) ) {
				if ( $cur ) $secs[] = $cur;
				$cur    = [ 'key' => $key, 'bg' => $bi === null ? [] : [ 'color' => $bands[ $bi ]['color'] ?? '', 'image' => $bands[ $bi ]['image'] ?? '' ], 'blocks' => [] ];
				$bottom = 0;
			}
			$cur['blocks'][] = $b;
			$bottom = max( $bottom, $b['y'] + $b['h'] );
		}
		if ( $cur ) $secs[] = $cur;

		// 2. per section: wide image behind content -> background; then rows/columns
		$h1 = false;
		$out = [];
		foreach ( $secs as $sec ) {
			$bl = $sec['blocks'];
			foreach ( $bl as $i => $b ) {
				if ( $b['type'] !== 'image' || $b['w'] < $vw * 0.8 ) continue;
				$n = 0;
				foreach ( $bl as $o ) {
					if ( $o['type'] === 'image' ) continue;
					$cx = $o['x'] + $o['w'] / 2; $cy = $o['y'] + $o['h'] / 2;
					if ( $cx >= $b['x'] && $cx <= $b['x'] + $b['w'] && $cy >= $b['y'] && $cy <= $b['y'] + $b['h'] ) $n++;
				}
				if ( $n >= 1 && empty( $sec['bg']['image'] ) ) { $sec['bg']['image'] = $b['src']; unset( $bl[ $i ] ); }
			}
			$els = self::layout( array_values( $bl ), $vw, $h1 );
			$bg  = [ 'color' => $sec['bg']['color'] ?? '', 'image' => $sec['bg']['image'] ?? '' ];
			if ( $els || $bg['image'] ) $out[] = [ 'bg' => $bg, 'elements' => $els ];
		}
		return [ 'title' => trim( $snap['title'] ?? '' ), 'sections' => $out ];
	}

	private static function bands( $raw ) {
		$out = []; $seen = [];
		foreach ( $raw as $b ) {
			if ( ! isset( $b['y'], $b['h'] ) ) continue;
			$k = round( $b['y'] / 20 ) . '-' . round( $b['h'] / 20 );
			if ( isset( $seen[ $k ] ) ) continue;
			$seen[ $k ] = 1;
			$out[] = $b;
		}
		usort( $out, function ( $a, $b ) { return $a['y'] <=> $b['y']; } );
		return $out;
	}

	private static function band_for( $bands, $cy ) {
		$best = null; $bh = INF;
		foreach ( $bands as $i => $b ) if ( $cy >= $b['y'] && $cy <= $b['y'] + $b['h'] && $b['h'] < $bh ) { $best = $i; $bh = $b['h']; }
		return $best;
	}

	private static function layout( $blocks, $vw, &$h1 ) {
		usort( $blocks, function ( $a, $b ) { return $a['y'] <=> $b['y']; } );
		$rows = [];
		foreach ( $blocks as $b ) {
			$n = count( $rows );
			if ( $n ) {
				$r  = &$rows[ $n - 1 ];
				$ov = min( $b['y'] + $b['h'], $r['bottom'] ) - max( $b['y'], $r['top'] );
				if ( $ov > 0.5 * min( $b['h'], $r['bottom'] - $r['top'] ) ) {
					$r['items'][] = $b; $r['top'] = min( $r['top'], $b['y'] ); $r['bottom'] = max( $r['bottom'], $b['y'] + $b['h'] );
					unset( $r );
					continue;
				}
				unset( $r );
			}
			$rows[] = [ 'top' => $b['y'], 'bottom' => $b['y'] + $b['h'], 'items' => [ $b ] ];
		}

		$els = [];
		foreach ( $rows as $row ) {
			$items = $row['items'];
			usort( $items, function ( $a, $b ) { return $a['x'] <=> $b['x']; } );
			$cols = [];
			foreach ( $items as $it ) {
				$placed = false;
				foreach ( $cols as &$c ) {
					$ov = min( $it['x'] + $it['w'], $c['x2'] ) - max( $it['x'], $c['x1'] );
					if ( $ov > 0.3 * min( $it['w'], $c['x2'] - $c['x1'] ) ) {
						$c['items'][] = $it; $c['x1'] = min( $c['x1'], $it['x'] ); $c['x2'] = max( $c['x2'], $it['x'] + $it['w'] );
						$placed = true; break;
					}
				}
				unset( $c );
				if ( ! $placed ) $cols[] = [ 'x1' => $it['x'], 'x2' => $it['x'] + $it['w'], 'items' => [ $it ] ];
			}
			if ( count( $cols ) === 1 ) {
				usort( $cols[0]['items'], function ( $a, $b ) { return $a['y'] <=> $b['y']; } );
				foreach ( $cols[0]['items'] as $it ) { $e = self::el( $it, $vw, $h1 ); if ( $e ) $els[] = $e; }
				continue;
			}
			$tot = 0; foreach ( $cols as $c ) $tot += max( 1, $c['x2'] - $c['x1'] );
			$spec = [ 'type' => 'row', 'cols' => [], 'widths' => [] ];
			foreach ( $cols as $c ) {
				usort( $c['items'], function ( $a, $b ) { return $a['y'] <=> $b['y']; } );
				$ce = [];
				foreach ( $c['items'] as $it ) { $e = self::el( $it, $vw, $h1 ); if ( $e ) $ce[] = $e; }
				if ( ! $ce ) continue;
				$spec['cols'][]   = $ce;
				$spec['widths'][] = max( 1, $c['x2'] - $c['x1'] ) / $tot * 100;
			}
			if ( count( $spec['cols'] ) > 1 ) $els[] = $spec;
			elseif ( $spec['cols'] ) foreach ( $spec['cols'][0] as $e ) $els[] = $e;
		}
		return $els;
	}

	private static function el( $b, $vw, &$h1 ) {
		$cx = $b['x'] + $b['w'] / 2;
		switch ( $b['type'] ) {
			case 'image':
				if ( empty( $b['src'] ) ) return null;
				return [ 'type' => 'image', 'src' => $b['src'], 'alt' => $b['alt'] ?? '', 'href' => $b['href'] ?? '' ];
			case 'button':
				$t = trim( preg_replace( '/\s+/u', ' ', $b['text'] ?? '' ) );
				if ( $t === '' ) return null;
				return [ 'type' => 'button', 'text' => $t, 'href' => ! empty( $b['href'] ) ? $b['href'] : '#', 'bg' => $b['bg'] ?? '', 'color' => $b['color'] ?? '', 'align' => '' ];
			case 'text':
				$html  = (string) ( $b['html'] ?? '' );
				$plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $html ) ) ) );
				if ( $plain === '' ) return null;
				$html = trim( wp_kses( $html, [ 'a' => [ 'href' => true ], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [] ] ) );
				if ( ! empty( $b['href'] ) && stripos( $html, '<a' ) === false ) $html = '<a href="' . esc_url( $b['href'] ) . '">' . $html . '</a>';
				$align = $b['align'] ?? '';
				if ( ! $align && abs( $cx - $vw / 2 ) < $vw * 0.06 && $b['w'] < $vw * 0.85 ) $align = 'center';
				$base = [ 'color' => $b['color'] ?? '', 'align' => $align ];
				$size = (float) ( $b['size'] ?? 16 );
				if ( $size >= 44 )      { $lvl = $h1 ? 2 : 1; $h1 = true; return $base + [ 'type' => 'heading', 'level' => $lvl, 'text' => $plain ]; }
				if ( $size >= 30 )      return $base + [ 'type' => 'heading', 'level' => 2, 'text' => $plain ];
				if ( $size >= 22 )      return $base + [ 'type' => 'heading', 'level' => 3, 'text' => $plain ];
				return $base + [ 'type' => 'text', 'html' => $html ];
		}
		return null;
	}
}
