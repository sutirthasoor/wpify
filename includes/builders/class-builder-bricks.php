<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Builder_Bricks extends WPIFY_Builder {

	private $els = [];

	public function build( array $page, $post_id ) {
		$this->els = [];
		foreach ( $page['sections'] as $s ) $this->section( $s );
		update_post_meta( $post_id, '_bricks_page_content_2', wp_slash( $this->els ) );
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );
	}

	private function section( $s ) {
		$sid = self::rid( 6 );
		$cid = self::rid( 6 );
		$set = [ '_padding' => [ 'top' => '60', 'bottom' => '60' ] ];
		$bg  = [];
		if ( ! empty( $s['bg']['color'] ) ) $bg['color'] = [ 'hex' => $s['bg']['color'] ];
		if ( ! empty( $s['bg']['image'] ) ) {
			$bg['image']    = [ 'url' => $s['bg']['image'], 'id' => (int) ( $s['bg']['image_id'] ?? 0 ), 'size' => 'full' ];
			$bg['size']     = 'cover';
			$bg['position'] = 'center center';
		}
		if ( $bg ) $set['_background'] = $bg;

		$this->els[] = [ 'id' => $sid, 'name' => 'section', 'parent' => 0, 'children' => [ $cid ], 'settings' => $set ];
		$cidx = count( $this->els );
		$this->els[] = [ 'id' => $cid, 'name' => 'container', 'parent' => $sid, 'children' => [], 'settings' => [ '_direction' => 'column', '_alignItems' => 'center', '_rowGap' => '20' ] ];
		foreach ( $s['elements'] as $e ) $this->add( $e, $cid, $cidx );
	}

	private function add( $e, $parent_id, $parent_idx ) {
		if ( $e['type'] === 'row' ) {
			$rid = self::rid( 6 );
			$ridx = count( $this->els );
			$this->els[] = [ 'id' => $rid, 'name' => 'container', 'parent' => $parent_id, 'children' => [], 'settings' => [ '_direction' => 'row', '_columnGap' => '20', '_width' => '100%' ] ];
			$this->els[ $parent_idx ]['children'][] = $rid;
			foreach ( $e['cols'] as $i => $col ) {
				$cid = self::rid( 6 );
				$cidx = count( $this->els );
				$this->els[] = [ 'id' => $cid, 'name' => 'container', 'parent' => $rid, 'children' => [], 'settings' => [ '_direction' => 'column', '_width' => round( $e['widths'][ $i ], 2 ) . '%' ] ];
				$this->els[ $ridx ]['children'][] = $cid;
				foreach ( $col as $ce ) $this->add( $ce, $cid, $cidx );
			}
			return;
		}
		$el = $this->widget( $e, $parent_id );
		if ( $el ) { $this->els[] = $el; $this->els[ $parent_idx ]['children'][] = $el['id']; }
	}

	private function typo( $e ) {
		$t = [];
		if ( ! empty( $e['color'] ) ) $t['color'] = [ 'hex' => $e['color'] ];
		if ( ! empty( $e['align'] ) ) $t['text-align'] = $e['align'];
		return $t ? [ '_typography' => $t ] : [];
	}

	private function widget( $e, $parent ) {
		$base = [ 'id' => self::rid( 6 ), 'parent' => $parent, 'children' => [] ];
		switch ( $e['type'] ) {
			case 'heading':
				return $base + [ 'name' => 'heading', 'settings' => [ 'text' => $e['text'], 'tag' => 'h' . $e['level'] ] + $this->typo( $e ) ];
			case 'text':
				return $base + [ 'name' => 'text', 'settings' => [ 'text' => '<p>' . $e['html'] . '</p>' ] + $this->typo( $e ) ];
			case 'list':
				$t = $e['ordered'] ? 'ol' : 'ul';
				return $base + [ 'name' => 'text', 'settings' => [ 'text' => '<' . $t . '><li>' . implode( '</li><li>', $e['items'] ) . '</li></' . $t . '>' ] ];
			case 'image':
				$s = [ 'image' => [ 'url' => $e['src'], 'id' => (int) ( $e['id'] ?? 0 ), 'size' => 'full' ] ];
				if ( ! empty( $e['href'] ) ) { $s['link'] = 'external'; $s['url'] = $e['href']; }
				return $base + [ 'name' => 'image', 'settings' => $s ];
			case 'button':
				$s = [ 'text' => $e['text'], 'link' => [ 'type' => 'external', 'url' => $e['href'] ], 'style' => 'primary' ];
				if ( $e['bg'] )    $s['_background'] = [ 'color' => [ 'hex' => $e['bg'] ] ];
				if ( $e['color'] ) $s['_typography'] = [ 'color' => [ 'hex' => $e['color'] ] ];
				return $base + [ 'name' => 'button', 'settings' => $s ];
		}
		return null;
	}
}
