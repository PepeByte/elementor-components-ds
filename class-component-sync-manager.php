<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor/WordPress integration for locked component shadows.
 */
final class EC_Component_Sync_Manager {

	const REFERENCE_META = '_ec_component_ref';
	const NONCE_ACTION   = 'ec_component_shadow';

	// Elementor's per-document rendered-HTML cache (Document::CACHE_META_KEY).
	// Document::save() clears it; every write we make outside save() must too,
	// or the frontend keeps serving the pre-sync markup for up to the cache TTL.
	const ELEMENTOR_CACHE_META = '_elementor_element_cache';

	// A component save syncs this many consumers inline; the rest continue in
	// WP-Cron batches of the same size. Nothing is dropped, and no single
	// request has to rewrite an unbounded number of pages.
	const BATCH_SIZE = 50;
	const CRON_HOOK  = 'ec_sync_consumers_batch';
	const QUEUE_KEY  = 'ec_sync_queue_';
	const HEAL_LOCK  = 'ec_heal_';

	// Component meta: "<md5 of parent data>:<parent_version>". Lets a render or
	// batch compare versions without decoding and hashing the parent tree.
	const VERSION_META = '_ec_component_version';

	// Instances the editor may sync in one request.
	const MAX_BATCH_INSTANCES = 100;

	// After a rebuild the page's CSS file is gone. Cron batches regenerate it
	// (a few pages per run, within a time budget) so the first visitor does not
	// pay for it, and a large fan-out does not turn into a cold-render stampede.
	const WARM_BATCH  = 10;
	const WARM_BUDGET = 10; // seconds per cron run

	// Opt-in: store instances without their shadow children (see is_compact_storage()).
	const COMPACT_OPTION = 'ec_compact_storage';

	/** @var array<int, string|null> Current parent version per component, per request. */
	private static $versions = [];

	public static function init(): void {
		add_filter( 'elementor/document/save/data', [ __CLASS__, 'filter_document_save' ], 20, 2 );
		add_action( 'elementor/document/after_save', [ __CLASS__, 'after_document_save' ], 20, 2 );
		add_action( 'wp_ajax_ec_component_shadow', [ __CLASS__, 'ajax_shadow' ] );
		add_action( 'wp_ajax_ec_component_shadow_batch', [ __CLASS__, 'ajax_shadow_batch' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_queued_batch' ] );
		// Fires before Elementor walks an element's children for CSS.
		add_action( 'elementor/element/before_parse_css', [ __CLASS__, 'inject_children_for_css' ], 10, 2 );
		// Runs before Elementor reads its render cache or enqueues the page CSS.
		add_filter( 'elementor/frontend/builder_content_data', [ __CLASS__, 'filter_frontend_content' ], 5, 2 );
	}

	/**
	 * Frontend safety net. If a consumer still holds a shadow of an older parent
	 * (missed by the fan-out, restored from a revision, created through an API
	 * that never ran the editor sync, ...), rebuild it now, persist it once, and
	 * drop the caches derived from the stale copy. Instances whose parent is
	 * unavailable are left alone rather than emptied.
	 */
	public static function filter_frontend_content( $data, $post_id = 0 ) {
		$post_id = (int) $post_id;
		if ( ! is_array( $data ) || ! $data || ! $post_id || self::is_editor_request() || self::is_component_post( $post_id ) ) {
			return $data;
		}

		$changed = false;
		$fresh   = self::refresh_stale_instances( $data, $changed );
		if ( ! $changed ) {
			return $data;
		}

		// One writer at a time; concurrent requests still render the fresh tree.
		$lock = self::HEAL_LOCK . $post_id;
		if ( ! get_transient( $lock ) ) {
			set_transient( $lock, 1, 30 );
			self::write_consumer( $post_id, $fresh );
		}
		return $fresh;
	}

	private static function is_editor_request(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		return ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) ||
			( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );
	}

	public static function filter_document_save( array $data, $document ): array {
		if ( self::is_component_document( $document ) || ! isset( $data['elements'] ) ) {
			return $data;
		}

		$data['elements'] = self::reconcile_document_elements( (array) $data['elements'] );
		return $data;
	}

	public static function reconcile_document_elements( array $elements ): array {
		foreach ( $elements as &$element ) {
			if ( 'component_instance' === ( $element['widgetType'] ?? '' ) ) {
				$element = self::reconcile_instance( $element );
				continue;
			}

			$element['elements'] = self::reconcile_document_elements( (array) ( $element['elements'] ?? [] ) );
		}
		unset( $element );
		return $elements;
	}

	public static function after_document_save( $document, array $data ): void {
		$post_id = (int) $document->get_main_id();
		if ( self::is_component_document( $document ) ) {
			self::resync( $post_id );
			return;
		}

		self::update_reference_index( $post_id, (array) ( $data['elements'] ?? [] ) );
	}

	/**
	 * Elementor's save gate for users without unfiltered_html
	 * (Utils::kses_post_deep in core/base/document.php), with a
	 * WordPress-core fallback. Used on every write path this plugin owns that
	 * bypasses Elementor's own Document::save() sanitization.
	 */
	public static function kses_post_deep( $data ) {
		if ( class_exists( '\Elementor\Utils' ) && method_exists( '\Elementor\Utils', 'kses_post_deep' ) ) {
			return \Elementor\Utils::kses_post_deep( $data );
		}
		return wp_kses_post_deep( $data );
	}

	public static function ajax_shadow(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$consumer_id = absint( $_POST['consumer_id'] ?? 0 );
		$component_id = absint( $_POST['component_id'] ?? 0 );
		$instance_id  = sanitize_key( wp_unslash( $_POST['instance_id'] ?? '' ) );
		if ( ! $consumer_id || ! $component_id || ! $instance_id ||
			! current_user_can( 'edit_post', $consumer_id ) ||
			! current_user_can( 'edit_post', $component_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'elementor-components' ) ], 403 );
		}

