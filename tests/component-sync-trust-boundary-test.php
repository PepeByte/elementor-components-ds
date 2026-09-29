<?php
/**
 * Trust-boundary test: the PHP save path (filter_document_save /
 * reconcile_document_elements / reconcile_instance) must discard any
 * structure injected into a component instance and rebuild it from the
 * published parent. JavaScript locking is not authorization — this is the
 * final enforcement layer (IMPLEMENTATION_PLAN_DESIGN_B.md: "PHP
 * reconciliation is the final trust boundary").
 *
 * Runs standalone with minimal WordPress function stubs; extend with a real
 * WordPress harness to also cover sync_consumers fan-out and CSS regen.
 */

define( 'ABSPATH', __DIR__ );

// --- WordPress function stubs (only what the exercised path needs) ---

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_option( $name, $default = false ) {
	return $default;
}

function add_action() {}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_post_type( $post_id ) {
	return 123 === (int) $post_id ? 'elementor_library' : 'post';
}

function get_post_status( $post_id ) {
	return 123 === (int) $post_id ? 'publish' : 'draft';
}

function get_post_meta( $post_id, $key, $single ) {
	if ( 123 !== (int) $post_id ) {
		return $single ? '' : [];
	}
	switch ( $key ) {
		case '_elementor_template_type':
			return $single ? 'component' : [ 'component' ];
		case '_elementor_data':
			$raw = wp_json_encode( $GLOBALS['ec_parent'] );
			return $single ? $raw : [ $raw ];
	}
	return $single ? '' : [];
}

require dirname( __DIR__ ) . '/class-component-overrides.php';
require dirname( __DIR__ ) . '/class-component-shadow-sync.php';
require dirname( __DIR__ ) . '/class-component-cpt.php';
require dirname( __DIR__ ) . '/class-component-sync-manager.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function collect_ids( array $elements, array &$ids ): void {
	foreach ( $elements as $element ) {
		$ids[] = $element['id'];
		collect_ids( $element['elements'] ?? [], $ids );
	}
}

function check_locked( array $elements ): void {
	foreach ( $elements as $element ) {
		check( true === ( $element['isLocked'] ?? false ), 'Shadow node is not locked: ' . $element['id'] );
		check_locked( $element['elements'] ?? [] );
	}
}

// Published parent component (post 123): a container with one heading widget.
$GLOBALS['ec_parent'] = [ [
	'id'       => 'par001',
	'elType'   => 'container',
	'settings' => [],
	'elements' => [ [
		'id'         => 'w0001',
		'elType'     => 'widget',
		'widgetType' => 'heading',
		'settings'   => [ 'title' => 'Parent title' ],
		'elements'   => [],
	] ],
] ];

// Fake Elementor document objects.
$post_document     = new class {
	public function get_name() { return 'post'; }
};
$component_document = new class {
	public function get_name() { return 'component'; }
};

// --- Case 1: injected structure is discarded and rebuilt from the parent ---

$data = [
	'elements' => [ [
		'id'         => 'inst001',
		'elType'     => 'widget',
		'widgetType' => 'component_instance',
		'settings'   => [
			'component_id'      => 123,
			'ec_overrides'      => '',
			'ec_shadow_map'     => '',
			'ec_parent_version' => '',
		],
		// Attacker-injected structure: a legacy section + a junk widget.
		'elements'   => [
			[ 'id' => 'injected', 'elType' => 'section', 'settings' => [], 'elements' => [] ],
			[ 'id' => 'junk', 'elType' => 'widget', 'widgetType' => 'evil_widget', 'settings' => [], 'elements' => [] ],
		],
	] ],
];

$out      = EC_Component_Sync_Manager::filter_document_save( $data, $post_document );
$instance = $out['elements'][0];

check( 'component_instance' === ( $instance['widgetType'] ?? '' ), 'Instance element was lost.' );

$ids = [];
collect_ids( $instance['elements'], $ids );
check( ! in_array( 'injected', $ids, true ), 'Injected section survived reconciliation.' );
check( ! in_array( 'junk', $ids, true ), 'Injected junk widget survived reconciliation.' );
check( 1 === count( $instance['elements'] ), 'Shadow does not match the parent shape.' );

$root = $instance['elements'][0];
check( 'container' === ( $root['elType'] ?? '' ), 'Parent root type not preserved.' );
check( 'par001' !== $root['id'], 'Runtime ID collides with the parent source ID.' );
check( 'heading' === ( $root['elements'][0]['widgetType'] ?? '' ), 'Parent widget not materialized.' );
check( 'Parent title' === ( $root['elements'][0]['settings']['title'] ?? '' ), 'Parent settings not materialized.' );
check( 'w0001' !== $root['elements'][0]['id'], 'Child runtime ID collides with its source ID.' );
check_locked( $instance['elements'] );

check( '' !== ( $instance['settings']['ec_parent_version'] ?? '' ), 'Parent version not recorded.' );
check( '{}' !== ( $instance['settings']['ec_shadow_map'] ?? '' ), 'Source/runtime map not recorded.' );
check( isset( $instance['settings']['ec_overrides'] ), 'Sparse overrides slot not written.' );

// --- Case 2: ordinary (non-instance) elements pass through untouched ---

$plain = [ 'elements' => [ [
	'id'         => 'a1',
	'elType'     => 'widget',
	'widgetType' => 'heading',
	'settings'   => [ 'title' => 'Keep me' ],
	'elements'   => [],
] ] ];
$out2  = EC_Component_Sync_Manager::filter_document_save( $plain, $post_document );
check( 'a1' === ( $out2['elements'][0]['id'] ?? '' ), 'Non-instance elements were altered.' );
check( 'Keep me' === ( $out2['elements'][0]['settings']['title'] ?? '' ), 'Non-instance settings were altered.' );

// --- Case 3: component documents (the parent itself) pass through untouched ---

$out3 = EC_Component_Sync_Manager::filter_document_save( $data, $component_document );
check( $out3 === $data, 'Component document data was altered.' );

// --- Case 4: stale parent version skips intent extraction but still
// rebuilds the shadow (live edits not yet captured are dropped by design) ---

$stale = [
	'elements' => [ [
		'id'         => 'inst002',
		'elType'     => 'widget',
		'widgetType' => 'component_instance',
		'settings'   => [
			'component_id'      => 123,
			'ec_overrides'      => '',
			'ec_shadow_map'     => '',
			'ec_parent_version' => 'stale-version',
		],
		'elements'   => [],
	] ],
];
$out4      = EC_Component_Sync_Manager::filter_document_save( $stale, $post_document );
$instance4 = $out4['elements'][0];
check( 1 === count( $instance4['elements'] ), 'Stale-version instance did not rebuild from the parent.' );
check( '' !== ( $instance4['settings']['ec_parent_version'] ?? '' ), 'Stale version not refreshed.' );
check( 'stale-version' !== ( $instance4['settings']['ec_parent_version'] ?? '' ), 'Stale version not updated.' );

echo "component sync trust boundary: ok\n";
