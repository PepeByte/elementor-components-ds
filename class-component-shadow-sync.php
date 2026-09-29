<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure parent-to-instance shadow reconciliation.
 */
final class EC_Component_Shadow_Sync {

	/**
	 * Version stamp of a raw parent tree, equal to the `parent_version` that
	 * reconcile() returns for it. Cheap staleness check: no shadow is built.
	 */
	public static function version_for( array $parent_elements ): string {
		return self::parent_version( self::wrap_widget_roots( self::normalize_parent_tree( $parent_elements ) ) );
	}

	public static function reconcile(
		array $parent_elements,
		array $patch,
		string $instance_id,
		array $allowed_by_type
	): array {
		$parent = self::wrap_widget_roots( self::normalize_parent_tree( $parent_elements ) );
		$index  = self::index_elements( $parent );
		$patch  = EC_Component_Overrides::normalize( $patch, $index, $allowed_by_type );
		$map    = [];
		$used   = [ $instance_id => true ];

		return [
			'elements'       => self::build_shadow( $parent, $patch, $instance_id, $map, $used ),
			'overrides'      => $patch,
			'map'            => $map,
			'parent_version' => self::parent_version( $parent ),
			'source_tree'    => $parent,
		];
	}

	public static function extract_overrides(
		array $parent_elements,
		array $shadow_elements,
		array $source_map,
		array $existing_patch,
		array $allowed_by_type
	): array {
		$parent       = self::wrap_widget_roots( self::normalize_parent_tree( $parent_elements ) );
		$parent_index = self::index_elements( $parent );
		$shadow_index = self::index_by_id( $shadow_elements );
		$existing     = EC_Component_Overrides::normalize( $existing_patch, $parent_index, $allowed_by_type );
		$patch        = EC_Component_Overrides::empty_patch();

		foreach ( $parent_index as $source_id => $parent_element ) {
			$runtime_id = self::clean_key( $source_map[ $source_id ] ?? '' );
			$shadow     = $runtime_id ? ( $shadow_index[ $runtime_id ] ?? null ) : null;

			if ( ! self::matching_element( $shadow, $parent_element ) ) {
				if ( isset( $existing['widgets'][ $source_id ] ) ) {
					$patch['widgets'][ $source_id ] = $existing['widgets'][ $source_id ];
				}
				continue;
			}

			$type   = self::element_type( $parent_element );
			$values = EC_Component_Overrides::extract_widget(
				(array) ( $parent_element['settings'] ?? [] ),
				(array) ( $shadow['settings'] ?? [] ),
				$allowed_by_type[ $type ] ?? []
			);

			if ( $values ) {
				$patch['widgets'][ $source_id ] = [ 'widgetType' => $type ] + $values;
			}
		}

		return EC_Component_Overrides::normalize( $patch, $parent_index, $allowed_by_type );
	}

	/**
	 * Patch-type key for an element: widget type for widgets, element type otherwise.
	 */
	public static function element_type( array $element ): string {
		return (string) ( $element['widgetType'] ?? '' ) ?: (string) ( $element['elType'] ?? '' );
	}

	/**
	 * Index widgets AND containers (containers accept style-only overrides).
	 */
	public static function index_elements( array $elements ): array {
		$index = [];
		foreach ( $elements as $element ) {
			$id = self::clean_key( $element['id'] ?? '' );
			if ( $id && in_array( $element['elType'] ?? '', [ 'widget', 'container' ], true ) && ! isset( $index[ $id ] ) ) {
				$index[ $id ] = $element;
			}
			$index += self::index_elements( (array) ( $element['elements'] ?? [] ) );
		}
		return $index;
	}

	/**
	 * Nested hosts only accept container children; wrap widget-rooted components.
	 */
	private static function wrap_widget_roots( array $elements ): array {
		$needs_wrap = false;
		foreach ( $elements as $element ) {
			if ( 'container' !== ( $element['elType'] ?? '' ) ) {
				$needs_wrap = true;
				break;
			}
		}

		if ( ! $needs_wrap ) {
			return $elements;
		}

		return [ [
			'id'       => 'ecwrap',
			'elType'   => 'container',
			'settings' => [],
			'elements' => $elements,
		] ];
	}

	public static function index_widgets( array $elements ): array {
		$index = [];
		foreach ( $elements as $element ) {
			$id = self::clean_key( $element['id'] ?? '' );
			if ( $id && 'widget' === ( $element['elType'] ?? '' ) && ! isset( $index[ $id ] ) ) {
				$index[ $id ] = $element;
			}
			$index += self::index_widgets( (array) ( $element['elements'] ?? [] ) );
		}
		return $index;
	}

	public static function parent_version( array $elements ): string {
		return hash( 'sha256', wp_json_encode( $elements ) );
	}

	private static function normalize_parent_tree( array $elements ): array {
		$normalized = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! self::clean_key( $element['id'] ?? '' ) ) {
				continue;
			}

			if ( in_array( $element['elType'] ?? '', [ 'section', 'column' ], true ) ) {
				$element['elType'] = 'container';
				unset( $element['isInner'] );
			}

			$element['settings'] = (array) ( $element['settings'] ?? [] );
			$element['elements'] = self::normalize_parent_tree( (array) ( $element['elements'] ?? [] ) );
			$normalized[]        = $element;
		}
		return $normalized;
	}

	private static function build_shadow(
		array $elements,
		array $patch,
		string $instance_id,
		array &$map,
		array &$used
	): array {
		$shadow = [];
		foreach ( $elements as $element ) {
			$source_id = self::clean_key( $element['id'] ?? '' );
			if ( ! $source_id || isset( $map[ $source_id ] ) ) {
				continue;
			}

			$runtime_id        = self::runtime_id( $instance_id, $source_id, $used );
			$map[ $source_id ] = $runtime_id;
			$element['id']     = $runtime_id;
			$element['isLocked'] = true;

			if ( isset( $patch['widgets'][ $source_id ] ) &&
				in_array( $element['elType'] ?? '', [ 'widget', 'container' ], true ) ) {
				$element['settings'] = EC_Component_Overrides::apply_to_settings(
					(array) ( $element['settings'] ?? [] ),
					$patch['widgets'][ $source_id ]
				);
			}

			$element['elements'] = self::build_shadow(
				(array) ( $element['elements'] ?? [] ),
				$patch,
				$instance_id,
				$map,
				$used
			);
			$shadow[] = $element;
		}
		return $shadow;
	}

	private static function runtime_id( string $instance_id, string $source_id, array &$used ): string {
		$salt = 0;
		do {
			$id = substr( hash( 'sha256', $instance_id . ':' . $source_id . ':' . $salt ), 0, 7 );
			$salt++;
		} while ( isset( $used[ $id ] ) );

		$used[ $id ] = true;
		return $id;
	}

	private static function index_by_id( array $elements ): array {
		$index = [];
		foreach ( $elements as $element ) {
			$id = self::clean_key( $element['id'] ?? '' );
			if ( $id && ! isset( $index[ $id ] ) ) {
				$index[ $id ] = $element;
			}
			$index += self::index_by_id( (array) ( $element['elements'] ?? [] ) );
		}
		return $index;
	}

	private static function matching_element( $shadow, array $parent ): bool {
		return is_array( $shadow ) &&
			( $shadow['elType'] ?? '' ) === ( $parent['elType'] ?? '' ) &&
			( $shadow['widgetType'] ?? '' ) === ( $parent['widgetType'] ?? '' );
	}

	private static function clean_key( $value ): string {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
	}
}
