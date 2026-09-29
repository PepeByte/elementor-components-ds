<?php
/**
 * Picker cache, compact storage, usage report / forced resync, and CSS pre-warming.
 * Standalone, against the shared in-memory WordPress stubs and a fake Elementor
 * CSS file class.
 */

namespace Elementor\Core\Files\CSS {
	class Post {
		private $id;
		public static function create( $id ) {
			$post     = new self();
			$post->id = (int) $id;
			return $post;
		}
		public function delete() {
			$GLOBALS['css']['delete'][] = $this->id;
		}
		public function update() {
			if ( $this->id === ( $GLOBALS['css']['throw_on'] ?? 0 ) ) {
				throw new \RuntimeException( 'CSS failed' );
			}
			$GLOBALS['css']['update'][] = $this->id;
		}
	}
}

namespace {
	require __DIR__ . '/support/wp-stubs.php';
	require __DIR__ . '/support/fake-elementor.php';

	$GLOBALS['css']        = [ 'delete' => [], 'update' => [] ];
	$GLOBALS['get_posts']  = 0;
	$GLOBALS['components'] = [];

	function get_posts( $args = [] ) {
		$GLOBALS['get_posts']++;
		return array_map(
			static function ( $id, $title ) { return (object) [ 'ID' => $id, 'post_title' => $title ]; },
			array_keys( $GLOBALS['components'] ),
			$GLOBALS['components']
		);
	}

	require dirname( __DIR__ ) . '/class-component-overrides.php';
	require dirname( __DIR__ ) . '/class-component-shadow-sync.php';
	require dirname( __DIR__ ) . '/class-component-cpt.php';
	require dirname( __DIR__ ) . '/class-component-sync-manager.php';

