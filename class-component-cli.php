<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI: inspect and re-sync component usage without the admin UI or WP-Cron.
 *
 * Loaded only when WP_CLI is defined (see elementor-components.php).
 */
class EC_Component_CLI {

	/**
	 * Show every component in use, how many pages use it and how many are out of date.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ec status
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function status( $args, $assoc_args ): void {
		$rows = [];
		foreach ( EC_Component_Sync_Manager::usage_report() as $component_id => $row ) {
			$rows[] = [
				'component_id' => $component_id,
				'title'        => $row['available'] ? get_the_title( $component_id ) : '(unavailable)',
				'pages'        => count( $row['pages'] ),
				'out_of_date'  => count( $row['stale'] ),
			];
		}

		if ( ! $rows ) {
			\WP_CLI::log( 'No pages use a component.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'component_id', 'title', 'pages', 'out_of_date' ] );
	}

	/**
	 * Re-sync the pages that use a component, synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [<component_id>]
	 * : Component to sync. Omit (or pass --all) to sync every component in use.
	 *
	 * [--all]
	 * : Sync every component in use.
	 *
	 * [--force]
	 * : Rebuild instances even if their parent version is current (applies the
	 * current storage mode to every page).
	 *
	 * [--no-warm]
	 * : Do not regenerate the CSS of rebuilt pages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ec sync 123
	 *     wp ec sync --all --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ): void {
		$force = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$warm  = ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-warm', false );

		$ids = isset( $args[0] ) ? [ absint( $args[0] ) ] : array_keys( EC_Component_Sync_Manager::usage_report() );
		if ( ! $ids || ( isset( $args[0] ) && ! $ids[0] ) ) {
			\WP_CLI::error( 'Pass a component ID, or use --all.' );
		}

		$consumers = 0;
		$changed   = 0;
		foreach ( $ids as $component_id ) {
			$result     = EC_Component_Sync_Manager::resync_blocking( (int) $component_id, $force, $warm );
			$consumers += $result['consumers'];
			$changed   += $result['changed'];
			\WP_CLI::log( sprintf( 'Component %d: %d page(s), %d rebuilt.', $component_id, $result['consumers'], $result['changed'] ) );
		}

		\WP_CLI::success( sprintf( '%d page(s) checked, %d rebuilt.', $consumers, $changed ) );
	}
}
