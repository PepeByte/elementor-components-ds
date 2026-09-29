<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EC_Component_Widget {

	public static function init(): void {
		add_action( 'elementor/elements/categories_registered', [ __CLASS__, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ __CLASS__, 'register_widget' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ __CLASS__, 'enqueue_editor_assets' ] );
	}

	public static function register_category( $elements_manager ): void {
		$elements_manager->add_category( 'ec-components', [
			'title' => esc_html__( 'Components', 'elementor-components' ),
			'icon'  => 'eicon-star',
		] );
	}

	public static function register_widget( $widgets_manager ): void {
		// Required here, after Elementor's nested widget base is loadable.
		require_once EC_PATH . 'class-component-instance-widget.php';
		$widgets_manager->register( new EC_Component_Instance_Widget() );
	}

	public static function enqueue_editor_assets(): void {
		wp_enqueue_script( 'ec-editor', EC_URL . 'assets/editor.js', [ 'jquery', 'elementor-editor' ], EC_VERSION, true );
		wp_enqueue_style( 'ec-editor', EC_URL . 'assets/editor.css', [], EC_VERSION );
		wp_localize_script( 'ec-editor', 'ElementorComponents', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'ec_save_component' ),
			'shadowNonce' => wp_create_nonce( EC_Component_Sync_Manager::NONCE_ACTION ),
			'consumerId'  => absint( $_GET['post'] ?? get_the_ID() ),
			'editBase'    => admin_url( 'post.php?action=elementor&post=' ),
		] );
	}
}
