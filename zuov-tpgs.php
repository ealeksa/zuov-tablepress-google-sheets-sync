<?php
/**
 * Plugin Name: ZUOV TablePress and Google Sheets Sync
 * Plugin URI: https://github.com/ealeksa/zuov-tablepress-google-sheets-sync
 * Description: Synchronizes one or more Google Sheets CSV exports into TablePress tables, with manual, cron, and webhook triggers.
 * Version: 2.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Aleksa Eremija
 * Author URI: https://zuov.gov.rs/
 * License: GPL-2.0-or-later
 * Text Domain: zuov-tpgs
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

final class ZUOV_TPGS {
	const OPTION_NAME = 'zuov_tpgs_options';
	const CRON_HOOK   = 'zuov_tpgs_sync_event';
	const LOCK_NAME   = 'zuov_tpgs_sync_lock';
	const MENU_SLUG   = 'zuov-tpgs';
	const REST_NS     = 'zuov-tpgs/v1';
	const REST_ROUTE  = '/sync';

	/**
	 * Boots the plugin.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_query_webhook' ), 20 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
	}

	/**
	 * Loads translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'zuov-tpgs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Creates defaults and schedules cron on activation.
	 */
	public static function activate() {
		$options = self::get_options();
		update_option( self::OPTION_NAME, $options, false );
		self::reschedule_cron( $options );
	}

	/**
	 * Clears cron on deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_NAME );
	}

	/**
	 * Returns a default sync profile.
	 */
	private static function default_profile() {
		return array(
			'id'                 => self::new_profile_id(),
			'name'               => __( 'Main table', 'zuov-tpgs' ),
			'enabled'            => 1,
			'google_url'         => 'https://docs.google.com/spreadsheets/d/12W_EVTY_S97OhMS-op16OrMfHH_9SkGo53y2sl5mhWY/edit?usp=sharing',
			'table_id'           => '',
			'page_url'           => 'https://zuov.gov.rs/spoljni-saradnici/',
			'remove_empty_rows'  => 1,
			'renumber_first_col' => 1,
			'sort_mode'          => 'none',
			'last_hash'          => '',
			'last_success'       => '',
			'last_status'        => '',
			'last_message'       => '',
			'last_rows'          => 0,
		);
	}

	/**
	 * Returns plugin defaults.
	 */
	private static function defaults() {
		return array(
			'schema_version' => 2,
			'profiles'       => array( self::default_profile() ),
			'interval'       => 'daily',
			'secret'         => wp_generate_password( 32, false, false ),
			'last_run'       => '',
			'last_success'   => '',
			'last_status'    => '',
			'last_message'   => '',
			'last_rows'      => 0,
			'last_source'    => '',
		);
	}

	/**
	 * Gets merged and normalized options.
	 */
	private static function get_options() {
		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$options = self::migrate_legacy_options( $options );
		$options = array_merge( self::defaults(), $options );
		$options['profiles'] = self::normalize_profiles( $options['profiles'] );

		return $options;
	}

	/**
	 * Converts 1.x single-table options to 2.x profiles.
	 */
	private static function migrate_legacy_options( $options ) {
		if ( isset( $options['profiles'] ) && is_array( $options['profiles'] ) ) {
			return $options;
		}

		$profile = self::default_profile();
		$profile['name'] = __( 'Migrated table', 'zuov-tpgs' );

		foreach ( array( 'google_url', 'table_id', 'page_url', 'remove_empty_rows', 'renumber_first_col', 'last_hash', 'last_success', 'last_status', 'last_message', 'last_rows' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$profile[ $key ] = $options[ $key ];
			}
		}

		$profile['sort_mode'] = ! empty( $options['sort_second_col'] ) ? 'sr' : 'none';
		$options['profiles'] = array( $profile );
		$options['schema_version'] = 2;

		return $options;
	}

	/**
	 * Normalizes all profiles.
	 */
	private static function normalize_profiles( $profiles ) {
		if ( ! is_array( $profiles ) || empty( $profiles ) ) {
			$profiles = array( self::default_profile() );
		}

		$normalized = array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$profile = array_merge( self::default_profile(), $profile );
			$profile['id'] = self::sanitize_profile_id( $profile['id'] );
			if ( '' === $profile['id'] ) {
				$profile['id'] = self::new_profile_id();
			}
			$profile['enabled'] = ! empty( $profile['enabled'] ) ? 1 : 0;
			$profile['remove_empty_rows'] = ! empty( $profile['remove_empty_rows'] ) ? 1 : 0;
			$profile['renumber_first_col'] = ! empty( $profile['renumber_first_col'] ) ? 1 : 0;
			$profile['sort_mode'] = array_key_exists( $profile['sort_mode'], self::sort_mode_options() ) ? $profile['sort_mode'] : 'none';
			$normalized[] = $profile;
		}

		if ( empty( $normalized ) ) {
			$normalized[] = self::default_profile();
		}

		return $normalized;
	}

	/**
	 * Creates a new profile ID.
	 */
	private static function new_profile_id() {
		return 'profile_' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Sanitizes a profile ID.
	 */
	private static function sanitize_profile_id( $profile_id ) {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $profile_id );
	}

	/**
	 * Registers custom cron intervals.
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['zuov_tpgs_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'zuov-tpgs' ),
		);
		$schedules['zuov_tpgs_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'zuov-tpgs' ),
		);
		$schedules['zuov_tpgs_30min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'zuov-tpgs' ),
		);

		return $schedules;
	}

	/**
	 * Adds the admin screen.
	 */
	public static function admin_menu() {
		add_menu_page(
			__( 'TablePress Google Sync', 'zuov-tpgs' ),
			__( 'TP Google Sync', 'zuov-tpgs' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-update',
			81
		);
	}

	/**
	 * Adds settings link on the Plugins screen.
	 */
	public static function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'zuov-tpgs' ) . '</a>' );

		return $links;
	}

	/**
	 * Shows dependency notice when TablePress is inactive.
	 */
	public static function dependency_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::tablepress_ready() ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'ZUOV TablePress and Google Sheets Sync:', 'zuov-tpgs' ) . '</strong> ' . esc_html__( 'TablePress must be active for synchronization to work.', 'zuov-tpgs' ) . '</p></div>';
	}

	/**
	 * Renders and handles the admin page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'zuov-tpgs' ) );
		}

		$options = self::get_options();
		$notice  = null;

		if ( isset( $_POST['zuov_tpgs_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['zuov_tpgs_action'] ) );

			if ( 'save' === $action || 'add_profile' === $action ) {
				check_admin_referer( 'zuov_tpgs_save' );
				$options = self::sanitize_options_from_post( $options );
				if ( 'add_profile' === $action ) {
					$new_profile = self::default_profile();
					$new_profile['name'] = sprintf( __( 'Table %d', 'zuov-tpgs' ), count( $options['profiles'] ) + 1 );
					$new_profile['google_url'] = '';
					$new_profile['table_id'] = '';
					$options['profiles'][] = $new_profile;
				}
				update_option( self::OPTION_NAME, $options, false );
				self::reschedule_cron( $options );
				$notice = array( 'success', __( 'Settings saved.', 'zuov-tpgs' ) );
			} elseif ( 'sync' === $action ) {
				check_admin_referer( 'zuov_tpgs_sync' );
				$profile_id = isset( $_POST['zuov_tpgs_profile_id'] ) ? self::sanitize_profile_id( wp_unslash( $_POST['zuov_tpgs_profile_id'] ) ) : '';
				$result = self::sync( true, 'manual', $profile_id );
				$options = self::get_options();
				$notice = is_wp_error( $result )
					? array( 'error', $result->get_error_message() )
					: array( 'success', $result['message'] );
			} elseif ( 'secret' === $action ) {
				check_admin_referer( 'zuov_tpgs_secret' );
				$options['secret'] = wp_generate_password( 32, false, false );
				update_option( self::OPTION_NAME, $options, false );
				$notice = array( 'success', __( 'Webhook secret changed.', 'zuov-tpgs' ) );
			}
		}

		$webhook_all = self::query_webhook_url( $options['secret'], '' );
		$rest_webhook_all = add_query_arg( 'secret', rawurlencode( $options['secret'] ), rest_url( self::REST_NS . self::REST_ROUTE ) );
		$next_run = wp_next_scheduled( self::CRON_HOOK );
		$intervals = self::interval_options();
		$sort_modes = self::sort_mode_options();
		$tablepress_ready = self::tablepress_ready();
		$sync_button_attributes = $tablepress_ready ? array() : array( 'disabled' => 'disabled' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'ZUOV TablePress and Google Sheets Sync', 'zuov-tpgs' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php echo esc_html__( 'Synchronize one or more Google Sheets CSV exports into existing TablePress tables.', 'zuov-tpgs' ); ?>
				<a href="#zuov-tpgs-google-guide"><?php echo esc_html__( 'Google Sheets setup guide', 'zuov-tpgs' ); ?></a>
			</p>

			<?php if ( ! $tablepress_ready ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'TablePress is not currently available. Saving settings is possible, but synchronization is disabled.', 'zuov-tpgs' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'zuov_tpgs_save' ); ?>
				<input type="hidden" name="zuov_tpgs_action" value="save" />

				<h2><?php echo esc_html__( 'Sync profiles', 'zuov-tpgs' ); ?></h2>
				<p><?php echo esc_html__( 'Each profile maps one Google Sheet to one existing TablePress table.', 'zuov-tpgs' ); ?></p>

				<?php foreach ( $options['profiles'] as $index => $profile ) : ?>
					<?php
					$csv_url = self::build_csv_url( $profile['google_url'] );
					$profile_webhook = self::query_webhook_url( $options['secret'], $profile['id'] );
					?>
					<div class="postbox" style="max-width: 1180px; padding: 0 16px 12px; margin-top: 16px;">
						<h3><?php echo esc_html( $profile['name'] ? $profile['name'] : sprintf( __( 'Profile %d', 'zuov-tpgs' ), $index + 1 ) ); ?></h3>
						<input type="hidden" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( $profile['id'] ); ?>" />

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="zuov_tpgs_profile_name_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Profile name', 'zuov-tpgs' ); ?></label></th>
								<td><input class="regular-text" type="text" id="zuov_tpgs_profile_name_<?php echo esc_attr( (string) $index ); ?>" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( $profile['name'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Active', 'zuov-tpgs' ); ?></th>
								<td><label><input type="checkbox" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( $profile['enabled'] ); ?> /> <?php echo esc_html__( 'Enable this sync profile', 'zuov-tpgs' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="zuov_tpgs_google_url_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Google Sheet URL', 'zuov-tpgs' ); ?></label></th>
								<td>
									<input class="large-text code" type="url" id="zuov_tpgs_google_url_<?php echo esc_attr( (string) $index ); ?>" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][google_url]" value="<?php echo esc_attr( $profile['google_url'] ); ?>" />
									<p class="description"><?php echo esc_html__( 'Use a standard /edit link or a direct /export?format=csv URL. The sheet must be readable by the server without a Google login screen.', 'zuov-tpgs' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="zuov_tpgs_table_id_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'TablePress ID', 'zuov-tpgs' ); ?></label></th>
								<td><input class="regular-text" type="text" id="zuov_tpgs_table_id_<?php echo esc_attr( (string) $index ); ?>" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][table_id]" value="<?php echo esc_attr( $profile['table_id'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="zuov_tpgs_page_url_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Cache purge URL', 'zuov-tpgs' ); ?></label></th>
								<td>
									<input class="large-text code" type="url" id="zuov_tpgs_page_url_<?php echo esc_attr( (string) $index ); ?>" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][page_url]" value="<?php echo esc_attr( $profile['page_url'] ); ?>" />
									<p class="description"><?php echo esc_html__( 'Optional. If W3 Total Cache is active, this page URL is flushed after a successful update.', 'zuov-tpgs' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Data processing', 'zuov-tpgs' ); ?></th>
								<td>
									<label><input type="checkbox" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][remove_empty_rows]" value="1" <?php checked( $profile['remove_empty_rows'] ); ?> /> <?php echo esc_html__( 'Remove empty trailing rows', 'zuov-tpgs' ); ?></label><br />
									<label><input type="checkbox" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][renumber_first_col]" value="1" <?php checked( $profile['renumber_first_col'] ); ?> /> <?php echo esc_html__( 'Renumber the first column on the website', 'zuov-tpgs' ); ?></label><br />
									<label for="zuov_tpgs_sort_mode_<?php echo esc_attr( (string) $index ); ?>"><?php echo esc_html__( 'Sort rows by the second column:', 'zuov-tpgs' ); ?></label>
									<select id="zuov_tpgs_sort_mode_<?php echo esc_attr( (string) $index ); ?>" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][sort_mode]">
										<?php foreach ( $sort_modes as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $profile['sort_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Direct CSV URL', 'zuov-tpgs' ); ?></th>
								<td><code><?php echo esc_html( $csv_url ? $csv_url : __( 'URL not recognized.', 'zuov-tpgs' ) ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Profile webhook URL', 'zuov-tpgs' ); ?></th>
								<td>
									<input class="large-text code" type="text" readonly value="<?php echo esc_attr( $profile_webhook ); ?>" onclick="this.select();" />
									<p class="description"><?php echo esc_html__( 'Use this URL in the Google Apps Script menu for this specific Google Sheet.', 'zuov-tpgs' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Last profile status', 'zuov-tpgs' ); ?></th>
								<td>
									<?php echo esc_html( $profile['last_status'] ? $profile['last_status'] : '-' ); ?> |
									<?php echo esc_html( self::format_datetime( $profile['last_success'] ) ); ?> |
									<?php echo esc_html( $profile['last_message'] ? $profile['last_message'] : '-' ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Remove', 'zuov-tpgs' ); ?></th>
								<td><label><input type="checkbox" name="zuov_tpgs_profiles[<?php echo esc_attr( (string) $index ); ?>][remove]" value="1" /> <?php echo esc_html__( 'Remove this profile on save', 'zuov-tpgs' ); ?></label></td>
							</tr>
						</table>

					</div>
				<?php endforeach; ?>

				<p>
					<?php submit_button( __( 'Save settings', 'zuov-tpgs' ), 'primary', 'submit', false ); ?>
					<button type="submit" class="button" name="zuov_tpgs_action" value="add_profile"><?php echo esc_html__( 'Save and add another profile', 'zuov-tpgs' ); ?></button>
				</p>

				<h2><?php echo esc_html__( 'Automation', 'zuov-tpgs' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zuov_tpgs_interval"><?php echo esc_html__( 'Scheduled check', 'zuov-tpgs' ); ?></label></th>
						<td>
							<select id="zuov_tpgs_interval" name="zuov_tpgs_interval">
								<?php foreach ( $intervals as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['interval'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Cron is a fallback check. For immediate updates, use the webhook or the Google Sheets custom menu.', 'zuov-tpgs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Webhook URL for all profiles', 'zuov-tpgs' ); ?></th>
						<td><input class="large-text code" type="text" readonly value="<?php echo esc_attr( $webhook_all ); ?>" onclick="this.select();" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'REST webhook fallback', 'zuov-tpgs' ); ?></th>
						<td><input class="large-text code" type="text" readonly value="<?php echo esc_attr( $rest_webhook_all ); ?>" onclick="this.select();" /></td>
					</tr>
				</table>
			</form>

			<h2><?php echo esc_html__( 'Manual profile sync', 'zuov-tpgs' ); ?></h2>
			<?php foreach ( $options['profiles'] as $profile ) : ?>
				<form method="post" action="" style="display: inline-block; margin: 0 8px 8px 0;">
					<?php wp_nonce_field( 'zuov_tpgs_sync' ); ?>
					<input type="hidden" name="zuov_tpgs_action" value="sync" />
					<input type="hidden" name="zuov_tpgs_profile_id" value="<?php echo esc_attr( $profile['id'] ); ?>" />
					<?php
					submit_button(
						sprintf(
							/* translators: %s: sync profile name */
							__( 'Sync %s', 'zuov-tpgs' ),
							$profile['name'] ? $profile['name'] : $profile['id']
						),
						'secondary',
						'submit',
						false,
						$sync_button_attributes
					);
					?>
				</form>
			<?php endforeach; ?>

			<h2><?php echo esc_html__( 'Manual sync', 'zuov-tpgs' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'zuov_tpgs_sync' ); ?>
				<input type="hidden" name="zuov_tpgs_action" value="sync" />
				<?php submit_button( __( 'Sync all profiles now', 'zuov-tpgs' ), 'primary', 'submit', false, $sync_button_attributes ); ?>
			</form>

			<h2><?php echo esc_html__( 'Webhook secret', 'zuov-tpgs' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'zuov_tpgs_secret' ); ?>
				<input type="hidden" name="zuov_tpgs_action" value="secret" />
				<?php submit_button( __( 'Change webhook secret', 'zuov-tpgs' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php echo esc_html__( 'Status', 'zuov-tpgs' ); ?></h2>
			<table class="widefat striped" style="max-width: 920px;">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'Last run', 'zuov-tpgs' ); ?></th><td><?php echo esc_html( self::format_datetime( $options['last_run'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Last success', 'zuov-tpgs' ); ?></th><td><?php echo esc_html( self::format_datetime( $options['last_success'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Status', 'zuov-tpgs' ); ?></th><td><?php echo esc_html( $options['last_status'] ? $options['last_status'] : '-' ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Message', 'zuov-tpgs' ); ?></th><td><?php echo esc_html( $options['last_message'] ? $options['last_message'] : '-' ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Rows', 'zuov-tpgs' ); ?></th><td><?php echo esc_html( (string) absint( $options['last_rows'] ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Next scheduled check', 'zuov-tpgs' ); ?></th><td><?php echo esc_html( $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : __( 'Not scheduled', 'zuov-tpgs' ) ); ?></td></tr>
				</tbody>
			</table>

			<h2 id="zuov-tpgs-google-guide"><?php echo esc_html__( 'Google Sheets setup guide', 'zuov-tpgs' ); ?></h2>
			<ol>
				<li><?php echo esc_html__( 'Open the Google Sheet and use Share > General access > Anyone with the link > Viewer. Do not make the public link editable.', 'zuov-tpgs' ); ?></li>
				<li><?php echo esc_html__( 'Give Editor access only to trusted Google accounts that should maintain the data.', 'zuov-tpgs' ); ?></li>
				<li><?php echo esc_html__( 'Paste the Google Sheet /edit URL into the matching sync profile, save settings, and run a manual sync.', 'zuov-tpgs' ); ?></li>
				<li><?php echo esc_html__( 'For a manual sync button inside Google Sheets, open Extensions > Apps Script and use the sample script from README.md or docs/google-sheets-setup.md.', 'zuov-tpgs' ); ?></li>
				<li><?php echo esc_html__( 'Use the profile-specific webhook URL when one Google Sheet should update only its own TablePress table.', 'zuov-tpgs' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Sanitizes admin settings.
	 */
	private static function sanitize_options_from_post( $options ) {
		$options['interval'] = self::sanitize_interval( isset( $_POST['zuov_tpgs_interval'] ) ? wp_unslash( $_POST['zuov_tpgs_interval'] ) : 'manual' );
		$options['profiles'] = array();

		$profiles = isset( $_POST['zuov_tpgs_profiles'] ) && is_array( $_POST['zuov_tpgs_profiles'] ) ? wp_unslash( $_POST['zuov_tpgs_profiles'] ) : array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) || ! empty( $profile['remove'] ) ) {
				continue;
			}

			$item = self::default_profile();
			$item['id'] = self::sanitize_profile_id( isset( $profile['id'] ) ? $profile['id'] : '' );
			$item['name'] = sanitize_text_field( isset( $profile['name'] ) ? $profile['name'] : '' );
			$item['enabled'] = isset( $profile['enabled'] ) ? 1 : 0;
			$item['google_url'] = esc_url_raw( trim( isset( $profile['google_url'] ) ? (string) $profile['google_url'] : '' ) );
			$item['table_id'] = preg_replace( '/[^A-Za-z0-9_-]/', '', isset( $profile['table_id'] ) ? (string) $profile['table_id'] : '' );
			$item['page_url'] = esc_url_raw( trim( isset( $profile['page_url'] ) ? (string) $profile['page_url'] : '' ) );
			$item['remove_empty_rows'] = isset( $profile['remove_empty_rows'] ) ? 1 : 0;
			$item['renumber_first_col'] = isset( $profile['renumber_first_col'] ) ? 1 : 0;
			$item['sort_mode'] = isset( $profile['sort_mode'] ) && array_key_exists( $profile['sort_mode'], self::sort_mode_options() ) ? $profile['sort_mode'] : 'none';

			$old_profile = self::find_profile( $options['profiles'], $item['id'] );
			if ( $old_profile ) {
				foreach ( array( 'last_hash', 'last_success', 'last_status', 'last_message', 'last_rows' ) as $status_key ) {
					$item[ $status_key ] = isset( $old_profile[ $status_key ] ) ? $old_profile[ $status_key ] : $item[ $status_key ];
				}
			}

			$options['profiles'][] = $item;
		}

		if ( empty( $options['profiles'] ) ) {
			$options['profiles'][] = self::default_profile();
		}

		return $options;
	}

	/**
	 * Finds a profile by ID.
	 */
	private static function find_profile( $profiles, $profile_id ) {
		foreach ( (array) $profiles as $profile ) {
			if ( is_array( $profile ) && isset( $profile['id'] ) && $profile['id'] === $profile_id ) {
				return $profile;
			}
		}

		return null;
	}

	/**
	 * Sanitizes cron interval.
	 */
	private static function sanitize_interval( $interval ) {
		$interval = sanitize_key( is_scalar( $interval ) ? (string) $interval : 'manual' );
		return array_key_exists( $interval, self::interval_options() ) ? $interval : 'manual';
	}

	/**
	 * Interval labels for admin.
	 */
	private static function interval_options() {
		return array(
			'manual'          => __( 'Manual / webhook only', 'zuov-tpgs' ),
			'zuov_tpgs_5min'  => __( 'Every 5 minutes', 'zuov-tpgs' ),
			'zuov_tpgs_15min' => __( 'Every 15 minutes', 'zuov-tpgs' ),
			'zuov_tpgs_30min' => __( 'Every 30 minutes', 'zuov-tpgs' ),
			'hourly'          => __( 'Hourly', 'zuov-tpgs' ),
			'twicedaily'      => __( 'Twice daily', 'zuov-tpgs' ),
			'daily'           => __( 'Daily', 'zuov-tpgs' ),
		);
	}

	/**
	 * Sort mode labels.
	 */
	private static function sort_mode_options() {
		return array(
			'none' => __( 'Do not sort', 'zuov-tpgs' ),
			'en'   => __( 'English / Latin A-Z', 'zuov-tpgs' ),
			'sr'   => __( 'Serbian Cyrillic azbuka', 'zuov-tpgs' ),
		);
	}

	/**
	 * Returns a front-end query webhook URL.
	 */
	private static function query_webhook_url( $secret, $profile_id = '' ) {
		$args = array(
			'zuov_tpgs_sync' => '1',
			'secret'         => rawurlencode( $secret ),
		);

		if ( '' !== $profile_id ) {
			$args['profile'] = $profile_id;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Reschedules cron based on settings.
	 */
	private static function reschedule_cron( $options ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( 'manual' === $options['interval'] || empty( self::enabled_profiles( $options ) ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $options['interval'], self::CRON_HOOK );
		}
	}

	/**
	 * Returns enabled profiles.
	 */
	private static function enabled_profiles( $options ) {
		return array_values(
			array_filter(
				$options['profiles'],
				static function ( $profile ) {
					return ! empty( $profile['enabled'] ) && ! empty( $profile['google_url'] ) && ! empty( $profile['table_id'] );
				}
			)
		);
	}

	/**
	 * Runs scheduled sync.
	 */
	public static function cron_sync() {
		self::sync( false, 'cron' );
	}

	/**
	 * Registers REST webhook.
	 */
	public static function register_rest_route() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'rest_sync' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles REST webhook sync.
	 */
	public static function rest_sync( WP_REST_Request $request ) {
		$options = self::get_options();
		$secret  = (string) $request->get_param( 'secret' );
		if ( '' === $secret ) {
			$secret = (string) $request->get_header( 'x-zuov-sync-secret' );
		}

		if ( '' === $options['secret'] || ! hash_equals( (string) $options['secret'], $secret ) ) {
			return new WP_Error( 'zuov_tpgs_forbidden', __( 'Invalid webhook secret.', 'zuov-tpgs' ), array( 'status' => 403 ) );
		}

		$force = (bool) $request->get_param( 'force' );
		$profile_id = self::sanitize_profile_id( (string) $request->get_param( 'profile' ) );
		$result = self::sync( $force, 'webhook', $profile_id );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handles the simple query-string webhook, useful when REST API access is restricted.
	 */
	public static function maybe_handle_query_webhook() {
		if ( empty( $_GET['zuov_tpgs_sync'] ) ) {
			return;
		}

		$options = self::get_options();
		$secret = isset( $_GET['secret'] ) ? sanitize_text_field( wp_unslash( $_GET['secret'] ) ) : '';

		nocache_headers();

		if ( '' === $options['secret'] || ! hash_equals( (string) $options['secret'], (string) $secret ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid webhook secret.', 'zuov-tpgs' ),
				),
				403
			);
		}

		$force = ! empty( $_GET['force'] );
		$profile_id = isset( $_GET['profile'] ) ? self::sanitize_profile_id( wp_unslash( $_GET['profile'] ) ) : '';
		$result = self::sync( $force, 'webhook-query', $profile_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Main sync routine.
	 */
	public static function sync( $force = false, $source = 'manual', $profile_id = '' ) {
		$options = self::get_options();

		if ( get_transient( self::LOCK_NAME ) ) {
			return new WP_Error( 'zuov_tpgs_locked', __( 'Synchronization is already in progress.', 'zuov-tpgs' ) );
		}
		set_transient( self::LOCK_NAME, 1, 5 * MINUTE_IN_SECONDS );

		$options['last_run'] = current_time( 'mysql' );
		$options['last_source'] = $source;
		update_option( self::OPTION_NAME, $options, false );

		try {
			$result = self::sync_unlocked( $options, $force, $source, $profile_id );
		} catch ( Exception $e ) {
			$options = self::get_options();
			$result = self::record_global_error( $options, $e->getMessage() );
		}

		delete_transient( self::LOCK_NAME );

		return $result;
	}

	/**
	 * Sync implementation without lock handling.
	 */
	private static function sync_unlocked( $options, $force, $source, $profile_id = '' ) {
		if ( ! self::tablepress_ready() ) {
			return self::record_global_error( $options, __( 'TablePress is not active or not loaded.', 'zuov-tpgs' ) );
		}

		$profiles = self::profiles_for_sync( $options, $profile_id );
		if ( is_wp_error( $profiles ) ) {
			return self::record_global_error( $options, $profiles->get_error_message() );
		}

		$results = array();
		$successes = 0;
		$errors = 0;
		$skipped = 0;
		$total_rows = 0;

		foreach ( $profiles as $profile ) {
			$result = self::sync_profile( $profile, $options, $force );
			$options = self::get_options();
			$results[] = $result;

			if ( is_wp_error( $result ) ) {
				$errors++;
				continue;
			}

			if ( 'skipped' === $result['status'] ) {
				$skipped++;
			} else {
				$successes++;
				$total_rows += (int) $result['rows'];
			}
		}

		$options = self::get_options();
		$options['last_source'] = $source;
		$options['last_rows'] = $total_rows;

		if ( $errors > 0 ) {
			$options['last_status'] = 'error';
			$options['last_message'] = sprintf(
				/* translators: 1: error count, 2: success count, 3: skipped count */
				__( 'Synchronization finished with %1$d error(s), %2$d update(s), and %3$d skipped profile(s).', 'zuov-tpgs' ),
				$errors,
				$successes,
				$skipped
			);
		} elseif ( $successes > 0 ) {
			$options['last_status'] = 'success';
			$options['last_success'] = current_time( 'mysql' );
			$options['last_message'] = sprintf(
				/* translators: 1: success count, 2: skipped count */
				__( 'Synchronization updated %1$d profile(s). Skipped: %2$d.', 'zuov-tpgs' ),
				$successes,
				$skipped
			);
		} else {
			$options['last_status'] = 'skipped';
			$options['last_message'] = __( 'No profile had changes to import.', 'zuov-tpgs' );
		}

		update_option( self::OPTION_NAME, $options, false );

		return array(
			'status'  => $options['last_status'],
			'message' => $options['last_message'],
			'rows'    => $total_rows,
			'results' => self::format_results_for_response( $results ),
		);
	}

	/**
	 * Selects profiles for sync.
	 */
	private static function profiles_for_sync( $options, $profile_id = '' ) {
		if ( '' !== $profile_id ) {
			$profile = self::find_profile( $options['profiles'], $profile_id );
			if ( ! $profile ) {
				return new WP_Error( 'zuov_tpgs_profile_missing', __( 'The selected sync profile does not exist.', 'zuov-tpgs' ) );
			}

			if ( empty( $profile['enabled'] ) ) {
				return new WP_Error( 'zuov_tpgs_profile_disabled', __( 'The selected sync profile is disabled.', 'zuov-tpgs' ) );
			}

			return array( $profile );
		}

		$profiles = self::enabled_profiles( $options );
		if ( empty( $profiles ) ) {
			return new WP_Error( 'zuov_tpgs_no_profiles', __( 'No enabled sync profiles have both a Google Sheet URL and a TablePress ID.', 'zuov-tpgs' ) );
		}

		return $profiles;
	}

	/**
	 * Formats sync results for JSON responses.
	 */
	private static function format_results_for_response( $results ) {
		$formatted = array();
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				$formatted[] = array(
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);
			} else {
				$formatted[] = $result;
			}
		}

		return $formatted;
	}

	/**
	 * Synchronizes one profile.
	 */
	private static function sync_profile( $profile, $options, $force ) {
		if ( empty( $profile['table_id'] ) ) {
			return self::record_profile_error( $options, $profile['id'], __( 'Missing TablePress ID.', 'zuov-tpgs' ) );
		}

		$csv_url = self::build_csv_url( $profile['google_url'] );
		if ( ! $csv_url ) {
			return self::record_profile_error( $options, $profile['id'], __( 'Google Sheet URL was not recognized.', 'zuov-tpgs' ) );
		}

		$response = wp_remote_get(
			$csv_url,
			array(
				'timeout'     => 60,
				'redirection' => 5,
				'user-agent'  => 'ZUOV TablePress Google Sheets Sync/2.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::record_profile_error(
				$options,
				$profile['id'],
				sprintf(
					/* translators: %s: WordPress HTTP error message */
					__( 'Google Sheet could not be downloaded: %s', 'zuov-tpgs' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );

		if ( 200 !== $code ) {
			return self::record_profile_error(
				$options,
				$profile['id'],
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Google Sheet returned HTTP status %d.', 'zuov-tpgs' ),
					$code
				)
			);
		}
		if ( '' === trim( $body ) ) {
			return self::record_profile_error( $options, $profile['id'], __( 'Google Sheet CSV export is empty.', 'zuov-tpgs' ) );
		}
		if ( preg_match( '/<\s*(html|!doctype)/i', substr( ltrim( $body ), 0, 100 ) ) ) {
			return self::record_profile_error( $options, $profile['id'], __( 'Google returned HTML instead of CSV. Check whether the sheet is readable without logging in.', 'zuov-tpgs' ) );
		}

		$hash_input = $body . '|' . (int) $profile['remove_empty_rows'] . '|' . (int) $profile['renumber_first_col'] . '|' . $profile['sort_mode'];
		$new_hash = hash( 'sha256', $hash_input );
		if ( ! $force && $new_hash === $profile['last_hash'] ) {
			self::record_profile_status( $options, $profile['id'], 'skipped', __( 'No changes in the Google Sheet.', 'zuov-tpgs' ), (int) $profile['last_rows'], false, $new_hash );
			return array(
				'profile' => $profile['id'],
				'status'  => 'skipped',
				'message' => __( 'No changes in the Google Sheet.', 'zuov-tpgs' ),
				'rows'    => (int) $profile['last_rows'],
			);
		}

		$data = self::parse_csv_with_tablepress( $body );
		if ( is_wp_error( $data ) ) {
			return self::record_profile_error( $options, $profile['id'], $data->get_error_message() );
		}

		$data = self::prepare_data( $data, $profile );
		if ( empty( $data ) || empty( $data[0] ) ) {
			return self::record_profile_error( $options, $profile['id'], __( 'CSV does not contain usable table data.', 'zuov-tpgs' ) );
		}

		$save = self::save_tablepress_data( $profile['table_id'], $data );
		if ( is_wp_error( $save ) ) {
			return self::record_profile_error( $options, $profile['id'], $save->get_error_message() );
		}

		self::flush_target_cache( $profile['page_url'] );

		$row_count = max( 0, count( $data ) - 1 );
		$message = sprintf(
			/* translators: 1: TablePress table ID, 2: row count */
			__( 'TablePress table %1$s was updated. Data rows: %2$d.', 'zuov-tpgs' ),
			$profile['table_id'],
			$row_count
		);
		self::record_profile_status( $options, $profile['id'], 'success', $message, $row_count, true, $new_hash );

		return array(
			'profile' => $profile['id'],
			'status'  => 'success',
			'message' => $message,
			'rows'    => $row_count,
		);
	}

	/**
	 * Records a global error.
	 */
	private static function record_global_error( $options, $message ) {
		$options['last_status'] = 'error';
		$options['last_message'] = $message;
		update_option( self::OPTION_NAME, $options, false );

		return new WP_Error( 'zuov_tpgs_sync_error', $message );
	}

	/**
	 * Records a profile error.
	 */
	private static function record_profile_error( $options, $profile_id, $message ) {
		self::record_profile_status( $options, $profile_id, 'error', $message, 0, false, '' );

		return new WP_Error( 'zuov_tpgs_profile_error', $message );
	}

	/**
	 * Records profile status.
	 */
	private static function record_profile_status( $options, $profile_id, $status, $message, $rows, $success, $hash ) {
		$options = self::get_options();
		foreach ( $options['profiles'] as &$profile ) {
			if ( $profile['id'] !== $profile_id ) {
				continue;
			}

			$profile['last_status'] = $status;
			$profile['last_message'] = $message;
			$profile['last_rows'] = (int) $rows;
			if ( '' !== $hash ) {
				$profile['last_hash'] = $hash;
			}
			if ( $success ) {
				$profile['last_success'] = current_time( 'mysql' );
			}
			break;
		}
		unset( $profile );

		update_option( self::OPTION_NAME, $options, false );
	}

	/**
	 * Checks whether TablePress models are available.
	 */
	private static function tablepress_ready() {
		return class_exists( 'TablePress', false )
			&& isset( TablePress::$model_table )
			&& is_object( TablePress::$model_table );
	}

	/**
	 * Converts common Google Sheet URLs to direct CSV export URLs.
	 */
	private static function build_csv_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		if ( false !== strpos( $url, 'output=csv' ) || false !== strpos( $url, 'format=csv' ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || false === strpos( $parts['host'], 'docs.google.com' ) ) {
			return $url;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ! preg_match( '#/spreadsheets/d/([^/]+)#', $path, $matches ) ) {
			return '';
		}

		$gid = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( isset( $query['gid'] ) ) {
				$gid = preg_replace( '/[^0-9]/', '', (string) $query['gid'] );
			}
		}
		if ( '' === $gid && ! empty( $parts['fragment'] ) && preg_match( '/gid=([0-9]+)/', $parts['fragment'], $gid_matches ) ) {
			$gid = $gid_matches[1];
		}

		$csv = 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $matches[1] ) . '/export?format=csv';
		if ( '' !== $gid ) {
			$csv .= '&gid=' . rawurlencode( $gid );
		}

		return $csv;
	}

	/**
	 * Parses CSV using TablePress' own CSV parser.
	 */
	private static function parse_csv_with_tablepress( $csv ) {
		TablePress::load_file( 'class-import-base.php', 'classes' );
		$importer = TablePress::load_class( 'TablePress_Import_Legacy', 'class-import-legacy.php', 'classes' );
		$table = $importer->import_table( 'csv', $csv );

		if ( false === $table || empty( $table['data'] ) || ! is_array( $table['data'] ) ) {
			return new WP_Error( 'zuov_tpgs_csv_parse_failed', __( 'CSV could not be parsed.', 'zuov-tpgs' ) );
		}

		return $table['data'];
	}

	/**
	 * Applies optional row cleanup, sorting, and numbering.
	 */
	private static function prepare_data( $data, $profile ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$data = array_values( $data );

		if ( ! empty( $profile['remove_empty_rows'] ) && count( $data ) > 1 ) {
			$header = array_shift( $data );
			$data = array_values(
				array_filter(
					$data,
					static function ( $row ) {
						$row = is_array( $row ) ? $row : array();
						$check = array_slice( $row, 1 );
						if ( empty( $check ) ) {
							$check = $row;
						}
						foreach ( $check as $cell ) {
							if ( '' !== trim( (string) $cell ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
			array_unshift( $data, $header );
		}

		if ( 'none' !== $profile['sort_mode'] && count( $data ) > 2 ) {
			$header = array_shift( $data );
			$indexed_rows = array();
			foreach ( $data as $index => $row ) {
				$sort_value = isset( $row[1] ) ? $row[1] : '';
				$indexed_rows[] = array(
					'index' => $index,
					'row'   => $row,
					'key'   => self::sort_key( $sort_value, $profile['sort_mode'] ),
				);
			}
			usort(
				$indexed_rows,
				static function ( $a, $b ) {
					$cmp = strcmp( $a['key'], $b['key'] );
					if ( 0 === $cmp ) {
						return $a['index'] <=> $b['index'];
					}
					return $cmp;
				}
			);
			$data = array( $header );
			foreach ( $indexed_rows as $item ) {
				$data[] = $item['row'];
			}
		}

		if ( ! empty( $profile['renumber_first_col'] ) && count( $data ) > 1 ) {
			for ( $i = 1, $count = count( $data ); $i < $count; $i++ ) {
				if ( ! isset( $data[ $i ] ) || ! is_array( $data[ $i ] ) ) {
					$data[ $i ] = array();
				}
				$data[ $i ][0] = (string) $i;
			}
		}

		return $data;
	}

	/**
	 * Builds a sort key for the selected mode.
	 */
	private static function sort_key( $value, $sort_mode ) {
		if ( 'sr' === $sort_mode ) {
			return self::serbian_sort_key( $value );
		}

		if ( 'en' === $sort_mode ) {
			return self::english_sort_key( $value );
		}

		return (string) $value;
	}

	/**
	 * Creates an English/Latin sort key.
	 */
	private static function english_sort_key( $value ) {
		$value = trim( (string) $value );
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		$value = strtolower( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return $value;
	}

	/**
	 * Returns one UTF-8 character by Unicode codepoint.
	 */
	private static function utf8_codepoint( $hex ) {
		return html_entity_decode( '&#x' . $hex . ';', ENT_NOQUOTES, 'UTF-8' );
	}

	/**
	 * Creates a Serbian sort key in the official azbuka order.
	 */
	private static function serbian_sort_key( $value ) {
		$value = trim( (string) $value );
		$u = array( __CLASS__, 'utf8_codepoint' );

		$value = strtr(
			$value,
			array(
				'D' . call_user_func( $u, '017D' ) => call_user_func( $u, '040F' ),
				'D' . call_user_func( $u, '017E' ) => call_user_func( $u, '040F' ),
				'd' . call_user_func( $u, '017E' ) => call_user_func( $u, '045F' ),
				'DJ' => call_user_func( $u, '0402' ),
				'Dj' => call_user_func( $u, '0402' ),
				'dj' => call_user_func( $u, '0452' ),
				'LJ' => call_user_func( $u, '0409' ),
				'Lj' => call_user_func( $u, '0409' ),
				'lj' => call_user_func( $u, '0459' ),
				'NJ' => call_user_func( $u, '040A' ),
				'Nj' => call_user_func( $u, '040A' ),
				'nj' => call_user_func( $u, '045A' ),
			)
		);

		static $map = null;
		if ( null === $map ) {
			$letters = array(
				'01' => array( '0410', '0430', 'A', 'a' ),
				'02' => array( '0411', '0431', 'B', 'b' ),
				'03' => array( '0412', '0432', 'V', 'v' ),
				'04' => array( '0413', '0433', 'G', 'g' ),
				'05' => array( '0414', '0434', 'D', 'd' ),
				'06' => array( '0402', '0452', '0110', '0111' ),
				'07' => array( '0415', '0435', 'E', 'e' ),
				'08' => array( '0416', '0436', '017D', '017E' ),
				'09' => array( '0417', '0437', 'Z', 'z' ),
				'10' => array( '0418', '0438', 'I', 'i' ),
				'11' => array( '0408', '0458', 'J', 'j' ),
				'12' => array( '041A', '043A', 'K', 'k' ),
				'13' => array( '041B', '043B', 'L', 'l' ),
				'14' => array( '0409', '0459' ),
				'15' => array( '041C', '043C', 'M', 'm' ),
				'16' => array( '041D', '043D', 'N', 'n' ),
				'17' => array( '040A', '045A' ),
				'18' => array( '041E', '043E', 'O', 'o' ),
				'19' => array( '041F', '043F', 'P', 'p' ),
				'20' => array( '0420', '0440', 'R', 'r' ),
				'21' => array( '0421', '0441', 'S', 's' ),
				'22' => array( '0422', '0442', 'T', 't' ),
				'23' => array( '040B', '045B', '0106', '0107' ),
				'24' => array( '0423', '0443', 'U', 'u' ),
				'25' => array( '0424', '0444', 'F', 'f' ),
				'26' => array( '0425', '0445', 'H', 'h' ),
				'27' => array( '0426', '0446', 'C', 'c' ),
				'28' => array( '0427', '0447', '010C', '010D' ),
				'29' => array( '040F', '045F' ),
				'30' => array( '0428', '0448', '0160', '0161' ),
			);

			$map = array();
			foreach ( $letters as $order => $symbols ) {
				foreach ( $symbols as $symbol ) {
					$char = preg_match( '/^[0-9A-F]{4}$/', $symbol ) ? call_user_func( $u, $symbol ) : $symbol;
					$map[ $char ] = $order;
				}
			}
		}

		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			$chars = str_split( $value );
		}

		$key = '';
		foreach ( $chars as $char ) {
			if ( isset( $map[ $char ] ) ) {
				$key .= $map[ $char ] . '.';
			} elseif ( preg_match( '/\s/u', $char ) ) {
				$key .= '00.';
			} else {
				$key .= '99' . $char . '.';
			}
		}

		return $key;
	}

	/**
	 * Saves data into an existing TablePress table while preserving table options.
	 */
	private static function save_tablepress_data( $table_id, $data ) {
		if ( ! TablePress::$model_table->table_exists( $table_id ) ) {
			return new WP_Error( 'zuov_tpgs_table_missing', sprintf( __( 'TablePress table does not exist: %s', 'zuov-tpgs' ), $table_id ) );
		}

		$existing = TablePress::$model_table->load( $table_id, false, true );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$num_rows = count( $data );
		$num_columns = count( $data[0] );

		$new_table = array(
			'id'          => $existing['id'],
			'name'        => $existing['name'],
			'description' => $existing['description'],
			'data'        => $data,
			'options'     => isset( $existing['options'] ) ? $existing['options'] : array(),
			'visibility'  => array(
				'rows'    => array_pad( array_slice( isset( $existing['visibility']['rows'] ) ? $existing['visibility']['rows'] : array(), 0, $num_rows ), $num_rows, 1 ),
				'columns' => array_pad( array_slice( isset( $existing['visibility']['columns'] ) ? $existing['visibility']['columns'] : array(), 0, $num_columns ), $num_columns, 1 ),
			),
		);

		$table = TablePress::$model_table->prepare_table( $existing, $new_table, false );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		return TablePress::$model_table->save( $table );
	}

	/**
	 * Flushes W3 Total Cache for the configured page when possible.
	 */
	private static function flush_target_cache( $page_url ) {
		$page_url = trim( (string) $page_url );
		if ( '' === $page_url ) {
			return;
		}

		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $page_url );
		} elseif ( function_exists( 'w3tc_pgcache_flush_url' ) ) {
			w3tc_pgcache_flush_url( $page_url );
		}

		$alt_url = untrailingslashit( $page_url );
		if ( $alt_url && $alt_url !== $page_url ) {
			if ( function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $alt_url );
			} elseif ( function_exists( 'w3tc_pgcache_flush_url' ) ) {
				w3tc_pgcache_flush_url( $alt_url );
			}
		}

		$post_id = url_to_postid( $page_url );
		if ( $post_id ) {
			clean_post_cache( $post_id );
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $post_id, true );
			} elseif ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
				w3tc_pgcache_flush_post( $post_id, true );
			}
		}
	}

	/**
	 * Formats stored MySQL datetime.
	 */
	private static function format_datetime( $datetime ) {
		if ( empty( $datetime ) ) {
			return '-';
		}
		$timestamp = strtotime( $datetime );
		if ( ! $timestamp ) {
			return $datetime;
		}
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}
}

ZUOV_TPGS::init();

register_activation_hook( __FILE__, array( 'ZUOV_TPGS', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZUOV_TPGS', 'deactivate' ) );
