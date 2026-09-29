<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint: receives a serialized Elementor element from the editor
 * and stores it as a new component (elementor_library post).
 */
class EC_Component_Save_Handler {

	// Ceilings on per-user component count and per-tree size/depth, so the
	// shared library and the sync fan-out cannot be used as a storage/DoS sink.
	const MAX_COMPONENTS_PER_USER = 200;
	const MAX_ELEMENT_DEPTH       = 20;
	const MAX_ELEMENT_COUNT       = 2000;

	public static function init(): void {
		add_action( 'wp_ajax_ec_save_component', [ __CLASS__, 'handle_save' ] );
	}

	public static function handle_save(): void {
		check_ajax_referer( 'ec_save_component', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'elementor-components' ) ], 403 );
		}

		if ( self::user_component_count() >= self::MAX_COMPONENTS_PER_USER ) {
			wp_send_json_error( [ 'message' => __( 'Component limit reached.', 'elementor-components' ) ], 429 );
		}

		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$raw   = wp_unslash( $_POST['elements'] ?? '' );
		$data  = json_decode( $raw, true );

		if ( empty( $title ) || ! is_array( $data ) || empty( $data['elType'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid component data.', 'elementor-components' ) ], 400 );
		}

		$count = 0;
		if ( ! self::validate_element_tree( $data, 0, $count ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid component data.', 'elementor-components' ) ], 400 );
		}

		// Nested component instances can host containers, not legacy sections.
		if ( 'widget' === $data['elType'] ) {
			$data = [
				'id'       => substr( md5( uniqid( '', true ) ), 0, 7 ),
				'elType'   => 'container',
				'settings' => [],
				'elements' => [ $data ],
			];
		} elseif ( 'column' === $data['elType'] ) {
			$data['elType'] = 'container';
			unset( $data['isInner'] );
		}

		// Mirror Elementor's own save gate (core/base/document.php save()):
		// users without unfiltered_html get their data kses-filtered, so a
		// low-privileged editor cannot plant raw <script> content into the
		// site-wide shared library (stored XSS). Structure (ids, elType,
		// settings/elements shape) is untouched — only string values are
		// filtered, exactly like a normal Elementor save.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$data = EC_Component_Sync_Manager::kses_post_deep( $data );
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'elementor_library',
			'post_title'  => $title,
			// Match Elementor's own template save: publish only for users with
			// publish_posts, else pending (moderated) — components only enter
			// the shared library once published.
			'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
		], true );

		if ( is_wp_error( $post_id ) ) {
			// Log the underlying error for operators; do not echo it to the caller.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Elementor Components: failed to create component post: ' . $post_id->get_error_message() );
			wp_send_json_error( [ 'message' => __( 'Could not save the component. Please try again.', 'elementor-components' ) ], 500 );
		}

		wp_set_object_terms( $post_id, EC_Component_CPT::TYPE, 'elementor_library_type' );

		// Raw layout JSON, same as Elementor's own save path. Trust boundary:
		// editors only (edit_posts + nonce) with Elementor's unfiltered_html
		// kses gate applied above — deep sanitizing beyond that would break
		// valid Elementor/HTML content.
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( [ $data ] ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', EC_Component_CPT::TYPE );
		update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' );
		update_post_meta( $post_id, '_elementor_page_settings', [] );

		// save_post fired before the type term and meta existed; refresh the picker list now.
		EC_Component_CPT::flush_cache();

		// Generate the component's CSS file so instances are styled on first render.
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			} catch ( \Throwable $e ) {
				// Non-fatal: Elementor regenerates the file on enqueue if missing.
			}
		}

		wp_send_json_success( [
			'id'       => $post_id,
			'title'    => $title,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
		] );
	}

	/**
	 * Number of components the current user already owns (published or pending).
	 */
	private static function user_component_count(): int {
		$query = new \WP_Query( [
			'post_type'        => 'elementor_library',
			'post_status'      => [ 'publish', 'pending', 'draft' ],
			'author'           => get_current_user_id(),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
			'tax_query'        => [ [
				'taxonomy' => 'elementor_library_type',
				'field'    => 'slug',
				'terms'    => EC_Component_CPT::TYPE,
			] ],
		] );
		return (int) $query->found_posts;
	}

	/**
	 * Structural validation for a submitted element tree: whitelisted element
	 * types, well-formed settings/elements buckets, bounded depth and size.
	 * Only shape is checked — string values are filtered later by the kses gate.
	 *
	 * @param array   $element Element (or root) to validate.
	 * @param int     $depth   Current nesting depth (root is 0).
	 * @param int     $count   Running total of visited elements (by reference).
	 */
	public static function validate_element_tree( array $element, int $depth, int &$count ): bool {
		$count++;
		if ( $count > self::MAX_ELEMENT_COUNT || $depth > self::MAX_ELEMENT_DEPTH ) {
			return false;
		}

		if ( ! is_string( $element['id'] ?? null ) || '' === $element['id'] ) {
			return false;
		}

		if ( ! in_array( $element['elType'] ?? '', [ 'widget', 'container', 'section', 'column' ], true ) ) {
			return false;
		}

		if ( isset( $element['settings'] ) && ! is_array( $element['settings'] ) ) {
			return false;
		}
		if ( isset( $element['elements'] ) && ! is_array( $element['elements'] ) ) {
			return false;
		}

		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) {
			if ( ! is_array( $child ) || ! self::validate_element_tree( $child, $depth + 1, $count ) ) {
				return false;
			}
		}
		return true;
	}
}
