<?php
/**
 * Plugin Name: Black Rock Meta Leads Sync
 * Plugin URI:  https://theblackrock.ae
 * Description: Receives Facebook/Instagram (Meta) Lead Ads webhooks and creates lead records in the Black Rock CRM (`leads` post type).
 * Version:     1.0.1
 * Requires PHP: 8.1
 * Requires at least: 5.8
 * Author:      CrocoNile Software
 * Author URI:  https://croconile.site
 * License:     GPL-2.0-or-later
 * Text Domain: blackrock-meta-leads-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BRML_Meta_Leads_Sync {

	private const GRAPH_VERSION = 'v21.0';
	private const LOG_OPTION    = 'brml_webhook_log';
	private const LOG_LIMIT     = 20;
	private const MAX_CONTENT   = 30000; // bytes, safely under 32 KB.

	public static function init(): void {
		$self = new self();
		add_action( 'rest_api_init', [ $self, 'register_routes' ] );
		add_action( 'admin_menu', [ $self, 'add_settings_page' ] );
		register_activation_hook( __FILE__, [ self::class, 'activate' ] );
	}

	public static function activate(): void {
		add_option( 'brml_verify_token', '' );
		add_option( 'brml_app_secret', '' );
		add_option( 'brml_page_access_token', '' );
		add_option( 'brml_responsible_person', '89' );
		add_option( 'brml_lead_status_term', 470 );
		add_option( self::LOG_OPTION, [], '', false );
	}

	/* ---------------------------------------------------------------------
	 * REST routes
	 * ------------------------------------------------------------------ */

	public function register_routes(): void {
		register_rest_route(
			'blackrock/v1',
			'/meta-webhook',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle_verification' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handle_webhook' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * GET: Meta webhook subscription verification.
	 */
	public function handle_verification( WP_REST_Request $request ) {
		$mode      = $request->get_param( 'hub_mode' ) ?? $request->get_param( 'hub.mode' );
		$token     = $request->get_param( 'hub_verify_token' ) ?? $request->get_param( 'hub.verify_token' );
		$challenge = $request->get_param( 'hub_challenge' ) ?? $request->get_param( 'hub.challenge' );

		$saved = (string) get_option( 'brml_verify_token', '' );
		$mode  = is_scalar( $mode ) ? (string) $mode : '';
		$token = is_scalar( $token ) ? (string) $token : '';

		if ( 'subscribe' === $mode && '' !== $saved && hash_equals( $saved, $token ) && is_scalar( $challenge ) ) {
			// Plain-text response: no JSON quoting.
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( (string) $challenge );
			exit;
		}

		return new WP_REST_Response( 'Forbidden', 403 );
	}

	/**
	 * POST: Lead ingestion.
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$raw_body = file_get_contents( 'php://input' );
		if ( false === $raw_body || '' === $raw_body ) {
			$raw_body = (string) $request->get_body();
		}

		$app_secret = (string) get_option( 'brml_app_secret', '' );
		$header     = (string) $request->get_header( 'x_hub_signature_256' );

		if ( '' === $app_secret || '' === $header || 0 !== strpos( $header, 'sha256=' ) ) {
			return new WP_REST_Response( [ 'status' => 'invalid_signature' ], 401 );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $app_secret );
		if ( ! hash_equals( $expected, $header ) ) {
			return new WP_REST_Response( [ 'status' => 'invalid_signature' ], 401 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->log( '', 0, 'error', 'Invalid JSON payload.' );
			return new WP_REST_Response( [ 'status' => 'received' ], 200 );
		}

		foreach ( (array) ( $payload['entry'] ?? [] ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
				if ( ( $change['field'] ?? '' ) !== 'leadgen' ) {
					continue;
				}
				$leadgen_id = isset( $change['value']['leadgen_id'] ) ? sanitize_text_field( (string) $change['value']['leadgen_id'] ) : '';
				if ( '' === $leadgen_id ) {
					$this->log( '', 0, 'error', 'Missing leadgen_id in change payload.' );
					continue;
				}
				try {
					$this->process_lead( $leadgen_id );
				} catch ( \Throwable $e ) {
					$this->log( $leadgen_id, 0, 'error', 'Exception: ' . $e->getMessage() );
				}
			}
		}

		return new WP_REST_Response( [ 'status' => 'received' ], 200 );
	}

	/* ---------------------------------------------------------------------
	 * Lead processing
	 * ------------------------------------------------------------------ */

	private function process_lead( string $leadgen_id ): void {
		// Strict deduplication across all statuses (including trash).
		$statuses = array_values( array_unique( array_merge( array_values( get_post_stati() ), [ 'trash' ] ) ) );
		$existing = get_posts(
			[
				'post_type'        => 'leads',
				'post_status'      => $statuses,
				'meta_key'         => '_meta_leadgen_id',
				'meta_value'       => $leadgen_id,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		if ( ! empty( $existing ) ) {
			$this->log( $leadgen_id, (int) $existing[0], 'duplicate_skipped', '' );
			return;
		}

		$token = (string) get_option( 'brml_page_access_token', '' );
		if ( '' === $token ) {
			$this->log( $leadgen_id, 0, 'error', 'Page Access Token is not configured.' );
			return;
		}

		$url = add_query_arg(
			[
				'fields'       => 'id,created_time,field_data,campaign_name,ad_name,form_id',
				'access_token' => $token,
			],
			'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode( $leadgen_id )
		);

		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $response ) ) {
			$this->log( $leadgen_id, 0, 'error', 'Graph API request failed: ' . $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Unexpected response';
			$this->log( $leadgen_id, 0, 'error', sprintf( 'Graph API HTTP %d: %s', $code, $msg ) );
			return;
		}
		if ( isset( $data['error'] ) ) {
			$this->log( $leadgen_id, 0, 'error', 'Graph API error: ' . (string) ( $data['error']['message'] ?? 'unknown' ) );
			return;
		}

		// Extract fields.
		$name = $phone = $email = $min_budget = $max_budget = '';
		$extras = [];

		foreach ( (array) ( $data['field_data'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
				continue;
			}
			$key   = strtolower( trim( (string) $field['name'] ) );
			$vals  = array_map( 'strval', array_filter( (array) ( $field['values'] ?? [] ), 'is_scalar' ) );
			$value = sanitize_text_field( implode( ', ', $vals ) );

			switch ( $key ) {
				case 'full_name':
				case 'name':
					if ( '' === $name ) {
						$name = $value;
					}
					break;
				case 'phone_number':
				case 'phone':
					if ( '' === $phone ) {
						$phone = $value;
					}
					break;
				case 'email':
					if ( '' === $email ) {
						$email = is_email( $value ) ? sanitize_email( $value ) : '';
					}
					break;
				case 'minimum_budget':
					$min_budget = $value;
					break;
				case 'maximum_budget':
					$max_budget = $value;
					break;
				default:
					if ( '' !== $value ) {
						$label    = ucwords( trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-' ], ' ', sanitize_text_field( (string) $field['name'] ) ) ) ) );
						$extras[] = $label . ': ' . $value;
					}
			}
		}

		// Validation.
		if ( '' === $name || ( '' === $phone && '' === $email ) ) {
			$this->log( $leadgen_id, 0, 'error', 'Validation failed: name and at least one of phone/email are required.' );
			return;
		}

		$title = sanitize_text_field( (string) ( $data['campaign_name'] ?? '' ) );
		if ( '' === $title ) {
			$title = sanitize_text_field( (string) ( $data['ad_name'] ?? '' ) );
		}
		if ( '' === $title ) {
			$title = 'Meta Lead Campaign';
		}

		$content = implode( '; ', $extras );
		if ( strlen( $content ) > self::MAX_CONTENT ) {
			$content = mb_strcut( $content, 0, self::MAX_CONTENT, 'UTF-8' );
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'leads',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			],
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'wp_insert_post returned 0';
			$this->log( $leadgen_id, 0, 'error', 'Insert failed: ' . $msg );
			return;
		}

		$meta = [
			'lead-name'          => $name,
			'lead-phone'         => $phone,
			'lead-email'         => $email,
			'minimum_budget'     => $min_budget,
			'maximum_budget'     => $max_budget,
			'_meta_leadgen_id'   => (string) $leadgen_id,
			'source'             => 'social_media_campaign',
			'purpose_of_the_lead' => 'sale',
			'responsible-person' => (string) get_option( 'brml_responsible_person', '89' ),
		];
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, $k, (string) $v );
		}

		$term_id = (int) get_option( 'brml_lead_status_term', 470 );
		$result  = wp_set_object_terms( $post_id, [ $term_id ], 'lead-status', false );
		if ( is_wp_error( $result ) ) {
			$this->log( $leadgen_id, (int) $post_id, 'created_with_warning', 'Term assignment failed: ' . $result->get_error_message() );
			return;
		}

		$this->log( $leadgen_id, (int) $post_id, 'created', '' );
	}

	/* ---------------------------------------------------------------------
	 * Log
	 * ------------------------------------------------------------------ */

	private function log( string $leadgen_id, int $post_id, string $status, string $error ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		array_unshift(
			$log,
			[
				'time'       => time(),
				'leadgen_id' => $leadgen_id,
				'post_id'    => $post_id,
				'status'     => $status,
				'error'      => $error,
			]
		);
		$log = array_slice( $log, 0, self::LOG_LIMIT );
		update_option( self::LOG_OPTION, $log, false );
	}

	/* ---------------------------------------------------------------------
	 * Admin settings page
	 * ------------------------------------------------------------------ */

	public function add_settings_page(): void {
		add_options_page(
			'Meta Leads Sync',
			'Meta Leads Sync',
			'manage_options',
			'blackrock-meta-leads-sync',
			[ $this, 'render_settings_page' ]
		);
	}

	private function save_settings(): void {
		check_admin_referer( 'brml_save_settings', 'brml_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'blackrock-meta-leads-sync' ) );
		}

		update_option( 'brml_verify_token', sanitize_text_field( wp_unslash( $_POST['brml_verify_token'] ?? '' ) ) );
		update_option( 'brml_app_secret', sanitize_text_field( wp_unslash( $_POST['brml_app_secret'] ?? '' ) ) );
		update_option( 'brml_page_access_token', sanitize_text_field( wp_unslash( $_POST['brml_page_access_token'] ?? '' ) ) );
		update_option( 'brml_responsible_person', (string) absint( wp_unslash( $_POST['brml_responsible_person'] ?? 89 ) ) );
		update_option( 'brml_lead_status_term', absint( wp_unslash( $_POST['brml_lead_status_term'] ?? 470 ) ) );

		add_settings_error( 'brml', 'brml_saved', 'Settings saved.', 'updated' );
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['brml_nonce'] ) ) {
			$this->save_settings();
		}

		$webhook_url = rest_url( 'blackrock/v1/meta-webhook' );
		$log         = get_option( self::LOG_OPTION, [] );
		$log         = is_array( $log ) ? $log : [];
		?>
		<div class="wrap">
			<h1>Meta Leads Sync</h1>
			<?php settings_errors( 'brml' ); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'brml_save_settings', 'brml_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="brml_verify_token">Meta Verify Token</label></th>
						<td>
							<input type="text" class="regular-text" id="brml_verify_token" name="brml_verify_token" value="<?php echo esc_attr( (string) get_option( 'brml_verify_token', '' ) ); ?>" autocomplete="off">
							<button type="button" class="button" id="brml-generate">Generate random token</button>
							<p class="description">Enter this same value as the "Verify token" when subscribing the webhook in the Meta App Dashboard.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="brml_app_secret">Meta App Secret</label></th>
						<td>
							<input type="password" class="regular-text" id="brml_app_secret" name="brml_app_secret" value="<?php echo esc_attr( (string) get_option( 'brml_app_secret', '' ) ); ?>" autocomplete="new-password">
							<p class="description">Used to verify the <code>X-Hub-Signature-256</code> header on incoming requests.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="brml_page_access_token">Meta Page Access Token</label></th>
						<td>
							<input type="password" class="regular-text" id="brml_page_access_token" name="brml_page_access_token" value="<?php echo esc_attr( (string) get_option( 'brml_page_access_token', '' ) ); ?>" autocomplete="new-password">
							<p class="description">System User or long-lived Page token with <code>leads_retrieval</code> permission.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="brml_responsible_person">Default Responsible User ID</label></th>
						<td><input type="number" min="0" class="small-text" id="brml_responsible_person" name="brml_responsible_person" value="<?php echo esc_attr( (string) get_option( 'brml_responsible_person', '89' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="brml_lead_status_term">Default Lead Status Term ID</label></th>
						<td>
							<input type="number" min="0" class="small-text" id="brml_lead_status_term" name="brml_lead_status_term" value="<?php echo esc_attr( (string) get_option( 'brml_lead_status_term', 470 ) ); ?>">
							<p class="description"><code>lead-status</code> taxonomy term ID for "New".</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="brml_webhook_url">Webhook Callback URL</label></th>
						<td>
							<input type="text" class="large-text code" id="brml_webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" readonly style="max-width:520px;">
							<button type="button" class="button" id="brml-copy">Copy</button>
							<span id="brml-copied" style="display:none;margin-left:8px;color:#1a7f37;">Copied!</span>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<h2>Recent Webhook Log</h2>
			<table class="widefat striped" style="max-width:1000px;">
				<thead>
					<tr><th>Timestamp</th><th>Leadgen ID</th><th>Lead Post ID</th><th>Status</th><th>Error</th></tr>
				</thead>
				<tbody>
				<?php if ( empty( $log ) ) : ?>
					<tr><td colspan="5">No webhook activity yet.</td></tr>
				<?php else : ?>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $row['time'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['leadgen_id'] ?? '' ) ); ?></td>
							<td>
								<?php
								$pid = (int) ( $row['post_id'] ?? 0 );
								if ( $pid > 0 ) {
									printf( '<a href="%s">%d</a>', esc_url( get_edit_post_link( $pid ) ?: '#' ), $pid );
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['error'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function () {
			var gen = document.getElementById('brml-generate');
			var tok = document.getElementById('brml_verify_token');
			gen.addEventListener('click', function () {
				var a = new Uint8Array(24);
				window.crypto.getRandomValues(a);
				tok.value = Array.from(a, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
			});
			var copy = document.getElementById('brml-copy');
			var url = document.getElementById('brml_webhook_url');
			var ok = document.getElementById('brml-copied');
			copy.addEventListener('click', function () {
				url.select();
				var done = function () { ok.style.display = 'inline'; setTimeout(function () { ok.style.display = 'none'; }, 1500); };
				if (navigator.clipboard) { navigator.clipboard.writeText(url.value).then(done); }
				else { document.execCommand('copy'); done(); }
			});
		})();
		</script>
		<?php
	}
}