		$parent = self::load_parent_elements( $component_id );
		if ( null === $parent ) {
			wp_send_json_error( [ 'message' => __( 'Invalid component.', 'elementor-components' ) ], 404 );
		}

		$allowed = self::allowed_by_type( $parent );
		$result  = EC_Component_Shadow_Sync::reconcile(
			$parent,
			EC_Component_Overrides::decode( wp_unslash( $_POST['overrides'] ?? '' ) ),
			$instance_id,
			$allowed
		);

		wp_send_json_success( self::shadow_payload( $result ) );
	}

	/**
	 * One request for every instance on a page. The editor used to send one
	 * request per instance, up to three times per load.
	 */
	public static function ajax_shadow_batch(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$consumer_id = absint( $_POST['consumer_id'] ?? 0 );
		if ( ! $consumer_id || ! current_user_can( 'edit_post', $consumer_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'elementor-components' ) ], 403 );
		}

		$items = json_decode( (string) wp_unslash( $_POST['instances'] ?? '' ), true );
		if ( ! is_array( $items ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'elementor-components' ) ], 400 );
		}

		wp_send_json_success( [ 'results' => self::build_shadow_batch( $items ) ] );
	}

	/**
	 * Shadow payloads keyed by the instance id the caller sent. Work that only
	 * depends on the component (parent load, allowed controls, source settings,
	 * policies) is done once per component, not once per instance.
	 *
	 * An item that reports the parent_version it already holds, and already has
	 * children, gets its map/overrides back but no `elements`: the editor keeps
	 * its live children instead of deleting and recreating them.
	 */
	public static function build_shadow_batch( array $items ): array {
		$results   = [];
		$can       = [];
		$parents   = [];
		$allowed   = [];
		$component = [];

		foreach ( array_slice( $items, 0, self::MAX_BATCH_INSTANCES ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key          = (string) ( $item['instance_id'] ?? '' );
			$instance_id  = sanitize_key( $key );
			$component_id = absint( $item['component_id'] ?? 0 );
			if ( '' === $key || ! $instance_id || ! $component_id ) {
				continue;
			}

			if ( ! isset( $can[ $component_id ] ) ) {
				$can[ $component_id ] = current_user_can( 'edit_post', $component_id );
			}
			if ( ! $can[ $component_id ] ) {
				$results[ $key ] = [ 'error' => __( 'Permission denied.', 'elementor-components' ) ];
				continue;
			}

			if ( ! array_key_exists( $component_id, $parents ) ) {
				$parents[ $component_id ] = self::load_parent_elements( $component_id );
				$allowed[ $component_id ] = null === $parents[ $component_id ] ? [] : self::allowed_by_type( $parents[ $component_id ] );
			}
			if ( null === $parents[ $component_id ] ) {
				$results[ $key ] = [ 'error' => __( 'Invalid component.', 'elementor-components' ) ];
				continue;
			}

			$result = EC_Component_Shadow_Sync::reconcile(
				$parents[ $component_id ],
				EC_Component_Overrides::decode( (string) ( $item['overrides'] ?? '' ) ),
				$instance_id,
				$allowed[ $component_id ]
			);

			if ( ! isset( $component[ $component_id ] ) ) {
				$component[ $component_id ] = self::source_details( $result['source_tree'] );
			}
			unset( $result['source_tree'] );

			$payload = $result + $component[ $component_id ];
			if ( ! empty( $item['has_children'] ) &&
				hash_equals( $result['parent_version'], (string) ( $item['parent_version'] ?? '' ) ) ) {
				unset( $payload['elements'] );
			}
			$results[ $key ] = [ 'data' => $payload ];
		}

		return $results;
	}

	private static function shadow_payload( array $result ): array {
		$details = self::source_details( $result['source_tree'] );
		unset( $result['source_tree'] );
		return $result + $details;
	}

	/**
	 * Parent-side settings and control policy per source id (identical for every
	 * instance of a component).
	 */
	private static function source_details( array $source_tree ): array {
		$source_settings = [];
		$policies        = [];
		$policy_cache    = [];
		foreach ( EC_Component_Shadow_Sync::index_elements( $source_tree ) as $source_id => $element ) {
			$type                          = EC_Component_Shadow_Sync::element_type( $element );
			$source_settings[ $source_id ] = (array) ( $element['settings'] ?? [] );
			$policies[ $source_id ]        = $policy_cache[ $type ] ??= EC_Component_Overrides::get_control_policy( $type );
		}
		return [
			'sourceSettings' => $source_settings,
			'policies'       => $policies,
		];
	}

	private static function reconcile_instance( array $instance ): array {
		$settings     = (array) ( $instance['settings'] ?? [] );
		$component_id = absint( $settings['component_id'] ?? 0 );
		$parent       = self::load_parent_elements( $component_id );
		if ( null === $parent ) {
			$instance['elements'] = [];
			return $instance;
		}

		$allowed = self::allowed_by_type( $parent );
		$patch   = EC_Component_Overrides::decode( $settings['ec_overrides'] ?? '' );
		$base    = EC_Component_Shadow_Sync::reconcile(
			$parent,
			$patch,
			(string) ( $instance['id'] ?? '' ),
			$allowed
		);

		$map     = self::decode_array( $settings['ec_shadow_map'] ?? [] );
		$version = (string) ( $settings['ec_parent_version'] ?? '' );
		if ( $instance['elements'] ?? [] ) {
			if ( hash_equals( $base['parent_version'], $version ) ) {
				$patch = EC_Component_Shadow_Sync::extract_overrides(
					$parent,
					(array) $instance['elements'],
					$map ?: $base['map'],
					$patch,
					$allowed
				);
				$base = EC_Component_Shadow_Sync::reconcile(
					$parent,
					$patch,
					(string) ( $instance['id'] ?? '' ),
					$allowed
				);
			}
		}

		// Compact storage keeps only the sparse overrides and version stamp; the
		// shadow is rebuilt from the parent at render/CSS time (expand_instance()).
		$instance['elements']                      = self::is_compact_storage() ? [] : $base['elements'];
		$instance['settings']['ec_overrides']      = EC_Component_Overrides::encode( $base['overrides'] );
		$instance['settings']['ec_shadow_map']     = wp_json_encode( $base['map'] );
		$instance['settings']['ec_parent_version'] = $base['parent_version'];
		return $instance;
	}

	private static function allowed_by_type( array $parent ): array {
		// Containers always participate (style-only), even when the raw tree has
		// none yet — reconcile may add a wrapper or convert sections to containers.
		$allowed = [ 'container' => EC_Component_Overrides::get_allowed_controls( 'container' ) ];
		foreach ( EC_Component_Shadow_Sync::index_elements( $parent ) as $element ) {
			$type = EC_Component_Shadow_Sync::element_type( $element );
			if ( ! isset( $allowed[ $type ] ) ) {
				$allowed[ $type ] = EC_Component_Overrides::get_allowed_controls( $type );
			}
		}
		return $allowed;
	}

	/**
	 * Raw _elementor_data of a published component, or null when the id is not
	 * one (wrong type, unpublished, missing, no data).
	 */
	private static function published_parent_raw( int $component_id ): ?string {
		if ( ! $component_id || 'elementor_library' !== get_post_type( $component_id ) ||
			'publish' !== get_post_status( $component_id ) ||
			EC_Component_CPT::TYPE !== get_post_meta( $component_id, '_elementor_template_type', true ) ) {
			return null;
		}

		$raw = get_post_meta( $component_id, '_elementor_data', true );
		$raw = is_array( $raw ) ? wp_json_encode( $raw ) : (string) $raw;
		return '' === $raw ? null : $raw;
	}

	private static function load_parent_elements( int $component_id ): ?array {
		$raw = self::published_parent_raw( $component_id );
		if ( null === $raw ) {
			return null;
		}
		$elements = json_decode( $raw, true );
		return is_array( $elements ) ? $elements : null;
	}

	private static function update_reference_index( int $post_id, array $elements ): void {
		$references = [];
		self::collect_references( $elements, $references );
		delete_post_meta( $post_id, self::REFERENCE_META );
		foreach ( array_keys( $references ) as $component_id ) {
			add_post_meta( $post_id, self::REFERENCE_META, $component_id );
		}
	}

	private static function collect_references( array $elements, array &$references ): void {
		foreach ( $elements as $element ) {
			if ( 'component_instance' === ( $element['widgetType'] ?? '' ) ) {
				$component_id = absint( $element['settings']['component_id'] ?? 0 );
				if ( $component_id ) {
					$references[ $component_id ] = true;
				}
				continue;
			}
			self::collect_references( (array) ( $element['elements'] ?? [] ), $references );
		}
	}

	/**
	 * Compact storage: instances are saved without their shadow children, only
	 * `component_id`, the sparse overrides and the version stamp. Pages shrink by
	 * roughly the size of the component per instance (less data in the database,
	 * revisions, exports and the editor payload). The trade-off is that the
	 * component content is then only present where this plugin is active.
	 *
	 * Rendering does not depend on the flag: an instance without children is
	 * always expanded from the parent at render/CSS time. The flag only controls
	 * whether saves and syncs strip the children. Off by default.
	 */
	public static function is_compact_storage(): bool {
		return (bool) apply_filters( 'ec_compact_storage', (bool) get_option( self::COMPACT_OPTION, false ) );
	}

	/**
	 * The locked shadow children of an instance, built from the current parent.
	 * Memoized per request: the CSS pass and the render pass ask for the same tree.
	 */
	public static function expand_instance( array $instance ): array {
		static $memo = [];

		$settings     = (array) ( $instance['settings'] ?? [] );
		$component_id = absint( $settings['component_id'] ?? 0 );
		$instance_id  = (string) ( $instance['id'] ?? '' );
		if ( ! $component_id || '' === $instance_id ) {
			return [];
		}

		$overrides = $settings['ec_overrides'] ?? '';
		$key       = $component_id . '|' . $instance_id . '|' . md5( is_string( $overrides ) ? $overrides : wp_json_encode( $overrides ) ) .
			'|' . (string) self::current_parent_version( $component_id );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$parent = self::load_parent_elements( $component_id );
		if ( null === $parent ) {
			return $memo[ $key ] = [];
		}

		$base = EC_Component_Shadow_Sync::reconcile(
			$parent,
			EC_Component_Overrides::decode( $overrides ),
			$instance_id,
			self::allowed_by_type( $parent )
		);
		return $memo[ $key ] = $base['elements'];
	}

	/**
	 * Give a live Elementor instance element its children when its stored data
	 * has none. add_child() is Elementor's public API for this; the elements then
	 * take part in rendering and CSS exactly like stored children would.
	 */
	public static function populate_children( $element ): void {
		if ( ! is_object( $element ) || ! method_exists( $element, 'add_child' ) || $element->get_children() ) {
			return;
		}
		$children = self::expand_instance( [
			'id'       => $element->get_id(),
			'settings' => (array) $element->get_data( 'settings' ),
		] );
		foreach ( $children as $child ) {
			$element->add_child( $child );
		}
	}

	/** Runs before Elementor recurses into children, so their CSS is generated too. */
	public static function inject_children_for_css( $css_file, $element ): void {
		if ( is_object( $element ) && method_exists( $element, 'get_name' ) && 'component_instance' === $element->get_name() ) {
			self::populate_children( $element );
		}
	}

	/**
	 * Queue every consumer of a component and sync the first batch inline; the
	 * rest continue in WP-Cron. $force rebuilds instances even when their version
	 * is current (used to compact or re-expand stored data). Returns how many
	 * pages were queued.
	 */
	public static function resync( int $component_id, bool $force = false ): int {
		// The parent may just have changed: forget any version computed earlier in this request.
		unset( self::$versions[ $component_id ] );
		self::current_parent_version( $component_id ); // warm the version cache once, here

		$ids = self::find_consumers( $component_id );
		if ( ! $ids ) {
			delete_transient( self::QUEUE_KEY . $component_id );
			return 0;
		}

		// The capability gate belongs to whoever saved the parent, not to whichever
		// context (possibly cron, with no user) ends up finishing the queue.
		set_transient( self::QUEUE_KEY . $component_id, [
			'ids'      => $ids,
			'warm'     => [],
			'force'    => $force,
			'sanitize' => ! current_user_can( 'unfiltered_html' ),
		], DAY_IN_SECONDS );

		self::process_queue( $component_id, false );
		return count( $ids );
	}

	/**
	 * Synchronous variant for WP-CLI: no queue, no time limits, trusted operator.
	 *
	 * @return array{consumers:int, changed:int}
	 */
	public static function resync_blocking( int $component_id, bool $force = false, bool $warm = true ): array {
		unset( self::$versions[ $component_id ] );
		$ids     = self::find_consumers( $component_id );
		$changed = 0;
		foreach ( $ids as $post_id ) {
			if ( self::sync_consumer( $post_id, false, $force ) ) {
				$changed++;
				$warm && self::warm_consumer_css( $post_id );
			}
		}
		return [ 'consumers' => count( $ids ), 'changed' => $changed ];
	}

	public static function run_queued_batch( $component_id ): void {
		self::process_queue( (int) $component_id, true );
	}

	private static function process_queue( int $component_id, bool $from_cron ): void {
		$key   = self::QUEUE_KEY . $component_id;
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) ) {
			delete_transient( $key );
			return;
		}

		$ids  = (array) ( $queue['ids'] ?? [] );
		$warm = (array) ( $queue['warm'] ?? [] );

		foreach ( array_splice( $ids, 0, self::BATCH_SIZE ) as $consumer_id ) {
			if ( self::sync_consumer( (int) $consumer_id, ! empty( $queue['sanitize'] ), ! empty( $queue['force'] ) ) &&
				apply_filters( 'ec_prewarm_css', true ) ) {
				$warm[] = (int) $consumer_id;
			}
		}

		// CSS generation is the expensive part, so it never happens inside the
		// request that saved the parent — only in the background batches.
		if ( $from_cron ) {
			$warm = self::warm_batch( $warm );
		}

		if ( ! $ids && ! $warm ) {
			delete_transient( $key );
			return;
		}

		$queue['ids']  = $ids;
		$queue['warm'] = $warm;
		set_transient( $key, $queue, DAY_IN_SECONDS );
		wp_schedule_single_event( time(), self::CRON_HOOK, [ $component_id ] );
		if ( function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
	}

	/** @return int[] Pages still waiting for their CSS. */
	private static function warm_batch( array $pending ): array {
		$deadline = microtime( true ) + self::WARM_BUDGET;
		$done     = 0;
		while ( $pending && $done < self::WARM_BATCH && microtime( true ) < $deadline ) {
			self::warm_consumer_css( (int) array_shift( $pending ) );
			$done++;
		}
		return $pending;
	}

	private static function warm_consumer_css( int $post_id ): void {
		if ( 'publish' !== get_post_status( $post_id ) || ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}
		try {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
		} catch ( \Throwable $e ) {
			// Non-fatal: Elementor regenerates the file on the first view.
			return;
		}
		do_action( 'ec_consumer_css_warmed', $post_id );
	}

	/**
	 * Every live post whose Elementor data uses this component, on any post type.
	 *
	 * The _ec_component_ref index is the fast path, but it only exists for pages
	 * saved through the editor after it was introduced. Pages created by import,
	 * duplication, REST/MCP tooling or an older plugin version have none, so
	 * instance data is scanned as well and any hit backfills the index.
	 * (WP_Query 'any' is not used: it skips exclude_from_search types such as
	 * elementor_library, i.e. Theme Builder headers, footers and popups.)
	 */
	private static function find_consumers( int $component_id ): array {
		global $wpdb;

		$live = "p.post_status NOT IN ('trash', 'auto-draft', 'inherit')";

		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND pm.meta_value = %s AND {$live}",
			self::REFERENCE_META,
			(string) $component_id
		) ) );

		$candidates = self::instance_candidates();

		foreach ( array_diff( $candidates, $ids ) as $post_id ) {
			$elements = self::consumer_elements( $post_id );
			$refs     = [];
			self::collect_references( $elements ?? [], $refs );
			if ( isset( $refs[ $component_id ] ) ) {
				$ids[] = $post_id;
				self::update_reference_index( $post_id, $elements );
			}
		}

		return array_values( array_filter(
			array_unique( $ids ),
			static function ( int $post_id ) use ( $component_id ): bool {
				// Components are not consumers here; nested components are out of scope.
				return $post_id !== $component_id && ! self::is_component_post( $post_id );
			}
		) );
	}

	/**
	 * Live posts whose Elementor data mentions a component instance (a superset;
	 * callers verify by parsing). Any post type, any component.
	 *
	 * @return int[]
	 */
	private static function instance_candidates(): array {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_elementor_data' AND pm.meta_value LIKE %s
			AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
			'%' . $wpdb->esc_like( 'component_instance' ) . '%'
		) ) );
	}

	/**
	 * Where every component is used and how many of those pages are out of date.
	 *
	 * @return array<int, array{pages:int[], stale:int[], available:bool}> keyed by component id
	 */
	public static function usage_report(): array {
		$report = [];
		foreach ( self::instance_candidates() as $post_id ) {
			$elements = self::is_component_post( $post_id ) ? null : self::consumer_elements( $post_id );
			if ( null === $elements ) {
				continue;
			}

			$instances = [];
			self::collect_instances( $elements, $instances );
			foreach ( $instances as [ $component_id, $known ] ) {
				$current = self::current_parent_version( $component_id );
				$report[ $component_id ] ??= [ 'pages' => [], 'stale' => [], 'available' => null !== $current ];
				$report[ $component_id ]['pages'][ $post_id ] = $post_id;
				if ( null !== $current && ! hash_equals( $current, $known ) ) {
					$report[ $component_id ]['stale'][ $post_id ] = $post_id;
				}
			}
		}

		foreach ( $report as &$row ) {
			$row['pages'] = array_values( $row['pages'] );
			$row['stale'] = array_values( $row['stale'] );
		}
		unset( $row );
		return $report;
	}

	/** @param array<int, array{0:int,1:string}> $out [component_id, recorded parent_version] per instance. */
	private static function collect_instances( array $elements, array &$out ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'component_instance' === ( $element['widgetType'] ?? '' ) ) {
				$component_id = absint( $element['settings']['component_id'] ?? 0 );
				if ( $component_id ) {
					$out[] = [ $component_id, (string) ( $element['settings']['ec_parent_version'] ?? '' ) ];
				}
				continue;
			}
			self::collect_instances( (array) ( $element['elements'] ?? [] ), $out );
		}
	}

	/** @return bool Whether the consumer's data was rewritten. */
	private static function sync_consumer( int $post_id, bool $sanitize, bool $force = false ): bool {
		$elements = self::consumer_elements( $post_id );
		if ( null === $elements ) {
			return false;
		}

		$changed  = false;
		$elements = self::refresh_stale_instances( $elements, $changed, $sanitize, $force );
		if ( $changed ) {
			self::write_consumer( $post_id, $elements );
		}
		return $changed;
	}

	/**
	 * Rebuild only the instances whose recorded parent_version no longer matches
	 * the published parent. Up-to-date instances are untouched, so re-running a
	 * batch is cheap and idempotent. $sanitize applies Elementor's non-
	 * unfiltered_html kses gate to the regenerated subtrees only. $force rebuilds
	 * current instances too (still never an instance whose parent is unavailable).
	 */
	public static function refresh_stale_instances( array $elements, bool &$changed, bool $sanitize = false, bool $force = false ): array {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( 'component_instance' === ( $element['widgetType'] ?? '' ) ) {
				$current = self::current_parent_version( absint( $element['settings']['component_id'] ?? 0 ) );
				$known   = (string) ( $element['settings']['ec_parent_version'] ?? '' );
				if ( null === $current || ( ! $force && hash_equals( $current, $known ) ) ) {
					continue;
				}

				$element = self::reconcile_instance( $element );
				if ( $sanitize ) {
					$element['elements'] = self::kses_post_deep( $element['elements'] );
				}
				$changed = true;
				continue;
			}

			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = self::refresh_stale_instances( (array) $element['elements'], $changed, $sanitize, $force );
			}
		}
		unset( $element );
		return $elements;
	}

	/**
	 * Current parent_version of a published component, or null if unavailable.
	 *
	 * Computing it means decoding the parent JSON, normalizing the tree and
	 * hashing it, which every render of every consumer would repeat. Instead the
	 * result is stored on the component keyed by a hash of the raw data (plus the
	 * plugin version, since normalization is plugin code). Anything that changes
	 * the data — including paths that never fire after_save, like a revision
	 * restore — changes the key and forces a recompute.
	 */
	private static function current_parent_version( int $component_id ): ?string {
		if ( array_key_exists( $component_id, self::$versions ) ) {
			return self::$versions[ $component_id ];
		}

		$version = null;
		$raw     = self::published_parent_raw( $component_id );
		if ( null !== $raw ) {
			$key    = md5( ( defined( 'EC_VERSION' ) ? EC_VERSION : '' ) . $raw );
			$cached = (string) get_post_meta( $component_id, self::VERSION_META, true );
			if ( 0 === strpos( $cached, $key . ':' ) && preg_match( '/^[a-f0-9]{64}$/', substr( $cached, 33 ) ) ) {
				$version = substr( $cached, 33 );
			} else {
				$elements = json_decode( $raw, true );
				if ( is_array( $elements ) ) {
					$version = EC_Component_Shadow_Sync::version_for( $elements );
					update_post_meta( $component_id, self::VERSION_META, $key . ':' . $version );
				}
			}
		}

		return self::$versions[ $component_id ] = $version;
	}

	private static function consumer_elements( int $post_id ): ?array {
		$raw      = get_post_meta( $post_id, '_elementor_data', true );
		$elements = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $elements ) ? $elements : null;
	}

	/**
	 * Persist rebuilt consumer data. This bypasses Document::save(), so it must
	 * also do what save() does after writing: drop the CSS file and the rendered
	 * HTML cache, both derived from the data we just replaced.
	 */
	private static function write_consumer( int $post_id, array $elements ): void {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		delete_post_meta( $post_id, self::ELEMENTOR_CACHE_META );
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
		}
		// A plain meta write does not fire save_post the way an editor Update does,
		// and page-cache plugins purge on save events, so tell them explicitly.
		clean_post_cache( $post_id );
		self::purge_page_caches( $post_id );
		do_action( 'ec_consumer_synced', $post_id );
	}

	/**
	 * Purge one page from the common page-cache plugins through their public APIs.
	 * Anything else can hook `ec_consumer_synced`.
	 */
	private static function purge_page_caches( int $post_id ): void {
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id ); // WP Rocket
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id ); // W3 Total Cache
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id ); // WP Super Cache
		}
		// LiteSpeed Cache does not purge on clean_post_cache, only on post-save events.
		do_action( 'litespeed_purge_post', $post_id );
	}

	private static function is_component_post( int $post_id ): bool {
		return EC_Component_CPT::TYPE === get_post_meta( $post_id, '_elementor_template_type', true );
	}

	private static function is_component_document( $document ): bool {
		return method_exists( $document, 'get_name' ) && EC_Component_CPT::TYPE === $document->get_name();
	}

	private static function decode_array( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
