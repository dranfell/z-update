<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Runner {
	public static function run( array $job ) {
		$started = time();
		$before  = WUO_Agent_Health::collect( false );
		$backup  = WUO_Agent_Updraft::status();
		$max_age = isset( $job['backup_max_age'] ) ? max( HOUR_IN_SECONDS, (int) $job['backup_max_age'] ) : DAY_IN_SECONDS;

		if ( self::another_update_is_running() ) {
			return self::result( false, 'concurrent_update_detected', 'Une autre mise à jour WordPress est déjà en cours.', $started, $before, $backup, true, 600 );
		}
		if ( WUO_Agent_Updraft::is_running() ) {
			return self::result( false, 'updraft_backup_running', 'Une sauvegarde UpdraftPlus est en cours.', $started, $before, $backup, true, 600 );
		}
		if ( empty( $before['healthy'] ) ) {
			return self::result( false, 'preflight_health_failed', 'Le contrôle de santé préalable a échoué.', $started, $before, $backup, true, 900 );
		}
		if ( empty( $backup['complete'] ) || null === $backup['age_seconds'] || $backup['age_seconds'] > $max_age ) {
			return self::result( false, 'updraft_backup_ineligible', 'Aucune sauvegarde UpdraftPlus complète et suffisamment récente sur Google Drive.', $started, $before, $backup, true, 3600 );
		}

		set_transient( 'wuo_agent_update_lock', (int) $job['id'], 30 * MINUTE_IN_SECONDS );
		$result = self::upgrade( $job );
		delete_transient( 'wuo_agent_update_lock' );
		$after = WUO_Agent_Health::collect( false );

		if ( is_wp_error( $result ) ) {
			return self::result( false, $result->get_error_code(), $result->get_error_message(), $started, $after, $backup );
		}
		if ( false === $result || null === $result ) {
			return self::result( false, 'update_failed', 'WordPress n’a pas confirmé la réussite de la mise à jour.', $started, $after, $backup );
		}
		if ( empty( $after['healthy'] ) ) {
			return self::result( false, 'postflight_health_failed', 'La mise à jour est terminée mais le contrôle de santé a échoué.', $started, $after, $backup );
		}

		return self::result( true, 'completed', 'Mise à jour terminée et contrôlée.', $started, $after, $backup );
	}

	private static function another_update_is_running() {
		foreach ( array( 'core_updater.lock', 'auto_updater.lock' ) as $lock ) {
			$started = (int) get_site_option( $lock, 0 );
			if ( $started && $started > time() - HOUR_IN_SECONDS ) {
				return true;
			}
		}
		return false;
	}

	private static function upgrade( array $job ) {
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$type = isset( $job['type'] ) ? sanitize_key( $job['type'] ) : '';
		$slug = isset( $job['slug'] ) ? sanitize_text_field( $job['slug'] ) : '';
		$skin = new Automatic_Upgrader_Skin();

		if ( 'plugin' === $type ) {
			wp_update_plugins();
			$updates = get_site_transient( 'update_plugins' );
			if ( empty( $updates->response[ $slug ] ) ) {
				return new WP_Error( 'plugin_update_missing', 'La mise à jour demandée n’est plus disponible.' );
			}
			return ( new Plugin_Upgrader( $skin ) )->upgrade( $slug );
		}

		if ( 'plugin_batch' === $type ) {
			$payload = isset( $job['payload_json'] ) ? json_decode( $job['payload_json'], true ) : array();
			$items   = isset( $payload['items'] ) && is_array( $payload['items'] ) ? array_slice( $payload['items'], 0, 3 ) : array();
			$slugs   = array();
			foreach ( $items as $item ) {
				if ( ! empty( $item['slug'] ) ) {
					$slugs[] = sanitize_text_field( $item['slug'] );
				}
			}
			$slugs = array_values( array_unique( $slugs ) );
			if ( count( $slugs ) < 2 ) {
				return new WP_Error( 'invalid_plugin_batch', 'Le micro-lot d’extensions est invalide.' );
			}
			wp_update_plugins();
			$updates = get_site_transient( 'update_plugins' );
			foreach ( $slugs as $plugin_file ) {
				if ( empty( $updates->response[ $plugin_file ] ) ) {
					return new WP_Error( 'plugin_batch_update_missing', 'Une mise à jour du micro-lot n’est plus disponible : ' . $plugin_file );
				}
			}
			$batch_skin = new Bulk_Plugin_Upgrader_Skin();
			$results    = ( new Plugin_Upgrader( $batch_skin ) )->bulk_upgrade( $slugs );
			if ( ! is_array( $results ) ) {
				return new WP_Error( 'plugin_batch_failed', 'WordPress n’a pas confirmé le micro-lot d’extensions.' );
			}
			foreach ( $slugs as $plugin_file ) {
				if ( ! isset( $results[ $plugin_file ] ) || is_wp_error( $results[ $plugin_file ] ) || false === $results[ $plugin_file ] || null === $results[ $plugin_file ] ) {
					$message = isset( $results[ $plugin_file ] ) && is_wp_error( $results[ $plugin_file ] ) ? $results[ $plugin_file ]->get_error_message() : 'Échec non détaillé.';
					return new WP_Error( 'plugin_batch_partial_failure', $plugin_file . ' : ' . $message );
				}
			}
			return true;
		}

		if ( 'theme' === $type ) {
			wp_update_themes();
			$updates = get_site_transient( 'update_themes' );
			if ( empty( $updates->response[ $slug ] ) ) {
				return new WP_Error( 'theme_update_missing', 'La mise à jour demandée n’est plus disponible.' );
			}
			return ( new Theme_Upgrader( $skin ) )->upgrade( $slug );
		}

		if ( 'core' === $type ) {
			if ( empty( $job['manual_approval'] ) ) {
				return new WP_Error( 'core_approval_required', 'Une validation manuelle est obligatoire pour WordPress.' );
			}
			wp_version_check();
			$updates = get_core_updates( array( 'dismissed' => false ) );
			if ( empty( $updates[0] ) || 'upgrade' !== $updates[0]->response ) {
				return new WP_Error( 'core_update_missing', 'Aucune mise à jour WordPress n’est disponible.' );
			}
			return ( new Core_Upgrader( $skin ) )->upgrade( $updates[0] );
		}

		return new WP_Error( 'unsupported_update_type', 'Type de mise à jour non pris en charge.' );
	}

	private static function result( $success, $code, $message, $started, array $health, array $backup, $retryable = false, $retry_after = 600 ) {
		return array(
			'success'     => (bool) $success,
			'code'        => sanitize_key( $code ),
			'message'     => sanitize_text_field( $message ),
			'started_at'  => (int) $started,
			'finished_at' => time(),
			'health'      => $health,
			'backup'      => $backup,
			'retryable'   => (bool) $retryable,
			'retry_after' => $retryable ? min( 7200, max( 300, absint( $retry_after ) ) ) : 0,
		);
	}
}
