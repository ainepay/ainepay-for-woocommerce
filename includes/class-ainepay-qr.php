<?php
/**
 * Server-side QR code generation as inline SVG.
 *
 * Uses bacon/bacon-qr-code (a Composer dependency shipped in the release zip).
 * If the library is unavailable the methods degrade gracefully to no output,
 * so the payment page never breaks or loads remote code.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generates QR codes as inline SVG markup.
 */
class Ainepay_Qr {

	/**
	 * Whether QR generation is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\BaconQrCode\Writer' );
	}

	/**
	 * Render the given text as an inline SVG QR code.
	 *
	 * @param string $text Text to encode (e.g. a payment address).
	 * @param int    $size Pixel size of the square QR code.
	 * @return string Inline SVG markup, or '' when unavailable / on error.
	 */
	public static function svg( $text, $size = 180 ) {
		if ( ! self::is_available() || '' === (string) $text ) {
			return '';
		}
		try {
			$renderer = new ImageRenderer(
				new RendererStyle( (int) $size, 1 ),
				new SvgImageBackEnd()
			);
			$writer   = new Writer( $renderer );
			$svg      = $writer->writeString( (string) $text );
			// Strip any leading XML prolog so the SVG can be embedded inline in
			// HTML without an invalid processing instruction.
			$svg = preg_replace( '/^\s*<\?xml[^>]*\?>\s*/i', '', $svg );
			return $svg;
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
