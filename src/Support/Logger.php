<?php
/**
 * Logger.
 *
 * Thin wrapper over the WooCommerce logger (source: mibizum-search). Never logs
 * secret values (API keys); callers must not pass keys in the context. Mirrors
 * Helper::log.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Support;

defined( 'ABSPATH' ) || exit;

class Logger {

	const SOURCE = 'mibizum-search';

	/**
	 * @param string $message
	 * @param string $level   One of WC_Log_Levels (info, warning, error, debug).
	 * @param array  $context Optional structured context. Must not contain keys.
	 * @return void
	 */
	public function log( $message, $level = 'info', array $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->log( $level, (string) $message, array_merge( array( 'source' => self::SOURCE ), $context ) );
				return;
			}
		}
		// Fallback when the WooCommerce logger is unavailable.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[' . self::SOURCE . '][' . $level . '] ' . $message );
	}

	/**
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( $message, 'error', $context );
	}

	/**
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( $message, 'warning', $context );
	}

	/**
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( $message, 'info', $context );
	}

	/**
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( $message, 'debug', $context );
	}
}
