<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wuo_agent_pair', array( __CLASS__, 'handle_pair' ) );
		add_action( 'admin_post_wuo_agent_heartbeat', array( __CLASS__, 'handle_heartbeat' ) );
	}

	public static function menu() {
		add_options_page( 'Z-Update', 'Z-Update', 'manage_options', 'wuo-agent', array( __CLASS__, 'render' ) );
	}

	public static function handle_pair() {
		check_admin_referer( 'wuo_agent_pair' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$result = WUO_Agent_Client::pair(
			esc_url_raw( wp_unslash( $_POST['master_url'] ) ),
			sanitize_text_field( wp_unslash( $_POST['pairing_token'] ) ),
			sanitize_key( wp_unslash( $_POST['hosting_pool'] ) )
		);
		$notice = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : 'success:Agent appairé.';
		wp_safe_redirect( add_query_arg( 'wuo_notice', rawurlencode( $notice ), admin_url( 'options-general.php?page=wuo-agent' ) ) );
		exit;
	}

	public static function handle_heartbeat() {
		check_admin_referer( 'wuo_agent_heartbeat' );
		if ( current_user_can( 'manage_options' ) ) {
			WUO_Agent_Client::heartbeat( true );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=wuo-agent' ) );
		exit;
	}

	public static function render() {
		$settings = get_option( WUO_Agent_Client::OPTION, array() );
		$backup   = WUO_Agent_Updraft::status();
		$health   = WUO_Agent_Health::collect( false );
		$mode     = class_exists( 'WUO_Agent_Control' ) ? WUO_Agent_Control::effective_mode() : 'total';
		$last_cron = absint( get_option( 'wuo_agent_last_cron', 0 ) );
		$next_poll = absint( $settings['next_job_poll'] ?? 0 );
		$last_full = absint( $settings['last_full_health'] ?? 0 );
		$last_master_contact = absint( $settings['last_master_contact'] ?? 0 );
		$master_unreachable_since = class_exists( 'WUO_Agent_Client' ) ? WUO_Agent_Client::master_unreachable_since() : 0;
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$cron_recent = $last_cron && $last_cron >= time() - 20 * MINUTE_IN_SECONDS;
		?>
		<div class="wrap"><h1>Z-Update — Agent</h1>
		<?php if ( ! empty( $_GET['wuo_notice'] ) ) : $notice = sanitize_text_field( wp_unslash( $_GET['wuo_notice'] ) ); ?>
			<div class="notice <?php echo 0 === strpos( $notice, 'error:' ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( preg_replace( '/^(error|success):/', '', $notice ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $master_unreachable_since ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( 'Le maître Z-Update est injoignable depuis ' . human_time_diff( $master_unreachable_since, time() ) . '. Les secours locaux restent appliqués selon la politique reçue.' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! $cron_disabled ) : ?><div class="notice notice-warning"><p>WP-Cron natif est actif. Pour utiliser cron-job.org sans déclenchement à chaque visite, ajoutez <code>define( 'DISABLE_WP_CRON', true );</code> dans <code>wp-config.php</code>.</p></div><?php elseif ( ! $cron_recent ) : ?><div class="notice notice-warning"><p>WP-Cron natif est désactivé, mais aucun appel externe récent n’a été détecté.</p></div><?php endif; ?>
		<?php if ( empty( $settings['site_id'] ) ) : ?>
			<p>Générez un jeton depuis le plugin maître, puis renseignez-le ici.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wuo_agent_pair"><?php wp_nonce_field( 'wuo_agent_pair' ); ?>
				<table class="form-table"><tr><th><label for="master_url">URL du maître</label></th><td><input class="regular-text" type="url" id="master_url" name="master_url" required></td></tr>
				<tr><th><label for="pairing_token">Jeton d’appairage</label></th><td><input class="regular-text" id="pairing_token" name="pairing_token" required autocomplete="off"></td></tr>
			<tr><th><label for="hosting_pool">Hébergement</label></th><td><select id="hosting_pool" name="hosting_pool"><option value="performance-a">Performance A</option><option value="performance-b">Performance B</option><option value="perso">Mutualisé Perso</option><option value="external">Externe</option><option value="ekinox-1">Ekinox 1</option><option value="ekinox-2">Ekinox 2</option><option value="ekinox-3">Ekinox 3</option></select></td></tr></table>
				<?php submit_button( 'Appairer le site' ); ?>
			</form>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px"><tbody>
			<tr><td>Maître</td><td><?php echo esc_html( $settings['master_url'] ); ?></td></tr><tr><td>Hébergement</td><td><?php echo esc_html( $settings['hosting_pool'] ); ?></td></tr>
			<tr><td>Santé</td><td><?php echo $health['healthy'] ? '<span style="color:#008a20">Saine</span>' : '<span style="color:#b32d2e">Dégradée</span>'; ?></td></tr>
			<tr><td>UpdraftPlus</td><td><?php echo esc_html( $backup['complete'] ? 'Sauvegarde complète sur Google Drive' : 'Sauvegarde non vérifiée' ); ?></td></tr>
			<tr><td>Politique</td><td><?php echo esc_html( 'security' === $mode ? 'Secours sécurité WordPress' : 'Contrôle total' ); ?></td></tr>
			<tr><td>Dernier contact maître</td><td><?php echo $last_master_contact ? esc_html( wp_date( 'd/m/Y H:i:s', $last_master_contact ) ) : 'Pas encore détecté'; ?></td></tr>
			<tr><td>Dernier passage WP-Cron</td><td><?php echo $last_cron ? esc_html( human_time_diff( $last_cron, time() ) ) : 'Pas encore détecté'; ?></td></tr>
			<tr><td>Surveillance cron externe</td><td><?php echo $cron_disabled ? ( $cron_recent ? '<span style="color:#008a20">Actif · appel récent</span>' : '<span style="color:#b32d2e">À vérifier · aucun appel récent</span>' ) : '<span style="color:#b32d2e">WP-Cron natif actif</span>'; ?></td></tr>
			<tr><td>Configuration à copier</td><td><code>define( 'DISABLE_WP_CRON', true );</code></td></tr>
			<tr><td>Prochaine recherche de tâche</td><td><?php echo $next_poll ? esc_html( wp_date( 'd/m/Y H:i:s', $next_poll ) ) : 'Au prochain passage'; ?></td></tr>
			<tr><td>Dernier contrôle complet</td><td><?php echo $last_full ? esc_html( human_time_diff( $last_full, time() ) ) : 'Pas encore effectué'; ?></td></tr>
			</tbody></table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wuo_agent_heartbeat"><?php wp_nonce_field( 'wuo_agent_heartbeat' ); ?><?php submit_button( 'Synchroniser maintenant', 'secondary' ); ?></form>
			<details style="margin-top:24px"><summary>Réappairer cet agent</summary><p>Utilisez un nouveau jeton si le maître ou le secret de liaison a changé. Les réglages actuels sont conservés si la tentative échoue.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wuo_agent_pair"><?php wp_nonce_field( 'wuo_agent_pair' ); ?>
				<table class="form-table"><tr><th><label for="master_url_repair">URL du maître</label></th><td><input class="regular-text" type="url" id="master_url_repair" name="master_url" value="<?php echo esc_attr( $settings['master_url'] ); ?>" required></td></tr>
				<tr><th><label for="pairing_token_repair">Nouveau jeton</label></th><td><input class="regular-text" id="pairing_token_repair" name="pairing_token" required autocomplete="off"></td></tr>
			<tr><th><label for="hosting_pool_repair">Hébergement</label></th><td><select id="hosting_pool_repair" name="hosting_pool"><option value="performance-a" <?php selected( $settings['hosting_pool'], 'performance-a' ); ?>>Performance A</option><option value="performance-b" <?php selected( $settings['hosting_pool'], 'performance-b' ); ?>>Performance B</option><option value="perso" <?php selected( $settings['hosting_pool'], 'perso' ); ?>>Mutualisé Perso</option><option value="external" <?php selected( $settings['hosting_pool'], 'external' ); ?>>Externe</option><option value="ekinox-1" <?php selected( $settings['hosting_pool'], 'ekinox-1' ); ?>>Ekinox 1</option><option value="ekinox-2" <?php selected( $settings['hosting_pool'], 'ekinox-2' ); ?>>Ekinox 2</option><option value="ekinox-3" <?php selected( $settings['hosting_pool'], 'ekinox-3' ); ?>>Ekinox 3</option></select></td></tr></table>
				<?php submit_button( 'Réappairer', 'secondary' ); ?>
			</form></details>
		<?php endif; ?></div><?php
	}
}
