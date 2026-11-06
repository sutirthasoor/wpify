<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Builder_Gutenberg extends WPIFY_Builder {

	public function build( array $page, $post_id ) {
		$out = '';
		foreach ( $page['sections'] as $s ) $out .= $this->section( $s );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => wp_slash( $out ) ] );
	}

	private function section( $s ) {
		$inner = '';
		foreach ( $s['elements'] as $e ) $inner .= $this->el( $e );
		$bg = $s['bg'];
		if ( ! empty( $bg['image'] ) ) {
			$attrs = [ 'url' => $bg['image'], 'dimRatio' => 0, 'isDark' => false, 'layout' => [ 'type' => 'constrained' ] ];
			if ( ! empty( $bg['image_id'] ) ) $attrs['id'] = (int) $bg['image_id'];
			return '<!-- wp:cover ' . self::json( $attrs ) . ' --><div class="wp-block-cover is-light"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><img class="wp-block-cover__image-background' . ( ! empty( $bg['image_id'] ) ? ' wp-image-' . (int) $bg['image_id'] : '' ) . '" alt="" src="' . esc_url( $bg['image'] ) . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container">' . $inner . '</div></div><!-- /wp:cover -->' . "\n";
		}
		$attrs = [ 'layout' => [ 'type' => 'constrained' ] ];
		$cls   = 'wp-block-group';
		$style = '';
		if ( ! empty( $bg['color'] ) ) {
			$attrs['style']['color']['background'] = $bg['color'];
			$cls  .= ' has-background';
			$style = 'background-color:' . $bg['color'];
		}
		return '<!-- wp:group ' . self::json( $attrs ) . ' --><div class="' . $cls . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . $inner . '</div><!-- /wp:group -->' . "\n";
	}

	private function el( $e ) {
		switch ( $e['type'] ) {
			case 'row':
				$cols = '';
				foreach ( $e['cols'] as $i => $col ) {
					$w = round( $e['widths'][ $i ], 2 ) . '%';
					$in = '';
					foreach ( $col as $ce ) $in .= $this->el( $ce );
					$cols .= '<!-- wp:column {"width":"' . $w . '"} --><div class="wp-block-column" style="flex-basis:' . $w . '">' . $in . '</div><!-- /wp:column -->';
				}
				return '<!-- wp:columns --><div class="wp-block-columns">' . $cols . '</div><!-- /wp:columns -->' . "\n";
			case 'heading':
				$a = [ 'level' => $e['level'] ]; $c = 'wp-block-heading'; $st = '';
				if ( $e['align'] ) { $a['textAlign'] = $e['align']; $c .= ' has-text-align-' . $e['align']; }
				if ( $e['color'] ) { $a['style']['color']['text'] = $e['color']; $c .= ' has-text-color'; $st = ' style="color:' . esc_attr( $e['color'] ) . '"'; }
				return '<!-- wp:heading ' . self::json( $a ) . ' --><h' . $e['level'] . ' class="' . $c . '"' . $st . '>' . esc_html( $e['text'] ) . '</h' . $e['level'] . '><!-- /wp:heading -->' . "\n";
			case 'text':
				$a = []; $c = []; $st = '';
				if ( $e['align'] ) { $a['align'] = $e['align']; $c[] = 'has-text-align-' . $e['align']; }
				if ( $e['color'] ) { $a['style']['color']['text'] = $e['color']; $c[] = 'has-text-color'; $st = ' style="color:' . esc_attr( $e['color'] ) . '"'; }
				return '<!-- wp:paragraph' . ( $a ? ' ' . self::json( $a ) : '' ) . ' --><p' . ( $c ? ' class="' . implode( ' ', $c ) . '"' : '' ) . $st . '>' . $e['html'] . '</p><!-- /wp:paragraph -->' . "\n";
			case 'list':
				$tag = $e['ordered'] ? 'ol' : 'ul';
				$li  = '';
				foreach ( $e['items'] as $i ) $li .= '<!-- wp:list-item --><li>' . $i . '</li><!-- /wp:list-item -->';
				return '<!-- wp:list' . ( $e['ordered'] ? ' {"ordered":true}' : '' ) . ' --><' . $tag . ' class="wp-block-list">' . $li . '</' . $tag . '><!-- /wp:list -->' . "\n";
			case 'image':
				$a = [ 'sizeSlug' => 'full', 'linkDestination' => empty( $e['href'] ) ? 'none' : 'custom' ];
				if ( ! empty( $e['id'] ) ) $a['id'] = (int) $e['id'];
				$img = '<img src="' . esc_url( $e['src'] ) . '" alt="' . esc_attr( $e['alt'] ?? '' ) . '"' . ( ! empty( $e['id'] ) ? ' class="wp-image-' . (int) $e['id'] . '"' : '' ) . '/>';
				if ( ! empty( $e['href'] ) ) $img = '<a href="' . esc_url( $e['href'] ) . '">' . $img . '</a>';
				return '<!-- wp:image ' . self::json( $a ) . ' --><figure class="wp-block-image size-full">' . $img . '</figure><!-- /wp:image -->' . "\n";
			case 'button':
				$a = []; $c = 'wp-block-button__link'; $st = [];
				if ( $e['bg'] )    { $a['style']['color']['background'] = $e['bg'];    $c .= ' has-background'; $st[] = 'background-color:' . $e['bg']; }
				if ( $e['color'] ) { $a['style']['color']['text']       = $e['color']; $c .= ' has-text-color'; array_unshift( $st, 'color:' . $e['color'] ); }
				$c .= ' wp-element-button';
				$btn = '<!-- wp:button' . ( $a ? ' ' . self::json( $a ) : '' ) . ' --><div class="wp-block-button"><a class="' . $c . '" href="' . esc_url( $e['href'] ) . '"' . ( $st ? ' style="' . esc_attr( implode( ';', $st ) ) . '"' : '' ) . '>' . esc_html( $e['text'] ) . '</a></div><!-- /wp:button -->';
				return '<!-- wp:buttons --><div class="wp-block-buttons">' . $btn . '</div><!-- /wp:buttons -->' . "\n";
		}
		return '';
	}
}
