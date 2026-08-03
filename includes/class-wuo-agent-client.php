<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Client {
	const CRON_HOOK = 'wuo_agent_poll';
	const OPTION    = 'wuo_agent_settings';
	const PENDING_RESULT_OPTION = 'wuo_agent_pending_result';
	const MASTER_UNREACHABLE_ALERT_TRANSIENT = 'wuo_agent_master_unreachable_alert_sent';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll' ) );
	}

	public static function activate() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 30, 150 ), 'wuo_two_minutes', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['wuo_two_minutes'] = array( 'interval' => 120, 'display' => 'Toutes les deux minutes' );
		return $schedules;
	}

	public static function pair( $master_url, $token, $pool ) {
		if ( 'https' !== wp_parse_url( $master_url, PHP_URL_SCHEME ) ) {
			return new WP_Error( 'https_required', 'Le maître doit utiliser une URL HTTPS.' );
		}
		if ( ! in_array( $pool, array( 'performance-a', 'performance-b', 'perso', 'external', 'ekinox-1', 'ekinox-2', 'ekinox-3' ), true ) ) {
			return new WP_Error( 'invalid_hosting_pool', 'L’hébergement sélectionné est invalide.' );
		}
		$route = '/wp-json/wuo/v1/pair';
		$body  = wp_json_encode( array(
			'token'         => $token,
			'site_url'      => home_url( '/' ),
			'site_name'     => get_bloginfo( 'name' ),
			'agent_version' => WUO_AGENT_VERSION,
			'hosting_pool'  => $pool,
		) );
		$response = wp_safe_remote_post( untrailingslashit( $master_url ) . $route, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || wp_remote_retrieve_response_code( $response ) >= 300 || empty( $data['site_id'] ) || empty( $data['secret'] ) ) {
			return new WP_Error( 'pairing_failed', isset( $data['message'] ) ? $data['message'] : 'Appairage refusé par le maître.' );
		}
		$secret_enc = WUO_Agent_Crypto::encrypt( sanitize_text_field( $data['secret'] ) );
		if ( is_wp_error( $secret_enc ) ) {
			return $secret_enc;
		}
		$settings = array(
			'master_url'       => esc_url_raw( $master_url ),
			'site_id'          => absint( $data['site_id'] ),
			'secret_enc'       => $secret_enc,
			'hosting_pool'     => sanitize_key( $pool ),
			'paired_at'        => time(),
			'next_job_poll'    => time() + wp_rand( 0, 120 ),
			'last_master_contact' => time(),
		);
		update_option( self::OPTION, $settings, false );
		self::heartbeat( true );
		return true;
	}

	public static function poll() {
		$now = time();
		update_option( 'wuo_agent_last_cron', $now, false );
		if ( get_transient( 'wuo_agent_update_lock' ) ) {
			return;
		}
		$pending = self::flush_pending_result();
		if ( is_wp_error( $pending ) ) {
			$policy = self::policy( get_option( self::OPTION, array() ) );
			self::maybe_alert_master_unreachable( $pending );
			self::schedule_next_poll( $policy['poll_active'], $policy['jitter'] );
			return;
		}

		self::maybe_heartbeat();
		$settings = get_option( self::OPTION, array() );
		if ( empty( $settings['site_id'] ) || ( ! empty( $settings['next_job_poll'] ) && (int) $settings['next_job_poll'] > $now ) ) {
			return;
		}

		$response = self::request( 'POST', '/wp-json/wuo/v1/jobs/next', array() );
		$policy   = self::policy( get_option( self::OPTION, array() ) );
		if ( is_wp_error( $response ) ) {
			self::maybe_alert_master_unreachable( $response );
			self::schedule_next_poll( $policy['poll_idle'], $policy['jitter'] );
			return;
		}

		$poll_after = isset( $response['poll_after'] ) ? absint( $response['poll_after'] ) : $policy['poll_idle'];
		self::schedule_next_poll( min( 900, max( 120, $poll_after ) ), $policy['jitter'] );
		if ( empty( $response['job'] ) ) {
			return;
		}

		$result = WUO_Agent_Runner::run( $response['job'] );
		self::queue_result( absint( $response['job']['id'] ), $result );
		self::flush_pending_result();
		self::schedule_next_poll( $policy['poll_active'], $policy['jitter'] );
	}

	private static function queue_result( $job_id, array $result ) {
		update_option( self::PENDING_RESULT_OPTION, array(
			'job_id'       => absint( $job_id ),
			'result'       => $result,
			'created_at'   => time(),
			'attempts'     => 0,
			'last_attempt' => 0,
		), false );
	}

	private static function flush_pending_result() {
		$pending = get_option( self::PENDING_RESULT_OPTION, array() );
		if ( empty( $pending ) ) {
			return true;
		}
		if ( ! is_array( $pending ) || empty( $pending['job_id'] ) || ! isset( $pending['result'] ) || ! is_array( $pending['result'] ) ) {
			return new WP_Error( 'pending_result_invalid', 'Le résultat local en attente est invalide et nécessite une vérification.' );
		}

		$pending['attempts']     = absint( $pending['attempts'] ?? 0 ) + 1;
		$pending['last_attempt'] = time();
		update_option( self::PENDING_RESULT_OPTION, $pending, false );
		$response = self::request( 'POST', '/wp-json/wuo/v1/jobs/result', array(
			'job_id' => absint( $pending['job_id'] ),
			'result' => $pending['result'],
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		delete_option( self::PENDING_RESULT_OPTION );
		return true;
	}

	private static function maybe_heartbeat() {
		$settings = get_option( self::OPTION, array() );
		if ( empty( $settings['site_id'] ) ) {
			return;
		}
		$policy = self::policy( $settings );
		$last   = isset( $settings['last_heartbeat'] ) ? absint( $settings['last_heartbeat'] ) : 0;
		if ( ! $last || $last <= time() - $policy['heartbeat'] ) {
			self::heartbeat( false );
		}
	}

	public static function heartbeat( $force_full = false ) {
		$settings = get_option( self::OPTION, array() );
		$policy   = self::policy( $settings );
		$last_full = isset( $settings['last_full_health'] ) ? absint( $settings['last_full_health'] ) : 0;
		$health    = get_option( 'wuo_agent_health_snapshot', array() );
		$full      = $force_full || ! is_array( $health ) || empty( $health ) || $last_full <= time() - $policy['full_health'];

		if ( $full ) {
			$health = WUO_Agent_Health::collect();
			update_option( 'wuo_agent_health_snapshot', $health, false );
			$settings['last_full_health'] = time();
			update_option( self::OPTION, $settings, false );
		}

		$response = self::request( 'POST', '/wp-json/wuo/v1/heartbeat', array(
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
			'agent_version' => WUO_AGENT_VERSION,
			'health'        => $health,
			'health_full'   => $full,
			'updraft'       => WUO_Agent_Updraft::status(),
			'updates'       => self::available_updates(),
			'control'       => array(
				'effective_mode' => class_exists( 'WUO_Agent_Control' ) ? WUO_Agent_Control::effective_mode() : 'total',
				'last_cron'      => absint( get_option( 'wuo_agent_last_cron', 0 ) ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			self::maybe_alert_master_unreachable( $response );
		} else {
			$settings = get_option( self::OPTION, array() );
			$settings['last_heartbeat'] = time();
			if ( isset( $response['policy'] ) && is_array( $response['policy'] ) ) {
				$settings['policy'] = self::sanitize_policy( $response['policy'] );
			}
			update_option( self::OPTION, $settings, false );
		}
		return $response;
	}

	public static function master_unreachable_since() {
		$settings = get_option( self::OPTION, array() );
		if ( empty( $settings['site_id'] ) ) {
			return 0;
		}
		$last = absint( $settings['last_master_contact'] ?? $settings['paired_at'] ?? 0 );
		if ( ! $last ) {
			return 0;
		}
		$policy = self::policy( $settings );
		return $last <= time() - $policy['failover_hours'] * HOUR_IN_SECONDS ? $last : 0;
	}

	private static function maybe_alert_master_unreachable( WP_Error $error ) {
		$since = self::master_unreachable_since();
		if ( ! $since || get_transient( self::MASTER_UNREACHABLE_ALERT_TRANSIENT ) ) {
			return;
		}
		$email = get_option( 'admin_email', '' );
		if ( ! is_email( $email ) ) {
			return;
		}
		$settings = get_option( self::OPTION, array() );
		$policy   = self::policy( is_array( $settings ) ? $settings : array() );
		$subject  = 'Maître injoignable : ' . wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$message  = "L’agent Z-Update ne contacte plus le maître depuis le délai maximal configuré.\n\n";
		$message .= 'Site : ' . get_bloginfo( 'name' ) . "\n";
		$message .= 'URL : ' . home_url( '/' ) . "\n";
		$message .= 'Maître : ' . ( $settings['master_url'] ?? 'non renseigné' ) . "\n";
		$message .= 'Dernier contact maître : ' . wp_date( 'd/m/Y H:i:s', $since ) . "\n";
		$message .= 'Délai configuré : ' . absint( $policy['failover_hours'] ) . " h\n";
		$message .= 'Erreur courante : ' . $error->get_error_message() . "\n";
		wp_mail( $email, '[Z-Update] ' . sanitize_text_field( $subject ), sanitize_textarea_field( $message ) );
		set_transient( self::MASTER_UNREACHABLE_ALERT_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );
	}

	private static function schedule_next_poll( $delay, $jitter ) {
		$settings = get_option( self::OPTION, array() );
		$settings['next_job_poll'] = time() + absint( $delay ) + wp_rand( 0, absint( $jitter ) );
		update_option( self::OPTION, $settings, false );
	}

	private static function policy( array $settings ) {
		$policy = isset( $settings['policy'] ) && is_array( $settings['policy'] ) ? $settings['policy'] : array();
		return self::sanitize_policy( $policy );
	}

	private static function sanitize_policy( array $policy ) {
		return array(
			'control_mode'      => isset( $policy['control_mode'] ) && 'security' === $policy['control_mode'] ? 'security' : 'total',
			'failover_hours'    => min( 168, max( 1, absint( $policy['failover_hours'] ?? 48 ) ) ),
			'failover_security' => ! isset( $policy['failover_security'] ) || ! empty( $policy['failover_security'] ),
			'poll_idle'         => min( 900, max( 120, absint( $policy['poll_idle'] ?? 300 ) ) ),
			'poll_active'       => min( 300, max( 120, absint( $policy['poll_active'] ?? 120 ) ) ),
			'heartbeat'         => min( 3600, max( 300, absint( $policy['heartbeat'] ?? 600 ) ) ),
			'full_health'       => min( 7200, max( 900, absint( $policy['full_health'] ?? 1800 ) ) ),
			'jitter'            => min( 300, absint( $policy['jitter'] ?? 120 ) ),
			'update_repository' => class_exists( 'Z_Update_Package_Verifier' ) ? Z_Update_Package_Verifier::valid_repository( $policy['update_repository'] ?? '' ) : '',
			'update_check_generation' => absint( $policy['update_check_generation'] ?? 0 ),
		);
	}

	private static function request( $method, $route, array $payload ) {
		$settings = get_option( self::OPTION, array() );
		if ( empty( $settings['master_url'] ) || empty( $settings['site_id'] ) || empty( $settings['secret_enc'] ) ) {
			return new WP_Error( 'agent_not_paired', 'L’agent n’est pas appairé.' );
		}
		$secret = WUO_Agent_Crypto::decrypt( $settings['secret_enc'] );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		$payload['site_id'] = (int) $settings['site_id'];
		$body    = wp_json_encode( $payload );
		$headers = WUO_Agent_Signature::headers( $method, $route, $body, $settings['site_id'], $secret );
		$response = wp_safe_remote_request( untrailingslashit( $settings['master_url'] ) . $route, array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return new WP_Error( 'master_request_failed', isset( $data['message'] ) ? $data['message'] : 'Erreur du maître.' );
		}
		$settings = get_option( self::OPTION, array() );
		$settings['last_master_contact'] = time();
		update_option( self::OPTION, $settings, false );
		delete_transient( self::MASTER_UNREACHABLE_ALERT_TRANSIENT );
		return is_array( $data ) ? $data : array();
	}

	private static function available_updates() {
		$updates = array();
		$plugins = get_site_transient( 'update_plugins' );
		if ( ! empty( $plugins->response ) ) {
			foreach ( $plugins->response as $file => $item ) {
				$name = 'wp-update-orchestrator-agent/wp-update-orchestrator-agent.php' === $file ? 'Z-Update — Agent' : ( isset( $item->slug ) ? $item->slug : $file );
				$updates[] = array( 'type' => 'plugin', 'slug' => $file, 'name' => $name, 'version' => isset( $item->new_version ) ? $item->new_version : '' );
			}
		}
		$themes = get_site_transient( 'update_themes' );
		if ( ! empty( $themes->response ) ) {
			foreach ( $themes->response as $slug => $item ) {
				$updates[] = array( 'type' => 'theme', 'slug' => $slug, 'name' => $item['theme'] ?? $slug, 'version' => $item['new_version'] ?? '' );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$core = get_site_transient( 'update_core' );
		if ( ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $core_update ) {
				$response = sanitize_key( $core_update->response ?? '' );
				if ( ! in_array( $response, array( 'upgrade', 'autoupdate' ), true ) ) {
					continue;
				}
				$target_version = isset( $core_update->current ) ? $core_update->current : ( $core_update->version ?? '' );
				if ( ! $target_version ) {
					continue;
				}
				$updates[] = array(
					'type'          => 'core',
					'slug'          => 'wordpress',
					'name'          => 'WordPress',
					'version'       => $target_version,
					'core_response' => $response,
				);
				break;
			}
		}
		return array_slice( $updates, 0, 100 );
	}
}
