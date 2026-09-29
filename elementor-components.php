<?php
/**
 * Plugin Name:       Elementor Components
 * Description:       Right-click any Elementor widget, container, or section to save it as a synced reusable component. Edit the parent once — every instance updates.
 * Version:           1.3.1
 * Author:            Sandesh
 * Text Domain:       elementor-components
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EC_VERSION', '1.3.1' );
define( 'EC_PATH', plugin_dir_path( __FILE__ ) );
define( 'EC_URL', plugin_dir_url( __FILE__ ) );

// Hard floor: Elementor\Modules\NestedElements\Base\Widget_Nested_Base exists
// since Elementor 3.8. The plugin is developed and tested against 4.1.5.
define( 'EC_MIN_ELEMENTOR_VERSION', '3.8.0' );

add_action( 'plugins_loaded', 'ec_bootstrap' );

function ec_bootstrap(): void {
	load_plugin_textdomain( 'elementor-components', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', 'ec_missing_elementor_notice' );
		return;
	}

	if ( ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, EC_MIN_ELEMENTOR_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'ec_old_elementor_notice' );
		return;
	}

	require_once EC_PATH . 'class-component-cpt.php';
	require_once EC_PATH . 'class-component-overrides.php';
	require_once EC_PATH . 'class-component-shadow-sync.php';
	require_once EC_PATH . 'class-component-sync-manager.php';
	require_once EC_PATH . 'class-component-save-handler.php';
	require_once EC_PATH . 'class-component-widget.php';
	require_once EC_PATH . 'class-component-admin.php';

	EC_Component_CPT::init();
	EC_Component_Overrides::init();
	EC_Component_Sync_Manager::init();
	EC_Component_Save_Handler::init();
	EC_Component_Widget::init();
	EC_Component_Admin::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once EC_PATH . 'class-component-cli.php';
		\WP_CLI::add_command( 'ec', 'EC_Component_CLI' );
	}
}

function ec_missing_elementor_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Elementor Components requires Elementor to be installed and active.', 'elementor-components' ) .
		'</p></div>';
}

function ec_old_elementor_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	/* translators: 1: required minimum Elementor version, 2: tested Elementor version. */
	echo '<div class="notice notice-error"><p>' .
		sprintf(
			esc_html__( 'Elementor Components requires Elementor %1$s or newer (tested with %2$s). Please update Elementor.', 'elementor-components' ),
			esc_html( EC_MIN_ELEMENTOR_VERSION ),
			esc_html( '4.1.5' )
		) .
		'</p></div>';
}
