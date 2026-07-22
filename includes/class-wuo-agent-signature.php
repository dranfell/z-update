<?php
defined( 'ABSPATH' ) || exit;

final class WUO_Agent_Signature {
	public static function headers( $method, $route, $body, $site_id, $secret ) {
		$timestamp = time();
		$nonce     = wp_generate_uuid4();
		$canonical = self::canonical( $timestamp, $nonce, $method, $route, $body );
		$signature = base64_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );

		return array(
			'X-WUO-Site'      => (string) $site_id,
			'X-WUO-Timestamp' => (string) $timestamp,
			'X-WUO-Nonce'     => $nonce,
			'X-WUO-Signature' => $signature,
			'Content-Type'    => 'application/json',
		);
	}

	public static function canonical( $timestamp, $nonce, $method, $route, $body ) {
		return implode( "\n", array(
			(string) $timestamp,
			(string) $nonce,
			strtoupper( (string) $method ),
			'/' . ltrim( (string) $route, '/' ),
			hash( 'sha256', (string) $body ),
		) );
	}
}

