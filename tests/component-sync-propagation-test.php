<?php
/**
 * Propagation test: a parent component edit must reach every consumer without
 * anyone re-publishing the consumer page.
 *
 * Covers what the trust-boundary test does not: consumer discovery (index +
 * unindexed + exclude_from_search post types), batched fan-out with no dropped
 * consumers, stale-only rebuilds, cache invalidation, and the frontend
 * self-heal. Runs standalone against in-memory WordPress stubs.
 */

require __DIR__ . '/support/wp-stubs.php';
require __DIR__ . '/support/fake-elementor.php';

$GLOBALS['purged'] = [];
function rocket_clean_post( $id ) { $GLOBALS['purged'][] = [ 'rocket', $id ]; }
function w3tc_flush_post( $id ) { $GLOBALS['purged'][] = [ 'w3tc', $id ]; }
function wp_cache_post_change( $id ) { $GLOBALS['purged'][] = [ 'supercache', $id ]; }

require dirname( __DIR__ ) . '/class-component-overrides.php';
require dirname( __DIR__ ) . '/class-component-shadow-sync.php';
require dirname( __DIR__ ) . '/class-component-cpt.php';
require dirname( __DIR__ ) . '/class-component-sync-manager.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// --- Fixtures ---

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
}

function instance_data( string $id ): array {
	return [ [
		'id'         => 'sec' . $id,
		'elType'     => 'container',
		'settings'   => [],
		'elements'   => [ [
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => 'component_instance',
			// Elementor's SELECT2 stores the id as a string.
			'settings'   => [ 'component_id' => '123', 'ec_parent_version' => 'old', 'ec_overrides' => '', 'ec_shadow_map' => '{}' ],
			'elements'   => [],
		] ],
	] ];
}

function shadow_title( int $post_id ): string {
	$data = json_decode( $GLOBALS['meta'][ $post_id ]['_elementor_data'], true );
	return $data[0]['elements'][0]['elements'][0]['elements'][0]['settings']['title'] ?? '(none)';
}

function make_consumer( int $post_id, bool $indexed ): void {
	$GLOBALS['meta'][ $post_id ] = [
		'_elementor_data'          => wp_json_encode( instance_data( 'i' . $post_id ) ),
		'_elementor_element_cache' => 'stale rendered html',
	];
	if ( $indexed ) {
		add_post_meta( $post_id, '_ec_component_ref', 123 );
	}
}

$component_doc = new class {
	public function get_name() { return 'component'; }
	public function get_main_id() { return 123; }
};

/** Run cron batches until the queue is gone (bounded, so a stuck queue fails the test). */
function drain_queue( int $component_id ): int {
	$runs = 0;
	while ( isset( $GLOBALS['transients'][ 'ec_sync_queue_' . $component_id ] ) && $runs < 60 ) {
		EC_Component_Sync_Manager::run_queued_batch( $component_id );
		$runs++;
	}
	check( ! isset( $GLOBALS['transients'][ 'ec_sync_queue_' . $component_id ] ), 'Queue never drained.' );
	return $runs;
}

// --- Case 1: stale instances rebuild; current and parentless ones are left alone ---

set_parent( 'v1' );
$changed = false;
$out     = EC_Component_Sync_Manager::refresh_stale_instances( instance_data( 'a' ), $changed );
check( $changed, 'Stale instance was not rebuilt.' );
$fresh_instance = $out[0]['elements'][0];
check( 'v1' === $fresh_instance['elements'][0]['elements'][0]['settings']['title'], 'Rebuilt shadow does not carry parent content.' );

$changed = false;
$again   = EC_Component_Sync_Manager::refresh_stale_instances( $out, $changed );
check( ! $changed && $again === $out, 'Up-to-date instance was rewritten.' );