/**
 * GitHub release updater.
 *
 * Checks the latest GitHub release of a PUBLIC repository and shows the normal
 * "Update available" notice in WordPress. The release must have an attached asset
 * named exactly `blackrock-meta-leads-sync.zip` (zip root folder: blackrock-meta-leads-sync/).
 *
 * To use another repository, add this to wp-config.php:
 *   define( 'BRML_GITHUB_REPO', 'owner/repo' );
 */
final class BRML_GitHub_Updater {

	private const DEFAULT_REPO = 'kapousa/blackrock-meta-leads-sync';
	private const ASSET_NAME   = 'blackrock-meta-leads-sync.zip';
	private const CACHE_KEY    = 'brml_gh_release';
	private const CACHE_TTL    = 6 * HOUR_IN_SECONDS;

	private string $slug;
	private string $basename;

	public static function init(): void {
		$self           = new self();
		$self->basename = plugin_basename( __FILE__ );
		$self->slug     = dirname( $self->basename );
		add_filter( 'pre_set_site_transient_update_plugins', [ $self, 'inject_update' ] );
		add_filter( 'plugins_api', [ $self, 'plugin_info' ], 10, 3 );
	}

	private function repo(): string {
		return defined( 'BRML_GITHUB_REPO' ) ? (string) BRML_GITHUB_REPO : self::DEFAULT_REPO;
	}

