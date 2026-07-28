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
		$target_version = isset( $job['target_version'] ) ? sanitize_text_field( $job['target_version'] ) : '';
		$skin = new Automatic_Upgrader_Skin();

		if ( 'plugin' === $type ) {
			if ( '' === $target_version ) {
				return new WP_Error( 'target_version_missing', 'La version cible de l’extension est absente.' );
			}
			wp_update_plugins();
			$updates = get_site_transient( 'update_plugins' );
			if ( empty( $updates->response[ $slug ] ) ) {
				return new WP_Error( 'plugin_update_missing', 'La mise à jour demandée n’est plus disponible.' );
			}
			$available = sanitize_text_field( $updates->response[ $slug ]->new_version ?? '' );
			if ( ! self::versions_match( $target_version, $available ) ) {
				return self::version_changed_error( $target_version, $available );
			}
			$result = ( new Plugin_Upgrader( $skin ) )->upgrade( $slug );
			return self::verify_upgrade_result( $result, $type, $slug, $target_version );
		}

		if ( 'plugin_batch' === $type ) {
			$payload = isset( $job['payload_json'] ) ? json_decode( $job['payload_json'], true ) : array();
			$items   = isset( $payload['items'] ) && is_array( $payload['items'] ) ? array_slice( $payload['items'], 0, 3 ) : array();
			$slugs   = array();
			$targets = array();
			foreach ( $items as $item ) {
				$plugin_file = sanitize_text_field( $item['slug'] ?? '' );
				$version     = sanitize_text_field( $item['version'] ?? '' );
				if ( $plugin_file && $version ) {
					$slugs[]                 = $plugin_file;
					$targets[ $plugin_file ] = $version;
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
				$available = sanitize_text_field( $updates->response[ $plugin_file ]->new_version ?? '' );
				if ( ! self::versions_match( $targets[ $plugin_file ], $available ) ) {
					return self::version_changed_error( $targets[ $plugin_file ], $available, $plugin_file );
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
				$installed = self::installed_plugin_version( $plugin_file );
				if ( ! self::versions_match( $targets[ $plugin_file ], $installed ) ) {
					return self::installed_version_error( $targets[ $plugin_file ], $installed, $plugin_file );
				}
			}
			return true;
		}

		if ( 'theme' === $type ) {
			if ( '' === $target_version ) {
				return new WP_Error( 'target_version_missing', 'La version cible du thème est absente.' );
			}
			wp_update_themes();
			$updates = get_site_transient( 'update_themes' );
			if ( empty( $updates->response[ $slug ] ) ) {
				return new WP_Error( 'theme_update_missing', 'La mise à jour demandée n’est plus disponible.' );
			}
			$available = sanitize_text_field( $updates->response[ $slug ]['new_version'] ?? '' );
			if ( ! self::versions_match( $target_version, $available ) ) {
				return self::version_changed_error( $target_version, $available );
			}
			$result = ( new Theme_Upgrader( $skin ) )->upgrade( $slug );
			return self::verify_upgrade_result( $result, $type, $slug, $target_version );
		}

		if ( 'core' === $type ) {
			if ( '' === $target_version ) {
				return new WP_Error( 'target_version_missing', 'La version cible de WordPress est absente.' );
			}
			wp_version_check();
			$updates = get_core_updates( array( 'dismissed' => false ) );
			if ( ! is_array( $updates ) ) {
				return new WP_Error( 'core_update_check_failed', 'WordPress n’a pas pu vérifier les mises à jour du cœur.' );
			}
			$selected = null;
			foreach ( $updates as $update ) {
				$available = sanitize_text_field( $update->current ?? ( $update->version ?? '' ) );
				if ( in_array( $update->response ?? '', array( 'upgrade', 'autoupdate' ), true ) && self::versions_match( $target_version, $available ) ) {
					$selected = $update;
					break;
				}
			}
			if ( ! $selected ) {
				return new WP_Error( 'core_update_missing', 'Aucune mise à jour WordPress n’est disponible.' );
			}
			$result = ( new Core_Upgrader( $skin ) )->upgrade( $selected );
			return self::verify_upgrade_result( $result, $type, $slug, $target_version );
		}

		return new WP_Error( 'unsupported_update_type', 'Type de mise à jour non pris en charge.' );
	}

	private static function verify_upgrade_result( $result, $type, $slug, $target_version ) {
		if ( is_wp_error( $result ) || false === $result || null === $result ) {
			return $result;
		}
		if ( 'plugin' === $type ) {
			$installed = self::installed_plugin_version( $slug );
		} elseif ( 'theme' === $type ) {
			$installed = wp_get_theme( $slug )->get( 'Version' );
		} else {
			$installed = self::installed_core_version();
		}
		return self::versions_match( $target_version, $installed ) ? $result : self::installed_version_error( $target_version, $installed );
	}

	private static function installed_plugin_version( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		return sanitize_text_field( $data['Version'] ?? '' );
	}

	private static function installed_core_version() {
		$wp_version = '';
		include ABSPATH . WPINC . '/version.php';
		return sanitize_text_field( $wp_version );
	}

	private static function versions_match( $expected, $actual ) {
		return '' !== (string) $expected && hash_equals( (string) $expected, (string) $actual );
	}

	private static function version_changed_error( $expected, $available, $item = '' ) {
		$context = $item ? $item . ' : ' : '';
		return new WP_Error( 'target_version_changed', $context . 'la version disponible (' . ( $available ?: 'inconnue' ) . ') ne correspond plus à la version validée (' . $expected . ').' );
	}

	private static function installed_version_error( $expected, $installed, $item = '' ) {
		$context = $item ? $item . ' : ' : '';
		return new WP_Error( 'installed_version_mismatch', $context . 'la version installée (' . ( $installed ?: 'inconnue' ) . ') ne correspond pas à la cible (' . $expected . ').' );
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
