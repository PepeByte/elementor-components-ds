<?php
/**
 * Elementor Components uninstall cleanup.
 *
 * WordPress includes this file automatically when the plugin is deleted, with
 * WP_UNINSTALL_PLUGIN defined. Removes only data this plugin created:
 *   - elementor_library posts of template type "component" (and their meta)
 *   - the _ec_component_ref reference-index meta on consumer posts
 *   - the pending consumer-sync cron batches and their queue/lock transients
 *   - the cached control-policy option, the picker list and the storage-mode setting
 *
 * Consumer post content (pages/posts containing component_instance widgets)
 * is intentionally left intact — rewriting every document's _elementor_data
 * on uninstall would risk destroying user content.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// All component library posts, any status (published, pending, trashed...).
$component_ids = get_posts( [
	'post_type'      => 'elementor_library',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
	'meta_key'       => '_elementor_template_type',
	'meta_value'     => 'component',
] );

foreach ( $component_ids as $component_id ) {
	wp_delete_post( (int) $component_id, true );
}

// Drop the consumer -> component reference index.
delete_post_meta_by_key( '_ec_component_ref' );

// Cached control policies.
delete_option( 'ec_control_policy_cache' );
delete_option( 'ec_compact_storage' );
delete_transient( 'ec_components_list' );

// Pending fan-out batches and the transients that back them.
wp_unschedule_hook( 'ec_sync_consumers_batch' );

global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '\\_transient\\_ec\\_sync\\_queue\\_%'
	OR option_name LIKE '\\_transient\\_timeout\\_ec\\_sync\\_queue\\_%'
	OR option_name LIKE '\\_transient\\_ec\\_heal\\_%'
	OR option_name LIKE '\\_transient\\_timeout\\_ec\\_heal\\_%'"
);