$orphan = instance_data( 'b' );
$orphan[0]['elements'][0]['settings']['component_id'] = '999'; // no such component
$orphan[0]['elements'][0]['elements']                 = [ [ 'id' => 'keep', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
$changed = false;
$out2    = EC_Component_Sync_Manager::refresh_stale_instances( $orphan, $changed );
check( ! $changed && 'keep' === $out2[0]['elements'][0]['elements'][0]['id'], 'Instance with an unavailable parent was emptied.' );

// --- Case 2: frontend self-heals a stale consumer, once, and drops derived caches ---

set_parent( 'v1' );
make_consumer( 500, false );
$stored = json_decode( $GLOBALS['meta'][500]['_elementor_data'], true );
$GLOBALS['writes'] = 0;
$GLOBALS['actions'] = [];

$rendered = EC_Component_Sync_Manager::filter_frontend_content( $stored, 500 );
check( 'v1' === $rendered[0]['elements'][0]['elements'][0]['elements'][0]['settings']['title'], 'Frontend render is still stale.' );
check( 'v1' === shadow_title( 500 ), 'Healed shadow was not persisted.' );
check( ! isset( $GLOBALS['meta'][500]['_elementor_element_cache'] ), 'Elementor rendered-HTML cache survived the heal.' );
check( in_array( [ 'clean_post_cache', 500 ], $GLOBALS['actions'], true ), 'Post cache was not cleaned.' );
check( in_array( [ 'ec_consumer_synced', 500 ], $GLOBALS['actions'], true ), 'ec_consumer_synced did not fire.' );
foreach ( [ 'rocket', 'w3tc', 'supercache' ] as $cache ) {
	check( in_array( [ $cache, 500 ], $GLOBALS['purged'], true ), "Page cache '{$cache}' was not purged for the synced page." );
}
check( in_array( [ 'litespeed_purge_post', 500 ], $GLOBALS['actions'], true ), 'LiteSpeed Cache was not asked to purge the synced page.' );

$writes_after_heal = $GLOBALS['writes'];
EC_Component_Sync_Manager::filter_frontend_content( json_decode( $GLOBALS['meta'][500]['_elementor_data'], true ), 500 );
check( $GLOBALS['writes'] === $writes_after_heal, 'A current consumer was rewritten on view.' );

// Component documents are never healed as consumers.
$GLOBALS['meta'][123]['_elementor_template_type'] = 'component';
$same = EC_Component_Sync_Manager::filter_frontend_content( instance_data( 'c' ), 123 );
check( instance_data( 'c' ) === $same, 'A component document was reconciled as a consumer.' );

// --- Case 3: parent save reaches every consumer (indexed, unindexed, non-page) ---

set_parent( 'v2' );
$GLOBALS['meta']       = [ 123 => $GLOBALS['meta'][123] ];
$GLOBALS['transients'] = [];
$GLOBALS['scheduled']  = [];

$total = 130;
for ( $i = 1; $i <= $total; $i++ ) {
	make_consumer( 1000 + $i, 0 === $i % 2 ); // half carry no _ec_component_ref index
}
// Another component's document that happens to embed an instance: not a consumer here.
$GLOBALS['meta'][2000] = [ '_elementor_template_type' => 'component', '_elementor_data' => wp_json_encode( instance_data( 'nest' ) ) ];

EC_Component_Sync_Manager::after_document_save( $component_doc, [] );

$done = 0;
for ( $i = 1; $i <= $total; $i++ ) {
	'v2' === shadow_title( 1000 + $i ) && $done++;
}
check( 50 === $done, "Expected exactly one inline batch (50), synced {$done}." );
check( isset( $GLOBALS['transients']['ec_sync_queue_123'] ), 'Remaining consumers were not queued.' );
check( [ 'ec_sync_consumers_batch', [ 123 ] ] === $GLOBALS['scheduled'][0], 'Follow-up batch was not scheduled.' );

drain_queue( 123 );

for ( $i = 1; $i <= $total; $i++ ) {
	check( 'v2' === shadow_title( 1000 + $i ), "Consumer " . ( 1000 + $i ) . ' was never synced.' );
	check( ! isset( $GLOBALS['meta'][ 1000 + $i ]['_elementor_element_cache'] ), 'Rendered-HTML cache survived sync on ' . ( 1000 + $i ) );
}
check( ! isset( $GLOBALS['transients']['ec_sync_queue_123'] ), 'Finished queue was not cleared.' );
check( 'stale' !== ( $GLOBALS['meta'][2000]['_elementor_data'] ?? '' ) && false === strpos( $GLOBALS['meta'][2000]['_elementor_data'], '"v2"' ), 'A component document was rewritten as a consumer.' );
check( ! empty( $GLOBALS['meta'][1001]['_ec_component_ref'] ), 'Unindexed consumer was not backfilled into the index.' );

// A second parent edit re-syncs everything again, even though the queue was drained.
set_parent( 'v3' );
EC_Component_Sync_Manager::after_document_save( $component_doc, [] );
drain_queue( 123 );
for ( $i = 1; $i <= $total; $i++ ) {
	check( 'v3' === shadow_title( 1000 + $i ), 'Second parent edit did not propagate to ' . ( 1000 + $i ) );
}

// --- Case 4: parent version cache (item 3) ---

function reset_versions(): void {
	$property = new ReflectionProperty( EC_Component_Sync_Manager::class, 'versions' );
	$property->setAccessible( true );
	$property->setValue( null, [] );
}
function set_parent_data_only( string $title ): void {
	$keep = $GLOBALS['meta'][123]['_ec_component_version'] ?? null;
	set_parent( $title );
	if ( null !== $keep ) {
		$GLOBALS['meta'][123]['_ec_component_version'] = $keep; // as a revision restore would leave it
	}
}
function stale_instance_with_version( string $version ): array {
	$data = instance_data( 'v' );
	$data[0]['elements'][0]['settings']['ec_parent_version'] = $version;
	return $data;
}

set_parent( 'v4' );
reset_versions();
$changed = false;
EC_Component_Sync_Manager::refresh_stale_instances( instance_data( 'w' ), $changed );
$cached  = $GLOBALS['meta'][123]['_ec_component_version'] ?? '';
$raw     = $GLOBALS['meta'][123]['_elementor_data'];
check( 0 === strpos( $cached, md5( $raw ) . ':' ), 'Version was not cached against the parent data hash.' );
$real_version = substr( $cached, 33 );
check( $real_version === EC_Component_Shadow_Sync::version_for( json_decode( $raw, true ) ), 'Cached version differs from the computed one.' );

// A cache hit must not recompute: plant a sentinel and see it trusted.
$sentinel = str_repeat( 'a', 64 );
$GLOBALS['meta'][123]['_ec_component_version'] = md5( $raw ) . ':' . $sentinel;
reset_versions();
$changed = false;
EC_Component_Sync_Manager::refresh_stale_instances( stale_instance_with_version( $sentinel ), $changed );
check( ! $changed, 'Version cache was ignored (parent was decoded and hashed again).' );

// Parent data changed by a path that never fired after_save (revision restore,
// direct meta write): the hash key no longer matches, so the stale sentinel is discarded.
set_parent_data_only( 'v5' );
reset_versions();
$changed = false;
EC_Component_Sync_Manager::refresh_stale_instances( stale_instance_with_version( $sentinel ), $changed );
check( $changed, 'A stale version cache hid a changed parent.' );
check( 0 === strpos( $GLOBALS['meta'][123]['_ec_component_version'], md5( $GLOBALS['meta'][123]['_elementor_data'] ) . ':' ), 'Version cache was not refreshed.' );

// Tampered/garbage cache values are rejected.
$GLOBALS['meta'][123]['_ec_component_version'] = md5( $GLOBALS['meta'][123]['_elementor_data'] ) . ':not-a-hash';
reset_versions();
$changed = false;
EC_Component_Sync_Manager::refresh_stale_instances( stale_instance_with_version( 'not-a-hash' ), $changed );
check( $changed, 'A malformed cached version was trusted.' );

// --- Case 5: batched editor sync (item 2) ---

set_parent( 'v6' );
reset_versions();
$parent_version = EC_Component_Shadow_Sync::version_for( json_decode( $GLOBALS['meta'][123]['_elementor_data'], true ) );
$GLOBALS['denied'] = [ 124 => true ];
$GLOBALS['reads']  = [];

$results = EC_Component_Sync_Manager::build_shadow_batch( [
	[ 'instance_id' => 'aaa1111', 'component_id' => 123, 'overrides' => '', 'parent_version' => 'old', 'has_children' => true ],
	[ 'instance_id' => 'bbb2222', 'component_id' => 123, 'overrides' => '', 'parent_version' => $parent_version, 'has_children' => true ],
	[ 'instance_id' => 'ccc3333', 'component_id' => 123, 'overrides' => '', 'parent_version' => $parent_version, 'has_children' => false ],
	[ 'instance_id' => 'ddd4444', 'component_id' => 999, 'overrides' => '' ],
	[ 'instance_id' => 'eee5555', 'component_id' => 124, 'overrides' => '' ],
	[ 'instance_id' => '', 'component_id' => 123 ],
	'junk',
] );

check( 5 === count( $results ), 'Batch should answer each valid instance and drop malformed items.' );
check( ! empty( $results['aaa1111']['data']['elements'] ), 'Stale instance did not get its rebuilt children.' );
check( 'v6' === $results['aaa1111']['data']['elements'][0]['elements'][0]['settings']['title'], 'Batch shadow is not built from the parent.' );
check( isset( $results['bbb2222']['data']['map'] ) && ! array_key_exists( 'elements', $results['bbb2222']['data'] ), 'Current instance should get state but no elements.' );
check( ! empty( $results['ccc3333']['data']['elements'] ), 'Current version with no children must still receive elements.' );
check( isset( $results['bbb2222']['data']['sourceSettings'], $results['bbb2222']['data']['policies'] ), 'Editor state (sourceSettings/policies) missing.' );
check( isset( $results['ddd4444']['error'] ), 'Unknown component was not reported.' );
check( isset( $results['eee5555']['error'] ), 'Permission failure was not reported.' );
check( ! isset( $results['ddd4444']['data'] ) && ! isset( $results['eee5555']['data'] ), 'Failed items must not carry data.' );

// Runtime ids are per instance, so two instances of one component never collide.
$id_a = $results['aaa1111']['data']['elements'][0]['id'];
$id_c = $results['ccc3333']['data']['elements'][0]['id'];
check( $id_a !== $id_c, 'Two instances share runtime ids.' );

// Parent-level work happens once per component, not once per instance.
check( 1 === ( $GLOBALS['reads']['123:_elementor_data'] ?? 0 ), 'Parent data was loaded more than once for one batch.' );

// The batch is bounded.
$many = [];
for ( $i = 0; $i < 250; $i++ ) {
	$many[] = [ 'instance_id' => 'x' . $i, 'component_id' => 123, 'overrides' => '' ];
}
check( 100 === count( EC_Component_Sync_Manager::build_shadow_batch( $many ) ), 'Batch size is not capped.' );

// --- Case 6: structure added to the parent (new widget + new container) reaches the page ---

function widget_titles( array $elements, array &$out = [] ): array {
	foreach ( $elements as $element ) {
		if ( 'widget' === ( $element['elType'] ?? '' ) ) {
			$out[ $element['id'] ] = $element['settings']['title'] ?? '';
		}
		widget_titles( $element['elements'] ?? [], $out );
	}
	return $out;
}
function count_elements( array $elements ): int {
	$n = 0;
	foreach ( $elements as $element ) {
		$n += 1 + count_elements( $element['elements'] ?? [] );
	}
	return $n;
}
function all_locked_and_unique( array $elements, array &$ids = [] ): bool {
	foreach ( $elements as $element ) {
		if ( true !== ( $element['isLocked'] ?? false ) || isset( $ids[ $element['id'] ] ) ) {
			return false;
		}
		$ids[ $element['id'] ] = true;
		if ( ! all_locked_and_unique( $element['elements'] ?? [], $ids ) ) {
			return false;
		}
	}
	return true;
}

set_parent( 'v1' );
$GLOBALS['meta'] = [ 123 => $GLOBALS['meta'][123] ];
reset_versions();

// A page whose instance already holds a synced copy, with a local override on the heading.
$synced = instance_data( 'live' )[0]['elements'][0];
$synced['settings']['ec_overrides'] = wp_json_encode( [ 'version' => 1, 'widgets' => [ 'w0001' => [ 'widgetType' => 'heading', 'values' => [ 'title' => 'Local title' ] ] ] ] );
$synced = EC_Component_Sync_Manager::reconcile_document_elements( [ $synced ] )[0]; // builds the copy with the override applied
$GLOBALS['meta'][900] = [ '_elementor_data' => wp_json_encode( [ $synced ] ), '_elementor_element_cache' => 'cached html without the new widgets' ];
add_post_meta( 900, '_ec_component_ref', 123 );
check( 1 === count( widget_titles( $synced['elements'] ) ), 'Fixture should start with one widget.' );

// The author adds a widget to the container AND a whole new container with a widget, then clicks Update.
$parent = json_decode( $GLOBALS['meta'][123]['_elementor_data'], true );
$parent[0]['elements'][] = [ 'id' => 'w0002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'New widget' ], 'elements' => [] ];
$parent[]                = [ 'id' => 'con002', 'elType' => 'container', 'settings' => [], 'elements' => [
	[ 'id' => 'w0003', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'New container widget' ], 'elements' => [] ],
] ];
$GLOBALS['meta'][123]['_elementor_data'] = wp_json_encode( $parent );

EC_Component_Sync_Manager::after_document_save( $component_doc, [] );
drain_queue( 123 );

$stored = json_decode( $GLOBALS['meta'][900]['_elementor_data'], true )[0];
$titles = array_values( widget_titles( $stored['elements'] ) );
check( 3 === count( $titles ), 'New widget/container did not reach the page (found ' . count( $titles ) . ' widgets).' );
check( in_array( 'New widget', $titles, true ) && in_array( 'New container widget', $titles, true ), 'New content missing from the synced page.' );
check( in_array( 'Local title', $titles, true ), 'The local override was lost when structure was added.' );
check( all_locked_and_unique( $stored['elements'] ), 'Synced children must all be locked with unique ids.' );
check( ! isset( $GLOBALS['meta'][900]['_elementor_element_cache'] ), 'Cached HTML (without the new widgets) survived the sync.' );

// The same page still reads correctly if the fan-out never ran (frontend self-heal).
$GLOBALS['meta'][123]['_elementor_data'] = wp_json_encode( array_merge( $parent, [ [ 'id' => 'con003', 'elType' => 'container', 'settings' => [], 'elements' => [
	[ 'id' => 'w0004', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Added later' ], 'elements' => [] ],
] ] ] ) );
reset_versions();
$viewed = EC_Component_Sync_Manager::filter_frontend_content( json_decode( $GLOBALS['meta'][900]['_elementor_data'], true ), 900 );
check( in_array( 'Added later', widget_titles( $viewed ), true ), 'Frontend did not pick up structure added after the last sync.' );
check( in_array( 'Local title', widget_titles( $viewed ), true ), 'Frontend heal lost the override.' );

echo "component sync propagation: ok\n";
