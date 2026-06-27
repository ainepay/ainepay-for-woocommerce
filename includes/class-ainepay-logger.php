<?php
/**
 * Thin wrapper around WC_Logger with secret redaction.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logging helper. Routes to the WooCommerce logger under the "ainepay" source,
 * and scrubs known sensitive keys before anything is written.
 */
class Ainepay_Logger {

	const SOURCE = 'ainepay';

	/**
	 * Whether debug logging is enabled (driven by the gateway "debug" setting).
	 *
	 * @var bool
	 */
	private static $enabled = false;

	/**
	 * Keys whose values must never be logged in clear text.
	 *
	 * @var string[]
	 */
	private static $sensitive_keys = array(
		'api_key',
		'api_secret',
		'apikey',
		'apisecret',
		'secret',
		'notify_secret',
		'x-api-key',
		'x-api-signature',
		'signature',
		'password',
	);

	/**
	 * Enable or disable debug logging.
	 *
	 * @param bool $enabled Whether debug logging is on.
	 * @return void
	 */
	public static function set_enabled( $enabled ) {
		self::$enabled = (bool) $enabled;
	}

	/**
	 * Log a debug message (only when debug logging is enabled).
	 *
	 * @param string $message Message text.
	 * @param array  $context Optional structured context (will be redacted).
	 * @return void
	 */
	public static function debug( $message, $context = array() ) {
		if ( ! self::$enabled ) {
			return;
		}
		self::write( 'debug', $message, $context );
	}

	/**
	 * Log an error message (always recorded).
	 *
	 * @param string $message Message text.
	 * @param array  $context Optional structured context (will be redacted).
	 * @return void
	 */
	public static function error( $message, $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Write to the WooCommerce logger.
	 *
	 * @param string $level   PSR log level.
	 * @param string $message Message text.
	 * @param array  $context Structured context.
	 * @return void
	 */
	private static function write( $level, $message, $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! empty( $context ) ) {
			$redacted = self::redact( $context );
			$message .= ' ' . wp_json_encode( $redacted );
		}
		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Recursively replace sensitive values with a fixed mask.
	 *
	 * @param array $data Arbitrary context array.
	 * @return array
	 */
	private static function redact( $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}
			if ( in_array( strtolower( (string) $key ), self::$sensitive_keys, true ) ) {
				$out[ $key ] = '***';
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
