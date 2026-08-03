<?php

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
define( 'WUO_AGENT_FILE', dirname( __DIR__ ) . '/wp-update-orchestrator-agent.php' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['test_options'] = array();
$GLOBALS['remote_response'] = null;

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

final class WUO_Agent_Crypto {
	public static function decrypt( $value ) { return $value; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_generate_uuid4() { return '12345678-1234-4234-8234-123456789abc'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function add_filter() { return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['test_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['test_options'][ $name ] ); return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function home_url( $path = '' ) { return 'https://agent.example' . $path; }
function get_bloginfo( $field ) { return 'name' === $field ? 'Agent test' : '6.8.3'; }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?: time() ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_mail() { return true; }
function wp_safe_remote_request() { return $GLOBALS['remote_response']; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? ( $response['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }

require dirname( __DIR__ ) . '/includes/class-wuo-agent-signature.php';
require dirname( __DIR__ ) . '/includes/class-z-update-package-verifier.php';
require dirname( __DIR__ ) . '/includes/class-z-update-agent-updater.php';
require dirname( __DIR__ ) . '/includes/class-wuo-agent-runner.php';
require dirname( __DIR__ ) . '/includes/class-wuo-agent-client.php';

$failures = 0;
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "ECHEC: {$message}\n" );
	}
};

$canonical = WUO_Agent_Signature::canonical( 100, 'nonce', 'post', 'wp-json/wuo/v1/jobs/result', '{}' );
$assert( "100\nnonce\nPOST\n/wp-json/wuo/v1/jobs/result\n" . hash( 'sha256', '{}' ) === $canonical, 'La canonicalisation HMAC doit rester stable.' );
$assert( 'https://github.com/zimages/z-update' === Z_Update_Package_Verifier::valid_repository( 'https://github.com/zimages/z-update.git/' ), 'Le dépôt GitHub attendu doit être accepté.' );
$assert( '' === Z_Update_Package_Verifier::valid_repository( 'https://example.com/zimages/z-update' ), 'Un autre hôte doit être refusé.' );
$metadata_url = new ReflectionMethod( Z_Update_Agent_Updater::class, 'metadata_url' );
$metadata_url->setAccessible( true );
$assert(
	'https://github.com/zimages/z-update/releases/latest/download/z-update-agent-update.json' === $metadata_url->invoke( null, 'https://github.com/zimages/z-update' ),
	'PUC doit utiliser le manifest JSON statique au lieu de l’API GitHub.'
);

$versions_match = new ReflectionMethod( WUO_Agent_Runner::class, 'versions_match' );
$versions_match->setAccessible( true );
$assert( true === $versions_match->invoke( null, '1.2.3', '1.2.3' ), 'Une version exacte doit être acceptée.' );
$assert( false === $versions_match->invoke( null, '1.2.3', '1.2.4' ), 'Une version différente doit être refusée.' );
$assert( false === $versions_match->invoke( null, '', '' ), 'Une cible vide doit être refusée.' );
$runner_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wuo-agent-runner.php' );
$assert( false === strpos( $runner_source, 'core_approval_required' ), 'Les mises à jour WordPress orchestrées ne doivent plus exiger une validation manuelle.' );
$client_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wuo-agent-client.php' );
$admin_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wuo-agent-admin.php' );
$updraft_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wuo-agent-updraft.php' );
$assert( false !== strpos( $client_source, 'maybe_alert_master_unreachable' ), 'L’agent doit alerter localement si le maître dépasse le délai de secours.' );
$assert( false !== strpos( $admin_source, 'Dernier contact maître' ), 'L’écran agent doit afficher le dernier contact maître.' );
$assert( false !== strpos( $admin_source, 'ekinox-1' ), 'L’agent doit proposer les pools Ekinox.' );
$assert( false !== strpos( $updraft_source, 'running_since' ), 'L’agent doit dater les sauvegardes UpdraftPlus en cours.' );
$assert( false !== strpos( $updraft_source, 'RUNNING_MAX_AGE' ), 'Le marqueur UpdraftPlus en cours ne doit pas rester bloquant trop longtemps.' );

$GLOBALS['test_options'][ WUO_Agent_Client::OPTION ] = array(
	'master_url' => 'https://master.example',
	'site_id'    => 12,
	'secret_enc' => 'secret',
);
$queue_result = new ReflectionMethod( WUO_Agent_Client::class, 'queue_result' );
$flush_result = new ReflectionMethod( WUO_Agent_Client::class, 'flush_pending_result' );
$queue_result->setAccessible( true );
$flush_result->setAccessible( true );
$result = array( 'success' => true, 'code' => 'completed' );
$queue_result->invoke( null, 42, $result );
$assert( 42 === get_option( WUO_Agent_Client::PENDING_RESULT_OPTION )['job_id'], 'Le résultat doit être persisté avant son envoi.' );

$GLOBALS['remote_response'] = new WP_Error( 'offline', 'Maître indisponible.' );
$assert( is_wp_error( $flush_result->invoke( null ) ), 'Une panne réseau doit être remontée.' );
$pending = get_option( WUO_Agent_Client::PENDING_RESULT_OPTION );
$assert( 1 === $pending['attempts'], 'La tentative échouée doit être comptabilisée.' );

$GLOBALS['remote_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
$assert( true === $flush_result->invoke( null ), 'La retransmission doit réussir après le retour du maître.' );
$assert( false === get_option( WUO_Agent_Client::PENDING_RESULT_OPTION, false ), 'Le résultat acquitté doit être supprimé.' );

if ( $failures ) {
	exit( 1 );
}

echo "Agent: tests réussis.\n";
