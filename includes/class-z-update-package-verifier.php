<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Z_Update_Package_Verifier', false ) ) {
	final class Z_Update_Package_Verifier {
		private static $targets = array();
		private static $hooked  = false;

		public static function register( $plugin_file, $repository_url, $asset_prefix ) {
			$repository_url = self::valid_repository( $repository_url );
			if ( ! $repository_url || ! function_exists( 'openssl_verify' ) ) {
				return false;
			}
			self::$targets[ plugin_basename( $plugin_file ) ] = array(
				'repository'   => $repository_url,
				'asset_prefix' => sanitize_key( $asset_prefix ),
			);
			if ( ! self::$hooked ) {
				add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 5, 4 );
				self::$hooked = true;
			}
			return true;
		}

		public static function verify_download( $reply, $package, $upgrader, $hook_extra ) {
			if ( false !== $reply ) {
				return $reply;
			}
			$plugins = array();
			if ( ! empty( $hook_extra['plugin'] ) ) {
				$plugins[] = sanitize_text_field( $hook_extra['plugin'] );
			}
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$plugins = array_merge( $plugins, array_map( 'sanitize_text_field', $hook_extra['plugins'] ) );
			}
			$matched = array_intersect_key( self::$targets, array_flip( $plugins ) );
			if ( ! $matched ) {
				return $reply;
			}
			$target = reset( $matched );
			if ( count( $matched ) > 1 ) {
				foreach ( $matched as $candidate ) {
					if ( self::package_belongs_to_target( $package, $candidate ) ) {
						$target = $candidate;
						break;
					}
				}
			}
			if ( ! self::package_belongs_to_target( $package, $target ) ) {
				return new WP_Error( 'z_update_untrusted_package', 'Z-Update a refusé un paquet qui ne correspond pas au dépôt et à l’asset attendus.' );
			}

			$signature_url = self::signature_url( $package );
			$response = wp_safe_remote_get( $signature_url, array( 'timeout' => 30, 'redirection' => 3 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'z_update_signature_missing', 'La signature du paquet Z-Update est absente ou inaccessible.' );
			}
			$encoded = trim( wp_remote_retrieve_body( $response ) );
			if ( ! $encoded || strlen( $encoded ) > 8192 ) {
				return new WP_Error( 'z_update_signature_invalid', 'La signature du paquet Z-Update est invalide.' );
			}
			$signature = base64_decode( $encoded, true );
			if ( false === $signature ) {
				return new WP_Error( 'z_update_signature_invalid', 'La signature du paquet Z-Update n’est pas encodée correctement.' );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temp_file = download_url( $package, 120 );
			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}
			if ( ! is_file( $temp_file ) || filesize( $temp_file ) > 20 * MB_IN_BYTES ) {
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'z_update_package_size', 'Le paquet Z-Update dépasse la taille autorisée.' );
			}

			$contents = file_get_contents( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$verified = false !== $contents && 1 === openssl_verify( $contents, $signature, self::public_key(), OPENSSL_ALGO_SHA256 );
			if ( ! $verified ) {
				@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'z_update_signature_rejected', 'La signature cryptographique du paquet Z-Update ne correspond pas.' );
			}
			return $temp_file;
		}

		public static function valid_repository( $url ) {
			$url = untrailingslashit( esc_url_raw( $url ) );
			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'github.com' !== strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
				return '';
			}
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$path = preg_replace( '/\\.git$/i', '', $path );
			return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $path ) ? 'https://github.com/' . $path : '';
		}

		private static function package_belongs_to_target( $package, array $target ) {
			if ( 'https' !== wp_parse_url( $package, PHP_URL_SCHEME ) || 'github.com' !== strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) ) ) {
				return false;
			}
			$repo_path = trim( (string) wp_parse_url( $target['repository'], PHP_URL_PATH ), '/' );
			$path      = (string) wp_parse_url( $package, PHP_URL_PATH );
			$asset     = basename( $path );
			if ( 0 !== strpos( strtolower( $path ), '/' . strtolower( $repo_path ) . '/releases/download/' ) ) {
				return false;
			}
			$pattern = '#^' . preg_quote( $target['asset_prefix'], '#' ) . '-[0-9][0-9A-Za-z._-]*\\.zip$#i';
			return 1 === preg_match( $pattern, $asset );
		}

		private static function signature_url( $package ) {
			$parts = wp_parse_url( $package );
			$url   = 'https://' . $parts['host'] . $parts['path'] . '.sig';
			return ! empty( $parts['query'] ) ? $url . '?' . $parts['query'] : $url;
		}

		private static function public_key() {
			return "-----BEGIN PUBLIC KEY-----\n"
				. "MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAnvc/hv75/QBsoyNWHEjO\n"
				. "/19dXK2HlVcKI9y7GWVvEIrCvfDYFnMk31zU5cGBgDh7IsTpbfY8JypQKarWlUsA\n"
				. "wIFPb4qS78zNzFURlzJB5YL5nEbfUAnA9SOc9GtJC/aYp+2nnW5ebLsQZNhyPLSE\n"
				. "G2XGSQ89GfcWWo8LCLQJhl5rTv6yKOLfnxsBMXykBETRqidZdlOapnr1K2X2nITn\n"
				. "2Atzqh5s7vaeMr8l1tVObznWlZV1NOBWXrFhmCHlacCPx8P3gvj59QQwUCX61ulN\n"
				. "gPN2WknoWU59rGkgsGT1+TwaKEIbw57kM7SE4MT0JRpJQNVz8Xib6oLvmp1lQgjm\n"
				. "yVdW4KjJUKJeRcDUwQPKx41Scj7H5VHnMwwtuOX+B9EblMjBmYNxy1hJLRSlpTY6\n"
				. "/FOueEzjXc/o1xY7ot8AixJkg03mQpvTHyBQUSb8xAVJTjDq4e5HCx1WAMUAw3CI\n"
				. "IZhfzJSzJeY1h7ciR7hrOT72Tr7RD4zejLTYA39ACguNAgMBAAE=\n"
				. "-----END PUBLIC KEY-----\n";
		}
	}
}
