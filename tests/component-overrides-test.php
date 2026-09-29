<?php

define( 'ABSPATH', __DIR__ );

function wp_json_encode( $value ) {
	return json_encode( $value );
}

require dirname( __DIR__ ) . '/class-component-overrides.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$index = [
	'head001' => [ 'widgetType' => 'heading' ],
	'btn001'  => [ 'widgetType' => 'button' ],
];
$allowed = [
	'heading' => [ 'title', 'title_color', 'align_tablet' ],
	'button'  => [ 'text', 'link', 'button_text_color' ],
];

$raw = [
	'version' => 1,
	'widgets' => [
		'head001' => [
			'widgetType' => 'heading',
			'values' => [
				'title' => '',
				'title_color' => '#123456',
				'_margin' => [ 'top' => 20 ],
			],
			'globals' => [ 'title_color' => 'globals/colors?id=primary' ],
			'dynamic' => [ 'title' => '[elementor-tag id="post-title"]' ],
		],
		'missing' => [
			'widgetType' => 'heading',
			'values' => [ 'title' => 'orphan' ],
		],
	],
];

$patch = EC_Component_Overrides::normalize( $raw, $index, $allowed );
check( array_key_exists( 'title', $patch['widgets']['head001']['values'] ), 'Explicit empty value was lost.' );
check( '' === $patch['widgets']['head001']['values']['title'], 'Explicit empty value changed.' );
check( ! isset( $patch['widgets']['head001']['values']['_margin'] ), 'Advanced control was accepted.' );
check( ! isset( $patch['widgets']['missing'] ), 'Orphan widget was accepted.' );

$parent_settings = [
	'title' => 'Parent title',
	'title_color' => '#000000',
	'align_tablet' => 'left',
	'__globals__' => [ 'title_color' => 'globals/colors?id=secondary' ],
	'__dynamic__' => [ 'title' => '[elementor-tag id="site-title"]' ],
];
$merged = EC_Component_Overrides::apply_to_settings(
	$parent_settings,
	$patch['widgets']['head001']
);
check( '' === $merged['title'], 'Empty title override did not apply.' );
check( '#123456' === $merged['title_color'], 'Literal value did not apply.' );
check( 'left' === $merged['align_tablet'], 'Untouched responsive value did not inherit.' );
check( 'globals/colors?id=primary' === $merged['__globals__']['title_color'], 'Global reference did not apply.' );
check( '[elementor-tag id="post-title"]' === $merged['__dynamic__']['title'], 'Dynamic reference did not apply.' );
check( 'Parent title' === $parent_settings['title'], 'Parent settings were mutated.' );

$literal = EC_Component_Overrides::apply_to_settings(
	$parent_settings,
	[ 'values' => [ 'title_color' => '#abcdef' ] ]
);
check( ! isset( $literal['__globals__']['title_color'] ), 'Literal override retained inherited global metadata.' );

$extracted = EC_Component_Overrides::extract_widget(
	$parent_settings,
	[
		'title' => '',
		'title_color' => '#000000',
		'align_tablet' => 'right',
		'__globals__' => [],
		'__dynamic__' => [],
	],
	$allowed['heading']
);
check( array_key_exists( 'title', $extracted['values'] ), 'Empty shadow value was not extracted.' );
check( 'right' === $extracted['values']['align_tablet'], 'Responsive override was not extracted.' );
check( array_key_exists( 'title_color', $extracted['values'] ), 'Cleared global binding was not captured as a literal override.' );

$reset = EC_Component_Overrides::reset_control( $patch, 'head001', 'title' );
check( ! isset( $reset['widgets']['head001']['values']['title'] ), 'Reset did not remove literal override.' );
check( ! isset( $reset['widgets']['head001']['dynamic']['title'] ), 'Reset did not remove dynamic metadata.' );

$encoded = EC_Component_Overrides::encode( $patch );
check( $patch === EC_Component_Overrides::decode( $encoded ), 'Patch JSON did not round-trip.' );
check( [ 'version' => 1, 'widgets' => [] ] === EC_Component_Overrides::decode( '{bad' ), 'Malformed JSON did not fail closed.' );

echo "component overrides: ok\n";
