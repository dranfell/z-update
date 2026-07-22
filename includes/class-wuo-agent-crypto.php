<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Crypto {
	private static function key() {
		return hash( 'sha256', AUTH_KEY . SECURE_AUTH_SALT, true );
	}

	public static function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'openssl_missing', 'OpenSSL est requis pour protéger le secret de l’agent.' );
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return new WP_Error( 'secret_encryption_failed', 'Le secret de l’agent n’a pas pu être chiffré.' );
		}
		return base64_encode( $iv . $tag . $ciphertext );
	}

	public static function decrypt( $encoded ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'openssl_missing', 'OpenSSL est requis pour lire le secret de l’agent.' );
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return new WP_Error( 'invalid_secret', 'Le secret chiffré de l’agent est invalide.' );
		}
		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $plaintext ) {
			return new WP_Error( 'secret_decryption_failed', 'Le secret de l’agent n’a pas pu être déchiffré.' );
		}
		return $plaintext;
	}
}