	/**
	 * Latest release data (cached). Returns [] when unavailable.
	 */
	private function latest_release(): array {
		$force = isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo() . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'BRML-Updater/' . $this->slug,
				],
			]
		);

		$data = [];
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$package = '';
				foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
					if ( ( $asset['name'] ?? '' ) === self::ASSET_NAME && ! empty( $asset['browser_download_url'] ) ) {
						$package = (string) $asset['browser_download_url'];
						break;
					}
				}
				if ( '' !== $package ) {
					$data = [
						'version' => ltrim( (string) $body['tag_name'], 'vV' ),
						'package' => esc_url_raw( $package ),
						'url'     => esc_url_raw( (string) ( $body['html_url'] ?? '' ) ),
						'notes'   => (string) ( $body['body'] ?? '' ),
						'date'    => (string) ( $body['published_at'] ?? '' ),
					];
				}
			}
		}

		// Cache failures briefly so a GitHub outage does not slow down the admin.
		set_site_transient( self::CACHE_KEY, $data, empty( $data ) ? HOUR_IN_SECONDS : self::CACHE_TTL );
		return $data;
	}

	private function current_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( __FILE__, false, false );
		return (string) ( $data['Version'] ?? '0' );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( empty( $release ) || ! version_compare( $release['version'], $this->current_version(), '>' ) ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) [
			'id'          => 'github.com/' . $this->repo(),
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		];

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'Black Rock Meta Leads Sync',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://croconile.site">CrocoNile Software</a>',
			'homepage'      => $release['url'],
			'requires'      => '5.8',
			'requires_php'  => '8.1',
			'last_updated'  => $release['date'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => 'Receives Facebook/Instagram Lead Ads webhooks and creates leads in the Black Rock CRM.',
				'changelog'   => nl2br( esc_html( $release['notes'] ) ),
			],
		];
	}
}

BRML_Meta_Leads_Sync::init();
BRML_GitHub_Updater::init();
