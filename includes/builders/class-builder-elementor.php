<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Builder_Elementor extends WPIFY_Builder {

	public function build( array $page, $post_id ) {
		$data = [];
		$exact = ! empty( $page['exact'] );
		foreach ( $page['sections'] as $s ) $data[] = $exact ? $this->exact_section( $s ) : $this->section( $s );

		update_post_meta( $post_id, '_elementor_data', wp_slash( self::json( $data ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		update_post_meta( $post_id, '_wp_page_template', ! empty( $page['canvas'] ) ? 'elementor_canvas' : 'default' );

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/* ================= EXACT MODE ================= */

	private function vw( $n ) { return [ 'unit' => 'vw', 'size' => round( (float) $n, 3 ) ]; }

	private function exact_section( $s ) {
		$set = [
			'content_width'  => 'full',
			'flex_direction' => 'column',
			'min_height'     => $this->vw( $s['h'] ),
			'overflow'       => 'hidden',
			'padding'        => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ],
		];
		if ( ! empty( $s['bg']['color'] ) ) { $set['background_background'] = 'classic'; $set['background_color'] = $s['bg']['color']; }
		if ( ! empty( $s['bg']['image'] ) ) {
			$set['background_background'] = 'classic';
			$set['background_image']      = [ 'url' => $s['bg']['image'], 'id' => (int) ( $s['bg']['image_id'] ?? 0 ) ];
			$set['background_position']   = 'center center';
			$set['background_size']       = 'cover';
			$set['background_repeat']     = 'no-repeat';
		}
		$kids = [];
		foreach ( $s['items'] as $it ) { $w = $this->abs_widget( $it ); if ( $w ) $kids[] = $w; }
		return [ 'id' => self::rid( 7 ), 'elType' => 'container', 'settings' => $set, 'elements' => $kids, 'isInner' => false ];
	}

	private function common( $it ) {
		return [
			'_position'               => 'absolute',
			'_offset_orientation_h'   => 'start',
			'_offset_x'               => [ 'unit' => '%', 'size' => $it['x'] ],
			'_offset_orientation_v'   => 'start',
			'_offset_y'               => $this->vw( $it['y'] ),
			'_element_width'          => 'initial',
			'_element_custom_width'   => [ 'unit' => '%', 'size' => $it['w'] ],
			'_z_index'                => $it['z'],
		];
	}

	private function typo( $f ) {
		$t = [
			'typography_typography'     => 'custom',
			'typography_font_family'    => $f['family'],
			'typography_font_size'      => $this->vw( $f['size'] ),
			'typography_font_weight'    => (string) $f['weight'],
			'typography_line_height'    => $this->vw( $f['lh'] ),
			'typography_letter_spacing' => $this->vw( $f['ls'] ),
		];
		if ( $f['style'] === 'italic' ) $t['typography_font_style'] = 'italic';
		if ( ! empty( $f['transform'] ) && $f['transform'] !== 'none' ) $t['typography_text_transform'] = $f['transform'];
		return $t;
	}

	/** "12px 12px 0px 0px" / "50%" -> Elementor dimensions array (vw or %). */
	private function radius( $str, $k = null ) {
		$tok = preg_split( '/\s+/', trim( (string) $str ) );
		if ( count( $tok ) < 4 ) $tok = array_pad( $tok, 4, $tok[0] ?? '0px' );
		$pct = strpos( $tok[0], '%' ) !== false;
		$vals = [];
		foreach ( array_slice( $tok, 0, 4 ) as $t ) $vals[] = $pct ? (string) round( (float) $t, 2 ) : (string) round( (float) $t * ( 100 / 1440 ), 3 );
		return [ 'unit' => $pct ? '%' : 'vw', 'top' => $vals[0], 'right' => $vals[1], 'bottom' => $vals[2], 'left' => $vals[3], 'isLinked' => false ];
	}

	private function abs_widget( $it ) {
		$w = fn( $type, $settings ) => [ 'id' => self::rid( 7 ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings + $this->common( $it ), 'elements' => [] ];
		switch ( $it['type'] ) {
			case 'text':
				$s = [ 'title' => $it['html'], 'header_size' => $it['tag'], 'align' => $it['align'], 'title_color' => $it['color'] ] + $this->typo( $it['font'] );
				return $w( 'heading', $s );

			case 'image':
				$s = [
					'image' => [ 'url' => $it['src'], 'id' => (int) ( $it['id'] ?? 0 ) ], 'image_size' => 'full',
					'width' => [ 'unit' => '%', 'size' => 100 ], 'height' => $this->vw( $it['h'] ), 'object-fit' => 'cover',
				];
				if ( ! empty( $it['radius'] ) && (float) $it['radius'] > 0 ) $s['image_border_radius'] = $this->radius( $it['radius'] );
				if ( ! empty( $it['href'] ) ) { $s['link_to'] = 'custom'; $s['link'] = [ 'url' => $it['href'], 'is_external' => '', 'nofollow' => '' ]; }
				return $w( 'image', $s );

			case 'shape':
				$st = [ 'box-sizing:border-box', 'width:100%', 'height:' . $it['h'] . 'vw' ];
				if ( $it['bg'] )    $st[] = 'background-color:' . $it['bg'];
				if ( $it['grad'] )  $st[] = 'background-image:' . $it['grad'];
				if ( $it['radius'] ) {
					$r = $this->radius( $it['radius'] );
					$st[] = 'border-radius:' . implode( ' ', [ $r['top'] . $r['unit'], $r['right'] . $r['unit'], $r['bottom'] . $r['unit'], $r['left'] . $r['unit'] ] );
				}
				if ( $it['bw'] > 0 && $it['bcolor'] ) $st[] = 'border:' . round( $it['bw'], 3 ) . 'vw ' . ( $it['bstyle'] ?: 'solid' ) . ' ' . $it['bcolor'];
				if ( $it['shadow'] ) $st[] = 'box-shadow:' . $it['shadow'];
				if ( isset( $it['opacity'] ) && (float) $it['opacity'] < 1 ) $st[] = 'opacity:' . (float) $it['opacity'];
				return $w( 'html', [ 'html' => '<div style="' . esc_attr( implode( ';', $st ) ) . '"></div>' ] );

			case 'button':
				$pv = max( 0, ( $it['h'] - $it['font']['lh'] ) / 2 );
				$s = [
					'text' => $it['text'], 'link' => [ 'url' => $it['href'], 'is_external' => '', 'nofollow' => '' ], 'align' => 'justify',
					'button_text_color' => $it['color'], 'background_color' => $it['bg'],
					'text_padding' => [ 'unit' => 'vw', 'top' => (string) round( $pv, 3 ), 'right' => '0', 'bottom' => (string) round( $pv, 3 ), 'left' => '0', 'isLinked' => false ],
					'border_radius' => $this->radius( $it['radius'] ),
				] + $this->typo( $it['font'] );
				if ( $it['bw'] > 0 && $it['bcolor'] ) {
					$b = (string) round( $it['bw'], 3 );
					$s['border_border'] = 'solid';
					$s['border_width']  = [ 'unit' => 'vw', 'top' => $b, 'right' => $b, 'bottom' => $b, 'left' => $b, 'isLinked' => true ];
					$s['border_color']  = $it['bcolor'];
				}
				return $w( 'button', $s );
		}
		return null;
	}

	/* ================= FLOW MODE ================= */

	private function section( $s ) {
		$set = [
			'content_width'  => 'boxed',
			'flex_direction' => 'column',
			'padding'        => [ 'unit' => 'px', 'top' => '60', 'right' => '20', 'bottom' => '60', 'left' => '20', 'isLinked' => false ],
		];
		if ( ! empty( $s['bg']['color'] ) ) { $set['background_background'] = 'classic'; $set['background_color'] = $s['bg']['color']; }
		if ( ! empty( $s['bg']['image'] ) ) {
			$set['background_background'] = 'classic';
			$set['background_image']      = [ 'url' => $s['bg']['image'], 'id' => (int) ( $s['bg']['image_id'] ?? 0 ) ];
			$set['background_position']   = 'center center';
			$set['background_size']       = 'cover';
		}
		$kids = [];
		foreach ( $s['elements'] as $e ) { $w = $this->widget( $e ); if ( $w ) $kids[] = $w; }
		return [ 'id' => self::rid( 7 ), 'elType' => 'container', 'settings' => $set, 'elements' => $kids, 'isInner' => false ];
	}

	private function widget( $e ) {
		$w = fn( $type, $settings ) => [ 'id' => self::rid( 7 ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ];
		switch ( $e['type'] ) {
			case 'row':
				$cols = [];
				foreach ( $e['cols'] as $i => $col ) {
					$kids = [];
					foreach ( $col as $ce ) { $x = $this->widget( $ce ); if ( $x ) $kids[] = $x; }
					$cols[] = [ 'id' => self::rid( 7 ), 'elType' => 'container', 'settings' => [ 'content_width' => 'full', 'flex_direction' => 'column', 'width' => [ 'unit' => '%', 'size' => round( $e['widths'][ $i ], 2 ) ] ], 'elements' => $kids, 'isInner' => true ];
				}
				return [ 'id' => self::rid( 7 ), 'elType' => 'container', 'settings' => [ 'content_width' => 'full', 'flex_direction' => 'row' ], 'elements' => $cols, 'isInner' => true ];
			case 'heading':
				$s = [ 'title' => $e['text'], 'header_size' => 'h' . $e['level'] ];
				if ( $e['align'] ) $s['align'] = $e['align'];
				if ( $e['color'] ) $s['title_color'] = $e['color'];
				return $w( 'heading', $s );
			case 'text':
				$s = [ 'editor' => '<p>' . $e['html'] . '</p>' ];
				if ( $e['align'] ) $s['align'] = $e['align'];
				if ( $e['color'] ) $s['text_color'] = $e['color'];
				return $w( 'text-editor', $s );
			case 'list':
				$t = $e['ordered'] ? 'ol' : 'ul';
				return $w( 'text-editor', [ 'editor' => '<' . $t . '><li>' . implode( '</li><li>', $e['items'] ) . '</li></' . $t . '>' ] );
			case 'image':
				$s = [ 'image' => [ 'url' => $e['src'], 'id' => (int) ( $e['id'] ?? 0 ) ], 'image_size' => 'full', 'align' => 'center' ];
				if ( ! empty( $e['href'] ) ) { $s['link_to'] = 'custom'; $s['link'] = [ 'url' => $e['href'], 'is_external' => '', 'nofollow' => '' ]; }
				return $w( 'image', $s );
			case 'button':
				$s = [ 'text' => $e['text'], 'link' => [ 'url' => $e['href'], 'is_external' => '', 'nofollow' => '' ], 'align' => $e['align'] ?: 'center' ];
				if ( $e['bg'] )    $s['background_color']  = $e['bg'];
				if ( $e['color'] ) $s['button_text_color'] = $e['color'];
				return $w( 'button', $s );
		}
		return null;
	}
}
