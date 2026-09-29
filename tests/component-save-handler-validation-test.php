<?php

define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/class-component-save-handler.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$valid = [
	'id'       => 'aaaaaaa',
	'elType'   => 'widget',
	'widgetType' => 'heading',
	'settings' => [ 'title' => '<p>Hello</p>' ],
	'elements' => [],
];

$count = 0;
check( EC_Component_Save_Handler::validate_element_tree( $valid, 0, $count ), 'Valid widget tree rejected.' );

$container = [
	'id'       => 'bbbbbbb',
	'elType'   => 'container',
	'settings' => [],
	'elements' => [ $valid ],
];
$count = 0;
check( EC_Component_Save_Handler::validate_element_tree( $container, 0, $count ), 'Valid container tree rejected.' );

$bad_type = $valid;
$bad_type['elType'] = 'snippet';
$count = 0;
check( ! EC_Component_Save_Handler::validate_element_tree( $bad_type, 0, $count ), 'Unknown element type accepted.' );

$no_id = $valid;
unset( $no_id['id'] );
$count = 0;
check( ! EC_Component_Save_Handler::validate_element_tree( $no_id, 0, $count ), 'Element without id accepted.' );

$bad_settings = $valid;
$bad_settings['settings'] = '<script>alert(1)</script>';
$count = 0;
check( ! EC_Component_Save_Handler::validate_element_tree( $bad_settings, 0, $count ), 'Non-array settings accepted.' );

$bad_children = $valid;
$bad_children['elements'] = 'not-an-array';
$count = 0;
check( ! EC_Component_Save_Handler::validate_element_tree( $bad_children, 0, $count ), 'Non-array elements accepted.' );

$deep = $valid;
$cursor = &$deep;
for ( $i = 0; $i < 25; $i++ ) {
	$cursor['elType']   = 'container';
	$cursor['elements'] = [ [ 'id' => 'd' . $i, 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
	$cursor             = &$cursor['elements'][0];
}
$count = 0;
check( ! EC_Component_Save_Handler::validate_element_tree( $deep, 0, $count ), 'Over-deep tree accepted.' );

$wide = [ 'id' => 'w000001', 'elType' => 'container', 'settings' => [], 'elements' => [] ];
for ( $i = 0; $i <= EC_Component_Save_Handler::MAX_ELEMENT_COUNT; $i++ ) {
	$wide['elements'][] = $valid;
}
$count = 0;
check( ! EC_Component_Save_Handler::validate_element_tree( $wide, 0, $count ), 'Over-sized tree accepted.' );

echo "component save handler validation: ok\n";
