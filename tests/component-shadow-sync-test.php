<?php

define( 'ABSPATH', __DIR__ );

function wp_json_encode( $value ) {
	return json_encode( $value );
}

require dirname( __DIR__ ) . '/class-component-overrides.php';
require dirname( __DIR__ ) . '/class-component-shadow-sync.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function find_element( array $elements, string $id ): ?array {
	foreach ( $elements as $element ) {
		if ( ( $element['id'] ?? '' ) === $id ) {
			return $element;
		}
		$found = find_element( $element['elements'] ?? [], $id );
		if ( $found ) {
			return $found;
		}
	}
	return null;
}

function check_locked( array $elements ): void {
	foreach ( $elements as $element ) {
		check( true === ( $element['isLocked'] ?? false ), 'Shadow element is not locked.' );
		check_locked( $element['elements'] ?? [] );
	}
}

function change_element_settings( array $elements, string $id, array $settings ): array {
	foreach ( $elements as &$element ) {
		if ( ( $element['id'] ?? '' ) === $id ) {
			$element['settings'] = array_replace( $element['settings'] ?? [], $settings );
		}
		$element['elements'] = change_element_settings( $element['elements'] ?? [], $id, $settings );
	}
	unset( $element );
	return $elements;
}

$parent = [ [
	'id' => 'root001',
	'elType' => 'container',
	'settings' => [ 'content_width' => 'full' ],
	'elements' => [
		[
			'id' => 'head001',
			'elType' => 'widget',
			'widgetType' => 'heading',
			'settings' => [ 'title' => 'Parent heading', 'title_color' => '#111111', '_margin' => [ 'top' => 10 ] ],
			'elements' => [],
		],
		[
			'id' => 'slide001',
			'elType' => 'container',
			'settings' => [ '_title' => 'Slide 1' ],
			'elements' => [ [
				'id' => 'text001',
				'elType' => 'widget',
				'widgetType' => 'text-editor',
				'settings' => [ 'editor' => '<p>Parent slide text</p>' ],
				'elements' => [],
			] ],
		],
	],
] ];
$parent_copy = $parent;
$allowed = [
	'heading' => [ 'title', 'title_color' ],
	'text-editor' => [ 'editor' ],
];
$patch = [
	'version' => 1,
	'widgets' => [
		'head001' => [
			'widgetType' => 'heading',
			'values' => [ 'title' => '', '_margin' => [ 'top' => 99 ] ],
		],
		'missing' => [
			'widgetType' => 'heading',
			'values' => [ 'title' => 'orphan' ],
		],
	],
];

$first = EC_Component_Shadow_Sync::reconcile( $parent, $patch, 'inst001', $allowed );
$second = EC_Component_Shadow_Sync::reconcile( $parent, $patch, 'inst002', $allowed );

check( $parent_copy === $parent, 'Parent tree was mutated.' );
check_locked( $first['elements'] );
check( $first['map']['head001'] !== $second['map']['head001'], 'Runtime IDs leaked across instances.' );
check( ! isset( $first['overrides']['widgets']['missing'] ), 'Deleted parent widget override survived.' );

$heading = find_element( $first['elements'], $first['map']['head001'] );
check( '' === $heading['settings']['title'], 'Explicit empty heading override failed.' );
check( '#111111' === $heading['settings']['title_color'], 'Untouched style did not inherit.' );
check( 10 === $heading['settings']['_margin']['top'], 'Advanced parent setting changed.' );

$slide = find_element( $first['elements'], $first['map']['slide001'] );
$text = find_element( $first['elements'], $first['map']['text001'] );
check( 'container' === $slide['elType'], 'Slide container type changed.' );
check( '<p>Parent slide text</p>' === $text['settings']['editor'], 'Widget inside slide did not render.' );

$edited_shadow = change_element_settings(
	$first['elements'],
	$first['map']['head001'],
	[ 'title_color' => '#abcdef', '_margin' => [ 'top' => 500 ] ]
);

$extracted = EC_Component_Shadow_Sync::extract_overrides(
	$parent,
	$edited_shadow,
	$first['map'],
	$first['overrides'],
	$allowed
);
check( '#abcdef' === $extracted['widgets']['head001']['values']['title_color'], 'Style edit was not extracted.' );
check( ! isset( $extracted['widgets']['head001']['values']['_margin'] ), 'Advanced edit entered the patch.' );

$updated_parent = $parent;
$updated_parent[0]['elements'][0]['settings']['title'] = 'New parent heading';
$updated_parent[0]['elements'][0]['settings']['title_color'] = '#222222';
$updated_parent[0]['elements'][1]['elements'][] = [
	'id' => 'btn001',
	'elType' => 'widget',
	'widgetType' => 'button',
	'settings' => [ 'text' => 'New parent button' ],
	'elements' => [],
];
$allowed['button'] = [ 'text' ];

$updated = EC_Component_Shadow_Sync::reconcile( $updated_parent, $extracted, 'inst001', $allowed );
$updated_heading = find_element( $updated['elements'], $updated['map']['head001'] );
check( '' === $updated_heading['settings']['title'], 'Existing override did not survive parent update.' );
check( '#abcdef' === $updated_heading['settings']['title_color'], 'Local style did not survive parent update.' );
check( isset( $updated['map']['btn001'] ), 'New parent widget did not synchronize.' );
check( $first['parent_version'] !== $updated['parent_version'], 'Parent version did not change.' );

echo "component shadow sync: ok\n";
