<?php
/**
 * Secret storage helper.
 *
 * WordPress has no built in encrypted config like Magento. We encrypt the API
 * keys at rest with AES-256-GCM. The encryption key is derived from a dedicated
 * wp-config constant MIBIZUM_SEARCH_KEY when present, otherwise from
 * wp_salt('secure_auth').
 *
 * This is hardening, not absolute secrecy: a dump of both the database AND
 * wp-config defeats it. The indexer key is the sensitive one (write scope); the
 * search key is read scope and also travels in the public widget snippet by
 * design.
 *
 * Tokens are prefixed with a version tag so a value stored in cleartext by
 * mistake is tolerated (returned as is), mirroring the Magento helper.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Support;

defined( 'ABSPATH' ) || exit;

class Crypto {

	const CIPHER  = 'aes-256-gcm';
	const PREFIX  = 'v1:';
	const TAG_LEN = 16;

	/**
	 * Encrypt a cleartext secret into a base64 token (prefix + iv + tag + ct).
	 *
	 * @param string $plain
	 * @return string '' for empty input.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Last resort: store reversibly encoded so it is not human readable.
			return self::PREFIX . 'b64:' . base64_encode( $plain );
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = random_bytes( $iv_len );
		$tag     = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return '';
		}
		return self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt a token produced by encrypt(). Returns '' on any failure so the
	 * caller treats it as "not configured" rather than throwing. A value without
	 * our prefix is assumed to be cleartext and returned as is.
	 *
	 * @param string $token
	 * @return string
	 */
	public static function decrypt( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return '';
		}
		if ( 0 !== strpos( $token, self::PREFIX ) ) {
			return $token; // tolerate cleartext stored by mistake.
		}
		$payload = substr( $token, strlen( self::PREFIX ) );

		if ( 0 === strpos( $payload, 'b64:' ) ) {
			$decoded = base64_decode( substr( $payload, 4 ), true );
			return false === $decoded ? '' : $decoded;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( $payload, true );
		if ( false === $raw ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) < $iv_len + self::TAG_LEN ) {
			return '';
		}
		$iv     = substr( $raw, 0, $iv_len );
		$tag    = substr( $raw, $iv_len, self::TAG_LEN );
		$cipher = substr( $raw, $iv_len + self::TAG_LEN );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive the 32 byte encryption key.
	 *
	 * @return string
	 */
	private static function key() {
		$material = defined( 'MIBIZUM_SEARCH_KEY' ) ? MIBIZUM_SEARCH_KEY : wp_salt( 'secure_auth' );
		return hash( 'sha256', $material, true );
	}
}
