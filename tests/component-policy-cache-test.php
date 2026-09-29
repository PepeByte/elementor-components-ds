<?php
/**
 * Control-policy cache: a widget's control stack is walked once per fingerprint,
 * not on every editor sync / consumer rebuild. Runs against a fake Elementor
 * that counts stack walks.
 */

namespace Elementor {
	class Base_Data_Control {}
	class Text_Control extends Base_Data_Control {}

	class Fake_Widget {
		public function get_stack() {
			$GLOBALS['stack_walks']++;
			return [
				'controls'       => [
					'section_content' => [ 'type' => 'section', 'tab' => 'content' ],
					'title'           => [ 'type' => 'text', 'section' => 'section_content' ],
					'title_color'     => [ 'type' => 'text', 'section' => 'section_content', 'responsive' => true ],
					'_margin'         => [ 'type' => 'text', 'section' => '_section_style' ],
				],
				'style_controls' => [],
			];
		}
	}

	class Plugin {
		public static $instance;
		public $widgets_manager;
		public $elements_manager;
		public $controls_manager;
		public $breakpoints;
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['options']    = [];
	$GLOBALS['stack_walks'] = 0;
	$GLOBALS['shutdown']   = 0;
	$GLOBALS['known']      = true;

	function wp_json_encode( $value ) { return json_encode( $value ); }
	function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
	function delete_option( $name ) { unset( $GLOBALS['options'][ $name ] ); return true; }
	function add_action( $hook, $callback ) { 'shutdown' === $hook && $GLOBALS['shutdown']++; }

	$plugin                   = new \Elementor\Plugin();
	$plugin->widgets_manager  = new class {
		public function get_widget_types( $type ) { return $GLOBALS['known'] ? new \Elementor\Fake_Widget() : null; }
	};
	$plugin->elements_manager = new class {
		public function get_element_types( $type ) { return new \Elementor\Fake_Widget(); }
	};
	$plugin->controls_manager = new class {
		public function get_control( $type ) { return 'text' === $type ? new \Elementor\Text_Control() : null; }
	};
	$plugin->breakpoints      = new class {
		public function get_active_devices_list() { return [ 'desktop', 'tablet', 'mobile' ]; }
	};
	\Elementor\Plugin::$instance = $plugin;

	require dirname( __DIR__ ) . '/class-component-overrides.php';

	function check( $condition, $message ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	/** Simulate a fresh PHP request: same database, empty static state. */
	function new_request(): void {
		foreach ( [ 'policies' => null, 'fingerprint' => null, 'policy_dirty' => false, 'policy_time' => 0 ] as $name => $value ) {
			$property = new ReflectionProperty( EC_Component_Overrides::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	// --- Case 1: computed once per request, and correct ---

	$policy = EC_Component_Overrides::get_control_policy( 'heading' );
	check( 1 === $GLOBALS['stack_walks'], 'First lookup did not walk the control stack once.' );
	check( in_array( 'title', $policy['allowed'], true ), 'Content control not allowed.' );
	check( in_array( 'title_color_tablet', $policy['allowed'], true ), 'Responsive variants missing.' );
	check( in_array( '_margin', $policy['denied'], true ), 'Advanced control not denied.' );

	EC_Component_Overrides::get_control_policy( 'heading' );
	EC_Component_Overrides::get_allowed_controls( 'heading' );
	check( 1 === $GLOBALS['stack_walks'], 'Repeat lookups in one request re-walked the stack.' );
	check( 1 === $GLOBALS['shutdown'], 'Persist should be registered once, not per lookup.' );

	// --- Case 2: persisted, and reused by the next request ---

	EC_Component_Overrides::persist_policy_cache();
	check( isset( $GLOBALS['options']['ec_control_policy_cache']['types']['heading'] ), 'Policy was not persisted.' );

	new_request();
	$again = EC_Component_Overrides::get_control_policy( 'heading' );
	check( 1 === $GLOBALS['stack_walks'], 'A new request did not reuse the persisted policy.' );
	check( $again === $policy, 'Persisted policy differs from the computed one.' );

	// The TTL bounds staleness from the first write: re-persisting must not extend it.
	$first_time = $GLOBALS['options']['ec_control_policy_cache']['time'];
	EC_Component_Overrides::get_control_policy( 'button' );
	EC_Component_Overrides::persist_policy_cache();
	check( $first_time === $GLOBALS['options']['ec_control_policy_cache']['time'], 'Persisting reset the cache TTL.' );
	check( 2 === $GLOBALS['stack_walks'], 'A new widget type should compute exactly once.' );

	// --- Case 3: anything that can change a stack invalidates the cache ---

	new_request();
	$GLOBALS['options']['active_plugins'] = [ 'some-widget-pack/plugin.php' ];
	EC_Component_Overrides::get_control_policy( 'heading' );
	check( 3 === $GLOBALS['stack_walks'], 'Changed active plugins did not invalidate the cache.' );

	new_request();
	$GLOBALS['options']['ec_control_policy_cache']['time'] = time() - 86401;
	EC_Component_Overrides::get_control_policy( 'heading' );
	check( 4 === $GLOBALS['stack_walks'], 'An expired cache was trusted.' );

	// --- Case 4: a type Elementor does not know yet is never cached ---

	new_request();
	$GLOBALS['known'] = false;
	$unknown          = EC_Component_Overrides::get_control_policy( 'late_widget' );
	check( [ 'allowed' => [], 'denied' => [], 'denied_sections' => [] ] === $unknown, 'Unknown widget should yield an empty policy.' );
	EC_Component_Overrides::persist_policy_cache();
	check( ! isset( $GLOBALS['options']['ec_control_policy_cache']['types']['late_widget'] ), 'An unknown widget type was cached.' );
	$GLOBALS['known'] = true;
	$late             = EC_Component_Overrides::get_control_policy( 'late_widget' );
	check( in_array( 'title', $late['allowed'], true ), 'Widget registered later never got a real policy.' );

	// --- Case 5: plugin/theme updates flush it ---

	EC_Component_Overrides::flush_policy_cache();
	check( ! isset( $GLOBALS['options']['ec_control_policy_cache'] ), 'Flush did not delete the persisted cache.' );

	echo "component policy cache: ok\n";
}
