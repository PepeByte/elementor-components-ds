<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EC_Component_Admin {

	const PAGE = 'ec-component-tools';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 600 );
		add_action( 'admin_post_ec_resync', [ __CLASS__, 'handle_resync' ] );
		add_action( 'admin_post_ec_save_settings', [ __CLASS__, 'handle_settings' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=elementor_library',
			esc_html__( 'Components', 'elementor-components' ),
			esc_html__( 'Components', 'elementor-components' ),
			'edit_posts',
			'edit.php?post_type=elementor_library&tabs_group=library&elementor_library_type=' . EC_Component_CPT::TYPE
		);

		add_submenu_page(
			'edit.php?post_type=elementor_library',
			esc_html__( 'Component Sync', 'elementor-components' ),
			esc_html__( 'Component Sync', 'elementor-components' ),
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'render_page' ]
		);
	}

	private static function page_url( array $args = [] ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=elementor_library&page=' . self::PAGE ) );
	}

	public static function handle_resync(): void {
		check_admin_referer( 'ec_resync' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'elementor-components' ), 403 );
		}

		$component_id = absint( $_POST['component_id'] ?? 0 );
		$force        = ! empty( $_POST['force'] );
		$queued       = 0;

		if ( $component_id ) {
			$queued = EC_Component_Sync_Manager::resync( $component_id, $force );
		} else {
			foreach ( array_keys( EC_Component_Sync_Manager::usage_report() ) as $id ) {
				$queued += EC_Component_Sync_Manager::resync( (int) $id, $force );
			}
		}

		wp_safe_redirect( self::page_url( [ 'ec_notice' => 'queued', 'ec_count' => $queued ] ) );
		exit;
	}

	public static function handle_settings(): void {
		check_admin_referer( 'ec_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'elementor-components' ), 403 );
		}

		update_option( EC_Component_Sync_Manager::COMPACT_OPTION, empty( $_POST['compact'] ) ? 0 : 1 );
		wp_safe_redirect( self::page_url( [ 'ec_notice' => 'saved' ] ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report  = EC_Component_Sync_Manager::usage_report();
		$compact = (bool) get_option( EC_Component_Sync_Manager::COMPACT_OPTION, false );
		$notice  = sanitize_key( wp_unslash( $_GET['ec_notice'] ?? '' ) );
		$count   = absint( $_GET['ec_count'] ?? 0 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Component Sync', 'elementor-components' ); ?></h1>

			<?php if ( 'queued' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html( sprintf(
						/* translators: %d: number of pages queued for syncing. */
						_n( '%d page queued. The first batch was synced now; the rest continue in the background.', '%d pages queued. The first batch was synced now; the rest continue in the background.', $count, 'elementor-components' ),
						$count
					) );
					?>
				</p></div>
			<?php elseif ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved. Run "Sync all (rebuild everything)" to apply the storage mode to existing pages.', 'elementor-components' ); ?></p></div>
			<?php endif; ?>

			<p><?php echo esc_html__( 'Editing a component updates its pages automatically. Use this screen to check what is out of date, force a re-sync (for example after a migration or import), or change how instances are stored.', 'elementor-components' ); ?></p>

			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php echo esc_html__( 'Component', 'elementor-components' ); ?></th>
					<th><?php echo esc_html__( 'Pages using it', 'elementor-components' ); ?></th>
					<th><?php echo esc_html__( 'Out of date', 'elementor-components' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $report ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'No pages use a component yet.', 'elementor-components' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $report as $component_id => $row ) : ?>
					<tr>
						<td>
							<?php if ( $row['available'] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $component_id . '&action=elementor' ) ); ?>"><?php echo esc_html( get_the_title( $component_id ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( sprintf( /* translators: %d: component post ID. */ __( 'Unavailable component #%d (deleted or unpublished)', 'elementor-components' ), $component_id ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo (int) count( $row['pages'] ); ?></td>
						<td><?php echo $row['stale'] ? '<strong>' . (int) count( $row['stale'] ) . '</strong>' : '0'; ?></td>
						<td>
							<?php if ( $row['available'] ) : ?>
								<?php self::action_form( (int) $component_id, false, __( 'Sync now', 'elementor-components' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $report ) : ?>
				<p style="margin-top:12px">
					<?php self::action_form( 0, false, __( 'Sync all out-of-date pages', 'elementor-components' ) ); ?>
					<?php self::action_form( 0, true, __( 'Sync all (rebuild everything)', 'elementor-components' ) ); ?>
				</p>
			<?php endif; ?>

			<h2 style="margin-top:2em"><?php echo esc_html__( 'Storage', 'elementor-components' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ec_save_settings' ); ?>
				<input type="hidden" name="action" value="ec_save_settings">
				<label>
					<input type="checkbox" name="compact" value="1" <?php checked( $compact ); ?>>
					<strong><?php echo esc_html__( 'Compact storage (experimental)', 'elementor-components' ); ?></strong>
				</label>
				<p class="description" style="max-width:720px">
					<?php echo esc_html__( 'Saves instances without a copy of the component, which makes pages, revisions and exports much smaller. The component content is then rebuilt from the parent whenever a page is rendered, so those pages depend on this plugin: turn this off and run "Sync all (rebuild everything)" before uninstalling. Verify your pages after enabling.', 'elementor-components' ); ?>
				</p>
				<?php submit_button( __( 'Save', 'elementor-components' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function action_form( int $component_id, bool $force, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<?php wp_nonce_field( 'ec_resync' ); ?>
			<input type="hidden" name="action" value="ec_resync">
			<input type="hidden" name="component_id" value="<?php echo (int) $component_id; ?>">
			<?php if ( $force ) : ?>
				<input type="hidden" name="force" value="1">
			<?php endif; ?>
			<button type="submit" class="button<?php echo $component_id ? '' : ' button-primary'; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
