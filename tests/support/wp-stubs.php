<?php
/**
 * In-memory WordPress stubs shared by the standalone tests: post meta,
 * transients, options, scheduled events, actions/filters and a fake $wpdb that
 * answers the plugin's consumer-discovery queries from the meta store.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

// --- In-memory WordPress stubs ---

$GLOBALS['meta']       = [];
$GLOBALS['transients'] = [];
$GLOBALS['scheduled']  = [];
$GLOBALS['actions']    = [];
$GLOBALS['writes']     = 0;
$GLOBALS['can_unfiltered'] = true;

function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_slash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_doing_cron() { return true; }
function current_user_can( $cap, $id = 0 ) {
	if ( 'unfiltered_html' === $cap ) {
		return $GLOBALS['can_unfiltered'];
	}
	return empty( $GLOBALS['denied'][ (int) $id ] );
}
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key ) ); }
function __( $text ) { return $text; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function add_action() {}
function wp_kses_post_deep( $value ) { return $value; }
function clean_post_cache( $id ) { $GLOBALS['actions'][] = [ 'clean_post_cache', $id ]; }
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = array_merge( [ $hook ], $args ); }

function get_post_type( $id ) { return 123 === (int) $id ? 'elementor_library' : 'page'; }
function get_post_status( $id ) { return $GLOBALS['status'][ (int) $id ] ?? 'publish'; }

function get_post_meta( $id, $key, $single = false ) {
	$GLOBALS['reads'][ $id . ':' . $key ] = ( $GLOBALS['reads'][ $id . ':' . $key ] ?? 0 ) + 1;
	$value = $GLOBALS['meta'][ $id ][ $key ] ?? ( $single ? '' : [] );
	return $single && is_array( $value ) && 'multi' === ( $GLOBALS['meta_kind'][ $id ][ $key ] ?? '' ) ? ( $value[0] ?? '' ) : $value;
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['writes']++; $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ], $GLOBALS['meta_kind'][ $id ][ $key ] ); return true; }
function add_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ $id ][ $key ][]         = $value;
	$GLOBALS['meta_kind'][ $id ][ $key ] = 'multi';
	return true;
}

function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }
function wp_schedule_single_event( $time, $hook, $args = [] ) { $GLOBALS['scheduled'][] = [ $hook, $args ]; return true; }

/** Fake $wpdb: answers the two consumer-discovery queries from the meta store. */
$GLOBALS['wpdb'] = new class {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query, ...$args ) { return $query . '|' . implode( '|', $args ); }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function get_col( $query ) {
		$ids = [];
		foreach ( $GLOBALS['meta'] as $id => $metas ) {
			if ( false !== strpos( $query, 'ec_component_ref' ) ) {
				$refs = $metas['_ec_component_ref'] ?? [];
				if ( in_array( substr( strrchr( $query, '|' ), 1 ), array_map( 'strval', (array) $refs ), true ) ) {
					$ids[] = $id;
				}
			} elseif ( false !== strpos( ( $metas['_elementor_data'] ?? '' ), 'component_instance' ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
};


$GLOBALS['options'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['titles']  = [];

function apply_filters( $hook, $value, ...$args ) {
	$callback = $GLOBALS['filters'][ $hook ] ?? null;
	return $callback ? $callback( $value, ...$args ) : $value;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function get_the_title( $id ) { return $GLOBALS['titles'][ $id ] ?? ''; }
function get_transient_timeout_stub() {}
