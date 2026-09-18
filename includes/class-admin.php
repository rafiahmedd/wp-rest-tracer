<?php
/**
 * Admin UI: Tools → REST Tracer.
 *
 * The traces tab is a live list: admin.js renders the table client-side and
 * polls the plugin's REST API, so new traces stream in without a page reload.
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

final class REST_Tracer_Admin {

	const PAGE_SLUG = 'rest-tracer';

	/** @var REST_Tracer_Admin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'admin_post_rest_tracer_download', array( $this, 'handle_download' ) );
	}

	/* ---------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------- */

	public function register_menu() {
		add_management_page(
			__( 'REST API Tracer', 'rest-tracer' ),
			__( 'REST Tracer', 'rest-tracer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting(
			'rest_tracer',
			'rest_tracer_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = REST_Tracer_Plugin::default_settings();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$out['namespaces'] = sanitize_text_field( isset( $input['namespaces'] ) ? $input['namespaces'] : '' );

		$out['mode'] = ( isset( $input['mode'] ) && 'always' === $input['mode'] ) ? 'always' : 'header';

		$out['token'] = isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : $defaults['token'];
		if ( '' === $out['token'] ) {
			$out['token'] = wp_generate_password( 32, false, false );
		}

		foreach ( array( 'capture_hooks', 'capture_sql', 'capture_http' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['max_nodes']  = max( 500, min( 100000, isset( $input['max_nodes'] ) ? absint( $input['max_nodes'] ) : $defaults['max_nodes'] ) );
		$out['max_traces'] = max( 1, min( 500, isset( $input['max_traces'] ) ? absint( $input['max_traces'] ) : $defaults['max_traces'] ) );

		return $out;
	}

	/**
	 * Enqueue assets on the plugin's admin pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Version assets by file mtime: every deploy busts browser/proxy caches
		// (CDNs sometimes ignore ?ver= from plugin versions), so markup and JS
		// can never go out of sync.
		$css_ver = filemtime( REST_TRACER_DIR . 'assets/admin.css' );
		$js_ver  = filemtime( REST_TRACER_DIR . 'assets/admin.js' );

		wp_enqueue_style( 'rest-tracer-admin', REST_TRACER_URL . 'assets/admin.css', array(), $css_ver ? (string) $css_ver : REST_TRACER_VERSION );
		wp_enqueue_script( 'rest-tracer-admin', REST_TRACER_URL . 'assets/admin.js', array( 'wp-api-fetch' ), $js_ver ? (string) $js_ver : REST_TRACER_VERSION, true );

		$settings   = REST_Tracer_Plugin::get_settings();
		$watched    = rest_tracer_watched_namespaces();
		$sample_ns  = $watched ? $watched[0] : 'myshop/v1';

		wp_add_inline_script(
			'rest-tracer-admin',
			'window.REST_TRACER_ADMIN = ' . wp_json_encode(
				array(
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'root'      => esc_url_raw( rest_url() ),
					'token'     => (string) $settings['token'],
					'mode'      => (string) $settings['mode'],
					'watched'   => $watched,
					'demoUrl'   => rest_url( 'rest-tracer-demo/v1/report' ),
					'apiUrl'    => rest_url( 'rest-tracer/v1/traces' ),
					'sampleUrl' => rest_url( $sample_ns . '/your-endpoint' ),
					'perPage'   => 25,
					'prefs'     => REST_Tracer_Rest_Api::get_user_prefs(),
				)
			) . ';',
			'before'
		);
	}

	/* ---------------------------------------------------------------------
	 * Page router
	 * ------------------------------------------------------------------- */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rest-tracer' ) );
		}

		$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $view_id ) {
			$this->render_view( $view_id );
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'traces'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap rt-wrap">';
		echo '<h1 class="rt-title">REST API Tracer</h1>';
		echo '<nav class="nav-tab-wrapper">';
		$base = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		printf(
			'<a href="%s" class="nav-tab %s">Traces</a>',
			esc_url( $base ),
			( 'traces' === $tab ) ? 'nav-tab-active' : ''
		);
		printf(
			'<a href="%s" class="nav-tab %s">Endpoints</a>',
			esc_url( add_query_arg( 'tab', 'endpoints', $base ) ),
			( 'endpoints' === $tab ) ? 'nav-tab-active' : ''
		);
		printf(
			'<a href="%s" class="nav-tab %s">Settings</a>',
			esc_url( add_query_arg( 'tab', 'settings', $base ) ),
			( 'settings' === $tab ) ? 'nav-tab-active' : ''
		);
		echo '</nav>';

		if ( 'settings' === $tab ) {
			$this->render_settings();
		} elseif ( 'endpoints' === $tab ) {
			$this->render_endpoints();
		} else {
			$this->render_traces();
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Traces — live list shell (rows are rendered by admin.js)
	 * ------------------------------------------------------------------- */

	private function render_traces() {
		$settings = REST_Tracer_Plugin::get_settings();
		$watched  = rest_tracer_watched_namespaces();
		$settings_url = admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&tab=settings' );

		// Live status bar.
		echo '<div class="rt-live-bar">';
		echo '<span id="rt-live-pill" class="rt-pill rt-pill-live"><span class="rt-dot"></span><span id="rt-live-label">Live</span></span>';
		echo '<span id="rt-live-count" class="rt-muted"></span>';
		echo '</div>';

		// Onboarding: how a request gets captured.
		echo '<div class="rt-card rt-howto">';
		echo '<h2 class="rt-card-title">How to capture a request</h2>';
		echo '<ol class="rt-steps">';

		echo '<li><strong>Watch a namespace.</strong><div class="rt-step-body">';
		if ( empty( $watched ) ) {
			echo '<span class="rt-error">No namespace configured yet.</span> ';
			echo '<a href="' . esc_url( $settings_url ) . '">Set one in Settings</a> (e.g. <code>wc/v3</code> or <code>myshop/v1</code>).';
		} else {
			echo 'Currently watching: ';
			foreach ( $watched as $ns ) {
				echo '<span class="rt-chip"><code>' . esc_html( $ns ) . '</code></span>';
			}
			echo ' <a class="rt-muted" href="' . esc_url( $settings_url ) . '">edit</a>';
		}
		echo '</div></li>';

		echo '<li><strong>Make a request.</strong><div class="rt-step-body">';
		if ( 'header' === $settings['mode'] ) {
			echo 'Any request is recorded as long as it carries the tracer header:';
			echo '<div class="rt-curl-row"><pre class="rt-pre rt-curl" id="rt-curl-sample"></pre><button type="button" class="button" id="rt-curl-copy">Copy</button></div>';
			echo '<span class="rt-muted">Only requests with this header are traced, so production traffic is unaffected.</span>';
		} else {
			echo '<span class="rt-badge rt-badge-ok">Always mode</span> Every request to a watched namespace is recorded automatically — no header needed.';
		}
		echo '</div></li>';

		echo '<li><strong>Inspect it.</strong><div class="rt-step-body">Captured requests appear in the list below <em>as they finish</em> — no reload needed. Click a row to open its flame graph.</div></li>';
		echo '</ol>';
		echo '</div>';

		// Toolbar.
		echo '<div class="rt-toolbar">';
		echo '<button type="button" class="button button-primary" id="rt-demo-run">Run demo trace</button>';
		echo '<label class="rt-demo-http"><input type="checkbox" id="rt-demo-http"> include outbound HTTP call</label>';
		echo '<span class="rt-spacer"></span>';
		echo '<input type="search" id="rt-filter" placeholder="Filter by route, method, status…" autocomplete="off">';
		echo '<button type="button" class="button" id="rt-live-toggle" title="Pause or resume automatic updates">Pause</button>';
		echo '<button type="button" class="button rt-button-danger" id="rt-clear-all">Clear all</button>';
		echo '</div>';

		// Table: header rendered server-side, body kept live by admin.js.
		echo '<table class="wp-list-table widefat fixed striped rt-traces"><thead><tr>';
		foreach ( array( 'Time', 'Request', 'Status', 'Duration', 'Ops', 'Actions' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody id="rt-traces-body">';
		echo '<tr><td colspan="6" class="rt-muted">Loading traces…</td></tr>';
		echo '</tbody></table>';
		echo '<div class="rt-load-more"><button type="button" class="button" id="rt-load-more" hidden>Load older traces</button></div>';
		echo '<noscript><p class="rt-error">The live trace list needs JavaScript. Enable it to see captured traces.</p></noscript>';
	}

	/* ---------------------------------------------------------------------
	 * Endpoints explorer
	 * ------------------------------------------------------------------- */

	private function render_endpoints() {
		?>
		<div class="rt-card">
			<h2 class="rt-card-title">Endpoint explorer</h2>
			<p class="description">List every endpoint registered under a namespace, exactly as the tracer would match it. Use it to find the namespace to watch, or to pick an endpoint to trace.</p>
			<div class="rt-explorer-controls">
				<input type="text" id="rt-ns-input" class="regular-text code" placeholder="e.g. wc/v3 or myshop/v1">
				<button type="button" class="button button-primary" id="rt-ns-load">List endpoints</button>
			</div>
			<div id="rt-ns-results"></div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private function render_settings() {
		$settings  = REST_Tracer_Plugin::get_settings();
		$endpoints_url = admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&tab=endpoints' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'rest_tracer' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rt-namespaces">Namespaces</label></th>
					<td>
						<input type="text" class="regular-text" id="rt-namespaces" name="rest_tracer_settings[namespaces]"
							value="<?php echo esc_attr( $settings['namespaces'] ); ?>" placeholder="myshop/v1, wc/v3">
						<p class="description">Comma-separated REST namespaces to watch. Not sure what to use? Look it up in the <a href="<?php echo esc_url( $endpoints_url ); ?>">Endpoints</a> tab.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Trigger mode</th>
					<td>
						<label><input type="radio" name="rest_tracer_settings[mode]" value="header" <?php checked( $settings['mode'], 'header' ); ?>> <strong>Header</strong> — trace only requests carrying <code>X-REST-Tracer</code> (safe for production)</label><br>
						<label><input type="radio" name="rest_tracer_settings[mode]" value="always" <?php checked( $settings['mode'], 'always' ); ?>> <strong>Always</strong> — trace every request to watched namespaces</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rt-token">Trigger token</label></th>
					<td>
						<input type="text" class="regular-text code" id="rt-token" name="rest_tracer_settings[token]"
							value="<?php echo esc_attr( $settings['token'] ); ?>">
						<p class="description">Requests must send this value as the <code>X-REST-Tracer</code> header. Clearing it generates a new one.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Capture</th>
					<td>
						<label><input type="checkbox" name="rest_tracer_settings[capture_hooks]" value="1" <?php checked( $settings['capture_hooks'] ); ?>> Hook &amp; filter callbacks (call tree)</label><br>
						<label><input type="checkbox" name="rest_tracer_settings[capture_sql]" value="1" <?php checked( $settings['capture_sql'] ); ?>> SQL queries</label><br>
						<label><input type="checkbox" name="rest_tracer_settings[capture_http]" value="1" <?php checked( $settings['capture_http'] ); ?>> Outbound HTTP requests</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rt-max-nodes">Max operations per trace</label></th>
					<td>
						<input type="number" id="rt-max-nodes" name="rest_tracer_settings[max_nodes]" min="500" max="100000"
							value="<?php echo esc_attr( $settings['max_nodes'] ); ?>">
						<p class="description">Safety cap — traces with more recorded operations are marked truncated.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rt-max-traces">Keep last N traces</label></th>
					<td><input type="number" id="rt-max-traces" name="rest_tracer_settings[max_traces]" min="1" max="500"
						value="<?php echo esc_attr( $settings['max_traces'] ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Trace viewer
	 * ------------------------------------------------------------------- */

	/**
	 * Render the trace detail page: shell for the redesigned viewer.
	 * admin.js builds the stat strip, graph views (flame / flow), waterfall,
	 * call tree and interactions from the inline payload.
	 *
	 * @param int $id Trace id.
	 */
	private function render_view( $id ) {
		$row = REST_Tracer_Storage::get_row( $id );
		if ( ! $row ) {
			echo '<div class="wrap"><h1>REST API Tracer</h1><div class="notice notice-error"><p>Trace #' . (int) $id . ' not found.</p></div>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) . '">Back to traces</a></p></div>';
			return;
		}

		$payload = REST_Tracer_Storage::decode_payload( $row->data, (int) $row->compressed );

		$status    = (int) $row->status;
		$status_cl = $status >= 200 && $status < 300 ? 'ok' : ( $status >= 400 ? 'bad' : 'warn' );
		$prefs     = REST_Tracer_Rest_Api::get_user_prefs();

		$download_json = wp_nonce_url(
			admin_url( 'admin-post.php?action=rest_tracer_download&amp;type=json&amp;trace=' . (int) $row->id ),
			'rest_tracer_download_' . (int) $row->id
		);
		$download_dot = wp_nonce_url(
			admin_url( 'admin-post.php?action=rest_tracer_download&amp;type=dot&amp;trace=' . (int) $row->id ),
			'rest_tracer_download_' . (int) $row->id
		);

		echo '<div class="wrap">';
		echo '<div class="rt-viewer" id="rt-viewer">';

		/* ---------- header ---------- */
		echo '<header class="rtv-head">';
        echo '<a class="page-title-action" href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) . '">← All traces</a>';
		echo '<span class="rtv-method">' . esc_html( $row->method ) . '</span>';
		echo '<code class="rtv-route">' . esc_html( $row->route ) . '</code>';
		echo '<span class="rtv-status rtv-status-' . esc_attr( $status_cl ) . '">' . ( $status ? (int) $status : '—' ) . '</span>';
		echo '<div class="rtv-head-meta">';
		echo '<a class="rtv-icon-btn" href="' . esc_url( $download_json ) . '">⬇ JSON</a>';
		echo '<a class="rtv-icon-btn" href="' . esc_url( $download_dot ) . '">⬇ DOT</a>';
		echo '</div>';
		echo '</header>';

		/* ---------- stat strip (JS) ---------- */
		echo '<section class="rtv-stats" id="rtv-stats"></section>';

		/* ---------- toolbar ---------- */
		echo '<div class="rtv-toolbar">';
		echo '<div class="rtv-chips" id="rtv-chips"></div>';
		echo '<span class="rtv-spacer"></span>';
		echo '<label class="rtv-search"><input id="rtv-q" type="search" placeholder="Filter operations…" autocomplete="off"><kbd>/</kbd></label>';
		echo '<span id="rtv-match" class="rtv-match"></span>';
		echo '<button type="button" class="rtv-icon-btn" id="rtv-reset">⟲ Reset zoom</button>';
		echo '</div>';

		/* ---------- tabs ---------- */
		echo '<nav class="rtv-tabs">';
		echo '<button type="button" class="rtv-tab on" data-view="graph" id="rtv-tab-graph">Graph</button>';
		echo '<button type="button" class="rtv-tab" data-view="waterfall" id="rtv-tab-waterfall">Waterfall</button>';
		echo '<button type="button" class="rtv-tab" data-view="tree" id="rtv-tab-tree">Call tree</button>';
		echo '</nav>';

		/* ---------- graph panel ---------- */
		echo '<section class="rtv-panel" id="rtv-view-graph">';
		echo '<div class="rtv-seg-row">';
		echo '<div class="rtv-seg" id="rtv-gmodes">';
		echo '<button type="button" data-g="flame"' . ( 'flame' === $prefs['graph'] ? ' class="on"' : '' ) . '>Flame</button>';
		echo '<button type="button" data-g="flow"' . ( 'flow' === $prefs['graph'] ? ' class="on"' : '' ) . '>Flow</button>';
		echo '</div>';
		echo '<span class="rtv-seg-hint" id="rtv-gm-hint">Graph style</span>';
		echo '</div>';
		echo '<div class="rtv-crumbs" id="rtv-crumbs"></div>';
		echo '<div id="rtv-g-time" class="rtv-canvas-pos">';
		echo '<button type="button" class="rtv-canvas-fs" id="rtv-time-fs" title="Fullscreen canvas">⛶</button>';
		echo '<div class="rtv-ruler" id="rtv-ruler"></div>';
		echo '<div class="rtv-flame" id="rtv-flame"></div>';
		echo '</div>';
		echo '<div class="rtv-flow-wrap" id="rtv-g-flow" hidden>';
		echo '<div class="rtv-flow-tools">';
		echo '<button type="button" id="rtv-flow-in" title="Zoom in">+</button>';
		echo '<button type="button" id="rtv-flow-out" title="Zoom out">−</button>';
		echo '<button type="button" id="rtv-flow-fit" title="Fit to view">⤢</button>';
		echo '<button type="button" id="rtv-flow-focus" title="Focus on the initial request">◎ Focus</button>';
		echo '<button type="button" id="rtv-flow-fs" title="Fullscreen">⛶</button>';
		echo '</div>';
		echo '<svg id="rtv-flow" viewBox="0 0 1400 760" role="img"></svg>';
		echo '<div class="rtv-flow-legend">drag to pan · scroll to zoom · click a node to collapse / expand</div>';
		echo '</div>';
		echo '</section>';

		/* ---------- waterfall panel ---------- */
		echo '<section class="rtv-panel" id="rtv-view-waterfall" hidden>';
		echo '<div class="rtv-wf-head"><div>Operation</div><div class="r">Timeline</div><div class="r">Duration / self</div></div>';
		echo '<div class="rtv-canvas-pos" id="rtv-wf-wrap">';
		echo '<button type="button" class="rtv-canvas-fs" id="rtv-wf-fs" title="Fullscreen canvas">⛶</button>';
		echo '<div class="rtv-wf-scroll" id="rtv-wf"></div>';
		echo '</div>';
		echo '</section>';

		/* ---------- call tree panel ---------- */
		echo '<section class="rtv-panel" id="rtv-view-tree" hidden>';
		echo '<div class="rtv-tree-row rtv-tree-head"><span>Operation (aggregated)</span><span class="num">Total</span><span class="num">Self</span><span>Share of request</span></div>';
		echo '<div id="rtv-tree"></div>';
		echo '</section>';

		/* ---------- details ---------- */
		echo '<div class="rtv-details" id="rtv-details"></div>';

		echo '<div class="rtv-tip" id="rtv-tip" hidden></div>';
		echo '</div>';

		wp_add_inline_script(
			'rest-tracer-admin',
			'window.REST_TRACER_TRACE = ' . wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG ) . ';',
			'before'
		);

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Admin-post actions
	 * ------------------------------------------------------------------- */

	/**
	 * Download a trace as JSON or GraphViz DOT.
	 */
	public function handle_download() {
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'json';
		$id   = isset( $_GET['trace'] ) ? absint( $_GET['trace'] ) : 0;

		if ( ! $id || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'rest-tracer' ), 403 );
		}
		check_admin_referer( 'rest_tracer_download_' . $id );

		$row = REST_Tracer_Storage::get_row( $id );
		if ( ! $row ) {
			wp_die( esc_html__( 'Trace not found', 'rest-tracer' ), 404 );
		}

		$payload = REST_Tracer_Storage::decode_payload( $row->data, (int) $row->compressed );
		$slug    = 'rest-trace-' . $id;

		if ( 'dot' === $type ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $slug . '.dot"' );
			echo rest_tracer_build_dot( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $slug . '.json"' );
			echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}
}
