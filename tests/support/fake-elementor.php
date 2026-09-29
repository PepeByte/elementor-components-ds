<?php
/**
 * Minimal Elementor stand-in: one widget type ("heading") whose `title` control
 * is overridable and whose `_margin` control is not, plus the manager objects
 * EC_Component_Overrides::get_control_policy() walks.
 */

namespace Elementor {
	class Base_Data_Control {}
	class Text_Control extends Base_Data_Control {}

	class Fake_Widget {
		public function get_stack() {
			return [
				'controls'       => [
					'section_content' => [ 'type' => 'section', 'tab' => 'content' ],
					'title'           => [ 'type' => 'text', 'section' => 'section_content' ],
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
	$plugin                   = new \Elementor\Plugin();
	$plugin->widgets_manager  = new class {
		public function get_widget_types( $type ) { return new \Elementor\Fake_Widget(); }
	};
	$plugin->elements_manager = new class {
		public function get_element_types( $type ) { return new \Elementor\Fake_Widget(); }
	};
	$plugin->controls_manager = new class {
		public function get_control( $type ) { return 'text' === $type ? new \Elementor\Text_Control() : null; }
	};
	$plugin->breakpoints      = new class {
		public function get_active_devices_list() { return [ 'desktop' ]; }
	};
	\Elementor\Plugin::$instance = $plugin;
}
