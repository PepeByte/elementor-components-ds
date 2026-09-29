<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "component" template type on Elementor's elementor_library CPT.
 * Components live alongside Saved Sections / Pages / Global Widgets in the library.
 */
class EC_Component_CPT {

	const TYPE = 'component';

	// Cached picker list. get_components() runs whenever the instance widget's
	// controls are built, including on frontend renders, so it must not hit the
	// database each time.
	const LIST_CACHE = 'ec_components_list';

	public static function init(): void {
		add_action( 'elementor/documents/register', [ __CLASS__, 'register_document_type' ] );

		// Anything that can add, remove, rename, publish or retype a component.
		add_action( 'save_post_elementor_library', [ __CLASS__, 'flush_cache' ] );
		add_action( 'deleted_post', [ __CLASS__, 'flush_cache' ] );
		add_action( 'trashed_post', [ __CLASS__, 'flush_cache' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'flush_cache' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'flush_cache_for_terms' ], 10, 4 );
		add_action( 'elementor/document/after_save', [ __CLASS__, 'flush_cache' ] );
	}

	public static function flush_cache(): void {
		delete_transient( self::LIST_CACHE );
	}

	public static function flush_cache_for_terms( $object_id = 0, $terms = [], $tt_ids = [], $taxonomy = '' ): void {
		if ( 'elementor_library_type' === $taxonomy ) {
			self::flush_cache();
		}
	}

	public static function register_document_type( $documents_manager ): void {
		require_once EC_PATH . 'class-component-document.php';
		$documents_manager->register_document_type(
			self::TYPE,
			EC_Component_Document::class
		);
	}

	/**
	 * All published components as [ post_id => title ].
	 */
	public static function get_components(): array {
		$cached = get_transient( self::LIST_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$posts = get_posts( [
			'post_type'              => 'elementor_library',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => [ [
				'taxonomy' => 'elementor_library_type',
				'field'    => 'slug',
				'terms'    => self::TYPE,
			] ],
		] );

		$options = [];
		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}
		set_transient( self::LIST_CACHE, $options, DAY_IN_SECONDS );
		return $options;
	}
}
