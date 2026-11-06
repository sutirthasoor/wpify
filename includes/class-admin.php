<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIFY_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_wpify_scan', [ __CLASS__, 'ajax_scan' ] );
		add_action( 'wp_ajax_wpify_import', [ __CLASS__, 'ajax_import' ] );
		add_action( 'wp_ajax_wpify_save', [ __CLASS__, 'ajax_save' ] );
	}

	public static function menu() {
		add_menu_page( 'WPIFY', 'WPIFY', 'manage_options', 'wpify', [ __CLASS__, 'render' ], 'dashicons-download', 58 );
	}

	public static function assets( $hook ) {
		if ( $hook !== 'toplevel_page_wpify' ) return;
		wp_enqueue_style( 'wpify', WPIFY_URL . 'assets/admin.css', [], WPIFY_VERSION );
		wp_enqueue_script( 'wpify', WPIFY_URL . 'assets/admin.js', [], WPIFY_VERSION, true );
		wp_localize_script( 'wpify', 'WPIFY', [ 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'wpify' ) ] );
	}

	private static function guard() {
		check_ajax_referer( 'wpify', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Not allowed.' ], 403 );
	}

	public static function ajax_scan() {
		self::guard();
		$url = esc_url_raw( trim( wp_unslash( $_POST['url'] ?? '' ) ) );
		if ( ! $url ) wp_send_json_error( [ 'message' => 'Paste a Canva link.' ] );
		if ( WPIFY_Renderer::configured() ) {
			$snap = WPIFY_Renderer::snapshot( $url );
			if ( is_wp_error( $snap ) ) wp_send_json_error( [ 'message' => $snap->get_error_message() ] );
			wp_send_json_success( [ 'pages' => WPIFY_Fetcher::discover_links( $snap['links'] ?? [], $url, $snap['title'] ?? '' ), 'design' => false, 'shell' => false ] );
		}
		$res = WPIFY_Fetcher::get( $url );
		if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		wp_send_json_success( [
			'pages'  => WPIFY_Fetcher::discover( $res['html'], $res['url'] ),
			'design' => (bool) WPIFY_Fetcher::is_design_link( $res['url'] ),
			'shell'  => ! WPIFY_Fetcher::is_design_link( $res['url'] ) && WPIFY_Fetcher::is_empty_shell( $res['html'] ),
		] );
	}

	public static function ajax_save() {
		self::guard();
		update_option( 'wpify_renderer_url', esc_url_raw( trim( wp_unslash( $_POST['renderer_url'] ?? '' ) ) ) );
		$t = sanitize_text_field( wp_unslash( $_POST['renderer_token'] ?? '' ) );
		if ( $t !== '' ) update_option( 'wpify_renderer_token', $t );
		wp_send_json_success( [ 'configured' => WPIFY_Renderer::configured() ] );
	}

	public static function ajax_import() {
		self::guard();
		@set_time_limit( 180 );
		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$label   = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$builder = sanitize_key( $_POST['builder'] ?? 'gutenberg' );
		$status  = ( $_POST['status'] ?? 'draft' ) === 'publish' ? 'publish' : 'draft';
		$side    = ! empty( $_POST['sideload'] ) && $_POST['sideload'] !== '0';
		$opts = [ 'exact' => ! empty( $_POST['exact'] ) && $_POST['exact'] !== '0', 'canvas' => ! empty( $_POST['canvas'] ) && $_POST['canvas'] !== '0' ];
		$r = WPIFY_Importer::import_page( $url, $label, $builder, $status, $side, $opts );
		if ( is_wp_error( $r ) ) wp_send_json_error( [ 'message' => $r->get_error_message() ] );
		wp_send_json_success( $r );
	}

	public static function render() {
		$el = defined( 'ELEMENTOR_VERSION' );
		$br = defined( 'BRICKS_VERSION' ) || get_template() === 'bricks';
		?>
		<div class="wrap wpify">
			<h1>WPIFY <span class="wpify-by">by Systems Solution</span></h1>
			<p>Canva &rarr; editable WordPress pages.</p>

			<div class="wpify-card">
				<h2>Renderer</h2>
				<p>Canva Websites are drawn by JavaScript, so a headless-browser renderer is required (see <code>renderer/README</code> in the plugin zip).</p>
				<p><input type="url" id="wpify-r-url" class="large-text" placeholder="https://your-renderer.example.com" value="<?php echo esc_attr( WPIFY_Renderer::url() ); ?>"></p>
				<p><input type="password" id="wpify-r-token" class="regular-text" placeholder="<?php echo get_option( 'wpify_renderer_token' ) ? 'Token saved (leave blank to keep)' : 'Token'; ?>" autocomplete="new-password">
				<button class="button" id="wpify-r-save">Save</button> <span id="wpify-r-msg"></span></p>
			</div>

			<div class="wpify-card">
				<h2>1. Canva link</h2>
				<input type="url" id="wpify-url" class="large-text" placeholder="https://yourname.my.canva.site/  (published Canva Website)">
				<p><button class="button button-primary" id="wpify-scan">Scan pages</button> <span id="wpify-scan-msg"></span></p>
			</div>

			<div class="wpify-card" id="wpify-step2" style="display:none">
				<h2>2. Pages to import</h2>
				<p>
					<label>Import first <input type="number" id="wpify-n" min="1" value="1" style="width:70px"> pages</label>
					<button class="button" id="wpify-firstn">Apply</button>
					<button class="button" id="wpify-all">Select all</button>
					<button class="button" id="wpify-none">Clear</button>
				</p>
				<div id="wpify-pages"></div>

				<h2>3. Editor</h2>
				<p class="wpify-builders">
					<label><input type="radio" name="wpify-builder" value="gutenberg" checked> Gutenberg</label>
					<label><input type="radio" name="wpify-builder" value="elementor"> Elementor <?php echo $el ? '' : '<em>(not detected)</em>'; ?></label>
					<label><input type="radio" name="wpify-builder" value="bricks"> Bricks <?php echo $br ? '' : '<em>(not detected)</em>'; ?></label>
				</p>
				<p>
					<label><input type="checkbox" id="wpify-side" checked> Import images to Media Library</label><br>
					<label><input type="checkbox" id="wpify-exact" checked> Exact replica (Elementor): absolute positions, sizes, fonts, shapes</label><br>
					<label><input type="checkbox" id="wpify-canvas" checked> Use blank canvas template (hides theme header/footer)</label><br>
					<label>Create as
						<select id="wpify-status"><option value="draft">Draft</option><option value="publish">Published</option></select>
					</label>
				</p>
				<p><button class="button button-primary button-hero" id="wpify-import">Import</button></p>
				<ul id="wpify-log"></ul>
			</div>
		</div>
		<?php
	}
}