	function check( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function reset_versions(): void {
		$property = new \ReflectionProperty( \EC_Component_Sync_Manager::class, 'versions' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	function set_parent( string $title ): void {
		$GLOBALS['meta'][123] = [
			'_elementor_template_type' => 'component',
			'_elementor_data'          => wp_json_encode( [ [
				'id'       => 'par001',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [ [
					'id'         => 'w0001',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [ 'title' => $title ],
					'elements'   => [],
				] ],
			] ] ),
		];
		reset_versions();
	}

	function instance( string $id, array $settings = [], array $children = [] ): array {
		return [
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'component_instance',
			'settings'   => $settings + [ 'component_id' => '123', 'ec_overrides' => '', 'ec_shadow_map' => '{}', 'ec_parent_version' => '' ],
			'elements'   => $children,
		];
	}

	function title_of( array $children ): string {
		return $children[0]['elements'][0]['settings']['title'] ?? '(none)';
	}

	function make_page( int $post_id, array $instance ): void {
		$GLOBALS['meta'][ $post_id ] = [ '_elementor_data' => wp_json_encode( [ $instance ] ), '_elementor_element_cache' => 'x' ];
	}

	function page_instance( int $post_id ): array {
		return json_decode( $GLOBALS['meta'][ $post_id ]['_elementor_data'], true )[0];
	}

	function set_compact( bool $on ): void {
		$GLOBALS['options']['ec_compact_storage'] = $on ? 1 : 0;
	}

	/** Stand-in for a live Elementor element that supports add_child(). */
	class FakeElement {
		public $children = [];
		public $added    = 0;
		private $data;
		private $name;
		public function __construct( array $data, string $name = 'component_instance' ) {
			$this->data = $data;
			$this->name = $name;
		}
		public function get_name() { return $this->name; }
		public function get_id() { return $this->data['id']; }
		public function get_data( $item = null ) { return $item ? $this->data[ $item ] : $this->data; }
		public function get_children() { return $this->children; }
		public function add_child( array $child ) { $this->children[] = $child; $this->added++; return true; }
	}

	// ============ Item 4: picker list cache ============

	$GLOBALS['components'] = [ 10 => 'Hero', 11 => 'Footer' ];
	check( [ 10 => 'Hero', 11 => 'Footer' ] === \EC_Component_CPT::get_components(), 'Picker list is wrong.' );
	\EC_Component_CPT::get_components();
	\EC_Component_CPT::get_components();
	check( 1 === $GLOBALS['get_posts'], 'Picker list was queried again instead of cached.' );

	$GLOBALS['components'][12] = 'Renamed';
	\EC_Component_CPT::flush_cache_for_terms( 1, [], [], 'category' );
	\EC_Component_CPT::get_components();
	check( 1 === $GLOBALS['get_posts'], 'An unrelated taxonomy flushed the picker list.' );

	\EC_Component_CPT::flush_cache_for_terms( 1, [], [], 'elementor_library_type' );
	check( isset( \EC_Component_CPT::get_components()[12] ), 'New component missing after its type was assigned.' );
	check( 2 === $GLOBALS['get_posts'], 'Flush should cause exactly one re-query.' );

	\EC_Component_CPT::flush_cache();
	\EC_Component_CPT::get_components();
	check( 3 === $GLOBALS['get_posts'], 'Explicit flush did not rebuild the list.' );

	// ============ Item 5: compact storage ============

	set_parent( 'v1' );
	set_compact( false );

	// Full storage baseline: children are stored.
	$full = \EC_Component_Sync_Manager::reconcile_document_elements( [ instance( 'inst001' ) ] )[0];
	check( ! empty( $full['elements'] ), 'Baseline (compact off) must store children.' );
	$full_ids = [ $full['elements'][0]['id'], $full['elements'][0]['elements'][0]['id'] ];

	// Editor save with a live override, compact ON: overrides captured, children stripped.
	$live                                            = $full;
	$live['elements'][0]['elements'][0]['settings']['title'] = 'Local title';
	set_compact( true );
	$compact = \EC_Component_Sync_Manager::reconcile_document_elements( [ $live ] )[0];
	check( [] === $compact['elements'], 'Compact storage still saved children.' );
	check( false !== strpos( $compact['settings']['ec_overrides'], 'Local title' ), 'Compact save lost the instance override.' );
	check( '' !== $compact['settings']['ec_parent_version'], 'Compact instance has no version stamp.' );
	check( strlen( wp_json_encode( $compact ) ) < strlen( wp_json_encode( $full ) ), 'Compact instance is not smaller.' );

	// Render/CSS time: children come back from the parent + overrides, with stable ids.
	$expanded = \EC_Component_Sync_Manager::expand_instance( $compact );
	check( 'Local title' === title_of( $expanded ), 'Expansion ignored the override.' );
	check( $full_ids === [ $expanded[0]['id'], $expanded[0]['elements'][0]['id'] ], 'Expanded runtime ids differ from stored-shadow ids.' );
	check( true === $expanded[0]['isLocked'], 'Expanded children are not locked.' );

	// Parent edit is visible immediately (memo is keyed by parent version).
	set_parent( 'v2' );
	$again = \EC_Component_Sync_Manager::expand_instance( $compact );
	check( 'Local title' === title_of( $again ), 'Override lost after a parent edit.' );
	$plain = \EC_Component_Sync_Manager::expand_instance( instance( 'inst002' ) );
	check( 'v2' === title_of( $plain ), 'Expansion did not follow the parent edit.' );

	// Unavailable parent expands to nothing, never an error.
	check( [] === \EC_Component_Sync_Manager::expand_instance( instance( 'inst003', [ 'component_id' => '999' ] ) ), 'Missing parent should expand to nothing.' );
	check( [] === \EC_Component_Sync_Manager::expand_instance( [ 'id' => 'x' ] ), 'Instance without a component should expand to nothing.' );

	// Live elements: render() and the CSS hook fill in missing children only.
	$element = new FakeElement( $compact );
	\EC_Component_Sync_Manager::populate_children( $element );
	check( 1 === $element->added && 'Local title' === title_of( $element->children ), 'populate_children did not add expanded children.' );
	\EC_Component_Sync_Manager::populate_children( $element );
	check( 1 === $element->added, 'Existing children were duplicated.' );

	$css_element = new FakeElement( $compact );
	\EC_Component_Sync_Manager::inject_children_for_css( null, $css_element );
	check( 1 === $css_element->added, 'CSS hook did not give a compact instance its children.' );
	$other = new FakeElement( $compact, 'heading' );
	\EC_Component_Sync_Manager::inject_children_for_css( null, $other );
	check( 0 === $other->added, 'CSS hook touched a non-instance element.' );

	// Stale compact instance: version refreshed, still compact.
	set_parent( 'v3' );
	$changed = false;
	$out     = \EC_Component_Sync_Manager::refresh_stale_instances( [ $compact ], $changed );
	check( $changed && [] === $out[0]['elements'], 'Stale compact instance was rebuilt with children.' );
	check( $out[0]['settings']['ec_parent_version'] !== $compact['settings']['ec_parent_version'], 'Compact version was not refreshed.' );

	// Forced rebuild applies the CURRENT mode to already-current instances, both ways.
	$changed = false;
	\EC_Component_Sync_Manager::refresh_stale_instances( $out, $changed );
	check( ! $changed, 'A current instance was rewritten without force.' );
	set_compact( false );
	$changed  = false;
	$restored = \EC_Component_Sync_Manager::refresh_stale_instances( $out, $changed, false, true );
	check( $changed && ! empty( $restored[0]['elements'] ), 'Force + compact off did not restore stored children.' );
	check( 'Local title' === title_of( $restored[0]['elements'] ), 'Restoring children lost the override.' );
	set_compact( true );
	$changed  = false;
	$shrunk   = \EC_Component_Sync_Manager::refresh_stale_instances( $restored, $changed, false, true );
	check( $changed && [] === $shrunk[0]['elements'], 'Force + compact on did not strip stored children.' );
	set_compact( false );

	// Instances of an unavailable parent are never emptied, even when forced.
	$orphan  = instance( 'orph', [ 'component_id' => '999' ], [ [ 'id' => 'keep', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] );
	$changed = false;
	$kept    = \EC_Component_Sync_Manager::refresh_stale_instances( [ $orphan ], $changed, false, true );
	check( ! $changed && 'keep' === $kept[0]['elements'][0]['id'], 'A forced rebuild emptied an instance with no parent.' );

	// ============ Item 6: usage report, forced resync, blocking sync ============

	set_parent( 'v4' );
	$GLOBALS['meta']       = [ 123 => $GLOBALS['meta'][123] ];
	$GLOBALS['transients'] = [];
	$GLOBALS['scheduled']  = [];
	$current_version       = \EC_Component_Shadow_Sync::version_for( json_decode( $GLOBALS['meta'][123]['_elementor_data'], true ) );

	make_page( 601, instance( 'p601', [ 'ec_parent_version' => $current_version ] ) );
	make_page( 602, instance( 'p602', [ 'ec_parent_version' => 'old' ] ) );
	make_page( 603, instance( 'p603', [ 'component_id' => '999', 'ec_parent_version' => 'whatever' ] ) );
	$GLOBALS['meta'][700] = [ '_elementor_template_type' => 'component', '_elementor_data' => wp_json_encode( [ instance( 'nest' ) ] ) ];

	$report = \EC_Component_Sync_Manager::usage_report();
	check( [ 123, 999 ] === array_keys( $report ), 'Report should list exactly the components in use.' );
	check( [ 601, 602 ] === $report[123]['pages'], 'Report pages wrong (component documents must be excluded).' );
	check( [ 602 ] === $report[123]['stale'], 'Report stale pages wrong.' );
	check( true === $report[123]['available'] && false === $report[999]['available'], 'Report availability wrong.' );
	check( [] === $report[999]['stale'], 'An unavailable component must not count pages as out of date.' );

	// Blocking sync (WP-CLI): only the stale page is rebuilt; force rebuilds both.
	$GLOBALS['css'] = [ 'delete' => [], 'update' => [] ];
	$result = \EC_Component_Sync_Manager::resync_blocking( 123, false );
	check( [ 'consumers' => 2, 'changed' => 1 ] === $result, 'resync_blocking result wrong: ' . wp_json_encode( $result ) );
	check( [ 602 ] === $GLOBALS['css']['update'], 'Only the rebuilt page should be pre-warmed.' );
	$result = \EC_Component_Sync_Manager::resync_blocking( 123, true, false );
	check( [ 'consumers' => 2, 'changed' => 2 ] === $result, 'Forced blocking sync did not rebuild every page.' );
	check( [ 602 ] === $GLOBALS['css']['update'], '--no-warm still regenerated CSS.' );
	check( [] === \EC_Component_Sync_Manager::usage_report()[123]['stale'], 'Pages are still out of date after a sync.' );

	// Queued resync (admin button): force rebuilds a current page, first batch inline.
	$before = $GLOBALS['writes'];
	$queued = \EC_Component_Sync_Manager::resync( 123, true );
	check( 2 === $queued, 'resync should report the queued page count.' );
	check( $GLOBALS['writes'] > $before, 'Forced resync did not rewrite current pages.' );
	check( 0 === \EC_Component_Sync_Manager::resync( 555, false ), 'A component with no pages should queue nothing.' );

	// ============ Item 7: CSS pre-warming ============

	function drain_queue( int $component_id ): int {
		$runs = 0;
		while ( isset( $GLOBALS['transients'][ 'ec_sync_queue_' . $component_id ] ) && $runs < 60 ) {
			\EC_Component_Sync_Manager::run_queued_batch( $component_id );
			$runs++;
		}
		check( ! isset( $GLOBALS['transients'][ 'ec_sync_queue_' . $component_id ] ), 'Queue never drained.' );
		return $runs;
	}

	function fresh_fleet( int $count ): void {
		$GLOBALS['meta']       = [ 123 => $GLOBALS['meta'][123] ];
		$GLOBALS['transients'] = [];
		$GLOBALS['scheduled']  = [];
		$GLOBALS['status']     = [];
		$GLOBALS['css']        = [ 'delete' => [], 'update' => [] ];
		for ( $i = 1; $i <= $count; $i++ ) {
			make_page( 3000 + $i, instance( 'q' . $i, [ 'ec_parent_version' => 'old' ] ) );
		}
	}

	$component_doc = new class {
		public function get_name() { return 'component'; }
		public function get_main_id() { return 123; }
	};

	// 120 stale pages; one draft (no need to pre-warm), one whose CSS generation throws.
	fresh_fleet( 120 );
	$GLOBALS['status'][3005]        = 'draft';
	$GLOBALS['css']['throw_on']     = 3007;

	\EC_Component_Sync_Manager::after_document_save( $component_doc, [] );
	check( 50 === count( array_unique( $GLOBALS['css']['delete'] ) ), 'Inline batch should rebuild exactly 50 pages.' );
	check( [] === $GLOBALS['css']['update'], 'CSS was regenerated inside the parent-save request.' );
	check( ! empty( $GLOBALS['transients']['ec_sync_queue_123']['warm'] ), 'Rebuilt pages were not queued for pre-warming.' );

	$runs = drain_queue( 123 );
	$warmed = $GLOBALS['css']['update'];
	check( $runs > 3, 'Warming must be spread across several cron runs (ran ' . $runs . ').' );
	check( ! in_array( 3005, $warmed, true ), 'A draft page was pre-warmed.' );
	check( ! in_array( 3007, $warmed, true ), 'Failing page should not be recorded as warmed.' );
	check( count( $warmed ) === count( array_unique( $warmed ) ), 'A page was warmed twice.' );
	check( 118 === count( $warmed ), 'Every other page should be warmed exactly once (got ' . count( $warmed ) . ').' );

	// The batch size bounds one cron run.
	fresh_fleet( 40 );
	\EC_Component_Sync_Manager::after_document_save( $component_doc, [] );
	\EC_Component_Sync_Manager::run_queued_batch( 123 );
	check( \EC_Component_Sync_Manager::WARM_BATCH === count( $GLOBALS['css']['update'] ), 'One cron run warmed more than WARM_BATCH pages.' );
	drain_queue( 123 );

	// Opt out with the filter: pages are rebuilt but nothing is queued for warming.
	fresh_fleet( 5 );
	$GLOBALS['filters']['ec_prewarm_css'] = static function () { return false; };
	\EC_Component_Sync_Manager::after_document_save( $component_doc, [] );
	check( ! isset( $GLOBALS['transients']['ec_sync_queue_123'] ), 'Opting out of pre-warming still left a queue.' );
	check( [] === $GLOBALS['css']['update'], 'CSS was warmed despite the opt-out.' );
	unset( $GLOBALS['filters']['ec_prewarm_css'] );

	echo "component storage and tools: ok\n";
}
