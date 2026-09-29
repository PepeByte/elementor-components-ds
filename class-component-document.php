<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor library document for synced Components.
 *
 * Loaded only from elementor/documents/register, after Elementor's library
 * document base class is available.
 */
class EC_Component_Document extends \Elementor\Modules\Library\Documents\Library_Document {

	public function get_name() {
		return 'component';
	}

	public static function get_type() {
		return 'component';
	}

	public static function get_title() {
		return esc_html__( 'Component', 'elementor-components' );
	}

	public static function get_plural_title() {
		return esc_html__( 'Components', 'elementor-components' );
	}

	public static function get_properties() {
		$properties = parent::get_properties();
		$properties['support_kit'] = true;
		$properties['show_in_finder'] = true;
		return $properties;
	}
}
