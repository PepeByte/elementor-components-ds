<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native nested host for a synchronized, structurally locked component shadow.
 */
class EC_Component_Instance_Widget extends \Elementor\Modules\NestedElements\Base\Widget_Nested_Base {

	public function get_name(): string {
		return 'component_instance';
	}

	public function get_title(): string {
		return esc_html__( 'Component Instance', 'elementor-components' );
	}

	public function get_icon(): string {
		return 'eicon-star';
	}

	public function get_categories(): array {
		return [ 'ec-components' ];
	}

	public function get_keywords(): array {
		return [ 'component', 'reusable', 'template', 'sync', 'global' ];
	}

	protected function get_default_children_elements(): array {
		return [];
	}

	protected function get_default_repeater_title_setting_key(): string {
		return '_title';
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'section_component', [
			'label' => esc_html__( 'Component', 'elementor-components' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'component_id', [
			'label'       => esc_html__( 'Component', 'elementor-components' ),
			'type'        => \Elementor\Controls_Manager::SELECT2,
			'options'     => EC_Component_CPT::get_components(),
			'label_block' => true,
			'description' => esc_html__( 'The parent owns structure; widget Content and Style can vary here.', 'elementor-components' ),
			// The editor swaps children itself on change; a template re-render here
			// would race it with stale server markup.
			'render_type' => 'none',
		] );

		foreach ( [
			'ec_overrides'      => wp_json_encode( EC_Component_Overrides::empty_patch() ),
			'ec_shadow_map'     => '{}',
			'ec_parent_version' => '',
		] as $control => $default ) {
			$this->add_control( $control, [
				'type'        => \Elementor\Controls_Manager::HIDDEN,
				'default'     => $default,
				// Written on every shadow edit/sync; without 'none' each write triggers
				// a remote re-render from saved data that overwrites live child views.
				'render_type' => 'none',
			] );
		}

		$this->add_control( 'sync_component', [
			'type'        => \Elementor\Controls_Manager::BUTTON,
			'text'        => esc_html__( 'Sync Parent Now', 'elementor-components' ),
			'event'       => 'ec:sync-component',
			'condition'   => [ 'component_id!' => '' ],
			'separator'   => 'before',
		] );

		$this->add_control( 'edit_parent_component', [
			'type'        => \Elementor\Controls_Manager::BUTTON,
			'text'        => esc_html__( 'Edit Parent Component', 'elementor-components' ),
			'event'       => 'ec:edit-parent-component',
			'condition'   => [ 'component_id!' => '' ],
		] );

		$this->end_controls_section();
	}

	/**
	 * A JS template makes the editor render this widget client-side ('js' template
	 * type). Without one it falls back to remote server rendering, whose stale
	 * markup replaces the live child views on every re-render.
	 */
	protected function content_template(): void {
		?>
		<# if ( ! view.collection || ! view.collection.length ) { #>
		<div class="ec-empty"><?php echo esc_html__( 'Select or sync a component.', 'elementor-components' ); ?></div>
		<# } #>
		<?php
	}

	protected function render(): void {
		$editor  = \Elementor\Plugin::$instance->editor ?? null;
		$editing = $editor && $editor->is_edit_mode();

		// Compact storage (or an instance created through an API) has no stored
		// children: build them from the parent now. render() only runs on a
		// render-cache miss, so warm pages never pay for this. The editor
		// supplies its own live children.
		if ( ! $editing && ! $this->get_children() ) {
			EC_Component_Sync_Manager::populate_children( $this );
		}

		$children = $this->get_children();
		if ( ! $children ) {
			if ( $editing ) {
				echo '<div class="ec-empty">' . esc_html__( 'Select or sync a component.', 'elementor-components' ) . '</div>';
			}
			return;
		}

		foreach ( array_keys( $children ) as $index ) {
			$this->print_child( $index );
		}
	}
}
