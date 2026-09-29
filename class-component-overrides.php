<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presence-based sparse widget setting overrides.
 */
final class EC_Component_Overrides {

	const VERSION = 1;

	const POLICY_OPTION = 'ec_control_policy_cache';
	const POLICY_TTL    = 86400;

	/** @var array<string, array>|null Control policy per widget type. */
	private static $policies = null;
	private static $policy_dirty = false;
	private static $fingerprint = null;
	private static $policy_time = 0;

	public static function empty_patch(): array {
		return [ 'version' => self::VERSION, 'widgets' => [] ];
	}

	public static function decode( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw + self::empty_patch();
		}

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded + self::empty_patch() : self::empty_patch();
	}

	public static function encode( array $patch ): string {
		return wp_json_encode( $patch );
	}

	public static function normalize( array $raw, array $widget_index, array $allowed_by_type ): array {
		$normalized = self::empty_patch();
		$widgets    = is_array( $raw['widgets'] ?? null ) ? $raw['widgets'] : [];

		foreach ( array_slice( $widgets, 0, 500, true ) as $source_id => $entry ) {
			$source_id = self::clean_key( $source_id );
			if ( ! $source_id || ! is_array( $entry ) || ! isset( $widget_index[ $source_id ] ) ) {
				continue;
			}

			$indexed     = $widget_index[ $source_id ];
			$widget_type = (string) ( $indexed['widgetType'] ?? '' );
			if ( ! $widget_type ) {
				// Containers carry no widgetType; their element type is the patch type.
				$widget_type = (string) ( $indexed['elType'] ?? '' );
			}
			if ( ! $widget_type || ( $entry['widgetType'] ?? $widget_type ) !== $widget_type ) {
				continue;
			}

			$allowed = array_fill_keys( $allowed_by_type[ $widget_type ] ?? [], true );
			$clean   = [ 'widgetType' => $widget_type ];

			foreach ( [ 'values', 'globals', 'dynamic' ] as $bucket ) {
				$values = self::filter_bucket( $entry[ $bucket ] ?? [], $allowed, $bucket );
				if ( $values ) {
					$clean[ $bucket ] = $values;
				}
			}

			if ( count( $clean ) > 1 ) {
				$normalized['widgets'][ $source_id ] = $clean;
			}
		}

		return $normalized;
	}

	public static function apply_to_settings( array $settings, array $widget_patch ): array {
		foreach ( (array) ( $widget_patch['values'] ?? [] ) as $key => $value ) {
			$settings[ $key ] = $value;
			unset( $settings['__globals__'][ $key ], $settings['__dynamic__'][ $key ] );
		}

		foreach ( [ 'globals' => '__globals__', 'dynamic' => '__dynamic__' ] as $bucket => $meta_key ) {
			foreach ( (array) ( $widget_patch[ $bucket ] ?? [] ) as $key => $value ) {
				$settings[ $meta_key ][ $key ] = $value;
			}
		}

		foreach ( [ '__globals__', '__dynamic__' ] as $meta_key ) {
			if ( isset( $settings[ $meta_key ] ) && ! $settings[ $meta_key ] ) {
				unset( $settings[ $meta_key ] );
			}
		}

		return $settings;
	}

	public static function extract_widget( array $parent, array $shadow, array $allowed ): array {
		$patch          = [];
		$parent_globals = (array) ( $parent['__globals__'] ?? [] );
		$shadow_globals = (array) ( $shadow['__globals__'] ?? [] );
		$parent_dynamic = (array) ( $parent['__dynamic__'] ?? [] );
		$shadow_dynamic = (array) ( $shadow['__dynamic__'] ?? [] );

		foreach ( $allowed as $key ) {
			$parent_has = array_key_exists( $key, $parent );
			$shadow_has = array_key_exists( $key, $shadow );
			$metadata_cleared =
				( array_key_exists( $key, $parent_globals ) && ! array_key_exists( $key, $shadow_globals ) ) ||
				( array_key_exists( $key, $parent_dynamic ) && ! array_key_exists( $key, $shadow_dynamic ) );

			if ( $shadow_has && ( ! $parent_has || $shadow[ $key ] !== $parent[ $key ] || $metadata_cleared ) ) {
				$patch['values'][ $key ] = $shadow[ $key ];
			} elseif ( $metadata_cleared ) {
				$patch['values'][ $key ] = '';
			}

			if ( array_key_exists( $key, $shadow_globals ) &&
				( ! array_key_exists( $key, $parent_globals ) || $shadow_globals[ $key ] !== $parent_globals[ $key ] ) ) {
				$patch['globals'][ $key ] = $shadow_globals[ $key ];
			}

			if ( array_key_exists( $key, $shadow_dynamic ) &&
				( ! array_key_exists( $key, $parent_dynamic ) || $shadow_dynamic[ $key ] !== $parent_dynamic[ $key ] ) ) {
				$patch['dynamic'][ $key ] = $shadow_dynamic[ $key ];
			}
		}

		return $patch;
	}

	public static function reset_control( array $patch, string $source_id, string $control ): array {
		foreach ( [ 'values', 'globals', 'dynamic' ] as $bucket ) {
			unset( $patch['widgets'][ $source_id ][ $bucket ][ $control ] );
			if ( isset( $patch['widgets'][ $source_id ][ $bucket ] ) && ! $patch['widgets'][ $source_id ][ $bucket ] ) {
				unset( $patch['widgets'][ $source_id ][ $bucket ] );
			}
		}

		if ( isset( $patch['widgets'][ $source_id ] ) && count( $patch['widgets'][ $source_id ] ) <= 1 ) {
			unset( $patch['widgets'][ $source_id ] );
		}

		return $patch;
	}

	public static function get_allowed_controls( string $widget_type ): array {
		return self::get_control_policy( $widget_type )['allowed'];
	}

	/**
	 * Which controls of a widget type an instance may override, and which are
	 * locked. Walking a widget's control stack costs hundreds of iterations, and
	 * this runs per widget type on every editor sync and consumer rebuild, so the
	 * result is cached for the request and persisted in an option keyed by a
	 * fingerprint of everything that can change a stack (plugin/Elementor
	 * versions, active plugins, breakpoints). A type Elementor does not know
	 * yet is never cached.
	 */
	public static function get_control_policy( string $widget_type ): array {
		self::load_policy_cache();
		if ( isset( self::$policies[ $widget_type ] ) ) {
			return self::$policies[ $widget_type ];
		}

		$policy = self::compute_control_policy( $widget_type );
		if ( null === $policy ) {
			return [ 'allowed' => [], 'denied' => [], 'denied_sections' => [] ];
		}

		self::$policies[ $widget_type ] = $policy;
		if ( ! self::$policy_dirty ) {
			self::$policy_dirty = true;
			add_action( 'shutdown', [ __CLASS__, 'persist_policy_cache' ] );
		}
		return $policy;
	}

	public static function init(): void {
		// A plugin/theme update can change any widget's controls.
		add_action( 'upgrader_process_complete', [ __CLASS__, 'flush_policy_cache' ] );
	}

	public static function flush_policy_cache(): void {
		delete_option( self::POLICY_OPTION );
		self::$policies     = null;
		self::$fingerprint  = null;
		self::$policy_time  = 0;
		self::$policy_dirty = false;
	}

	public static function persist_policy_cache(): void {
		if ( ! self::$policy_dirty || null === self::$policies ) {
			return;
		}
		self::$policy_dirty = false;
		update_option( self::POLICY_OPTION, [
			'fp'    => self::policy_fingerprint(),
			'time'  => self::$policy_time,
			'types' => self::$policies,
		], false );
	}

	private static function load_policy_cache(): void {
		if ( null !== self::$policies ) {
			return;
		}
		$stored = get_option( self::POLICY_OPTION, [] );
		$fresh  = is_array( $stored ) &&
			( $stored['fp'] ?? '' ) === self::policy_fingerprint() &&
			( time() - (int) ( $stored['time'] ?? 0 ) ) < self::POLICY_TTL;
		self::$policies = $fresh ? (array) ( $stored['types'] ?? [] ) : [];
		// Keep the original timestamp so the TTL bounds staleness, not the last write.
		self::$policy_time  = $fresh ? (int) $stored['time'] : time();
		self::$policy_dirty = false;
	}

	private static function policy_fingerprint(): string {
		if ( null === self::$fingerprint ) {
			$plugin  = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance : null;
			$devices = $plugin && isset( $plugin->breakpoints ) ? $plugin->breakpoints->get_active_devices_list() : [];
			self::$fingerprint = md5( wp_json_encode( [
				defined( 'EC_VERSION' ) ? EC_VERSION : '',
				defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
				get_option( 'active_plugins', [] ),
				$devices,
			] ) );
		}
		return self::$fingerprint;
	}

	private static function compute_control_policy( string $widget_type ): ?array {
		$policy  = [ 'allowed' => [], 'denied' => [], 'denied_sections' => [] ];
		$plugin  = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance : null;
		// Containers are elements, not widgets, and only their Style tab may vary per instance.
		$style_only = 'container' === $widget_type;
		$widget     = null;
		if ( $plugin ) {
			$widget = $style_only
				? $plugin->elements_manager->get_element_types( 'container' )
				: $plugin->widgets_manager->get_widget_types( $widget_type );
		}
		if ( ! $widget ) {
			return null;
		}

		$stack    = $widget->get_stack();
		$controls = array_merge( $stack['controls'] ?? [], $stack['style_controls'] ?? [] );

		foreach ( $controls as $name => $control ) {
			$name    = (string) $name;
			$control = (array) $control;
			if ( 'section' === ( $control['type'] ?? '' ) ) {
				if ( self::is_advanced_section( $name ) ||
					( $style_only && 'style' !== ( $control['tab'] ?? '' ) ) ) {
					$policy['denied_sections'][] = $name;
				}
				continue;
			}

			$editable = self::is_editable_data_control( $name, $control, $plugin ) &&
				( ! $style_only || 'style' === ( $control['tab'] ?? '' ) );
			$bucket   = $editable ? 'allowed' : 'denied';
			$names  = [ $name ];
			if ( array_key_exists( 'responsive', $control ) ) {
				foreach ( $plugin->breakpoints->get_active_devices_list() as $device ) {
					if ( 'desktop' !== $device ) {
						$names[] = $name . '_' . $device;
					}
				}
			}
			$policy[ $bucket ] = array_merge( $policy[ $bucket ], $names );
		}

		foreach ( $policy as $key => $values ) {
			$policy[ $key ] = array_values( array_unique( $values ) );
		}
		return $policy;
	}

	private static function is_editable_data_control( string $name, array $control, $plugin ): bool {
		$section = (string) ( $control['section'] ?? '' );
		if ( 0 === strpos( $name, '_' ) || 0 === strpos( $name, 'ec_' ) || self::is_advanced_section( $section ) ) {
			return false;
		}

		$type             = $control['type'] ?? '';
		$control_instance = $plugin->controls_manager->get_control( $type );
		return $control_instance instanceof \Elementor\Base_Data_Control;
	}

	private static function is_advanced_section( string $section ): bool {
		return 0 === strpos( $section, '_section' ) || in_array(
			$section,
			[ 'section_effects', 'section_custom_css', 'section_attributes' ],
			true
		);
	}

	private static function filter_bucket( $bucket, array $allowed, string $type ): array {
		if ( ! is_array( $bucket ) ) {
			return [];
		}

		$clean = [];
		foreach ( array_slice( $bucket, 0, 500, true ) as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			if ( 'globals' === $type && ( ! is_string( $value ) || strlen( $value ) > 2048 ) ) {
				continue;
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	private static function clean_key( $value ): string {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
	}
}
