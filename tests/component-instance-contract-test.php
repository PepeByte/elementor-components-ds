<?php

$source = file_get_contents( dirname( __DIR__ ) . '/class-component-instance-widget.php' );

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

check(
	false !== strpos( $source, 'extends \Elementor\Modules\NestedElements\Base\Widget_Nested_Base' ),
	'Component Instance is not a native nested host.'
);
check( false !== strpos( $source, 'get_default_children_elements' ), 'Default shadow root contract is missing.' );
check( false !== strpos( $source, 'get_default_repeater_title_setting_key' ), 'Nested repeater key contract is missing.' );
check( false !== strpos( $source, "'ec_overrides'" ), 'Sparse override state control is missing.' );
check( false !== strpos( $source, "'ec_shadow_map'" ), 'Source/runtime map control is missing.' );
check( false !== strpos( $source, "'ec_parent_version'" ), 'Parent version control is missing.' );
check( false !== strpos( $source, 'print_child(' ), 'Native child rendering is missing.' );
check( false === strpos( $source, "'override_heading'" ), 'Fixed heading field still exists.' );
check( false === strpos( $source, 'build_css(' ), 'Hand-built instance CSS still exists.' );

// Compact storage: instances without stored children are expanded at render and CSS
// time. These need real Elementor to execute, so pin the wiring at source level.
check(
	1 === preg_match( '/!\s*\$editing\s*&&\s*!\s*\$this->get_children\(\)\s*\)\s*\{\s*EC_Component_Sync_Manager::populate_children\(\s*\$this\s*\)/', $source ),
	'render() must expand missing children outside the editor only.'
);

$manager = file_get_contents( dirname( __DIR__ ) . '/class-component-sync-manager.php' );
check(
	false !== strpos( $manager, "'elementor/element/before_parse_css', [ __CLASS__, 'inject_children_for_css' ]" ),
	'Compact instances get no CSS: the before_parse_css hook is not registered.'
);

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/elementor-components.php' );
check(
	false !== strpos( $bootstrap, "add_command( 'ec', 'EC_Component_CLI' )" ) && false !== strpos( $bootstrap, "defined( 'WP_CLI' )" ),
	'WP-CLI command is not registered behind a WP_CLI guard.'
);

echo "component instance contract: ok\n";
