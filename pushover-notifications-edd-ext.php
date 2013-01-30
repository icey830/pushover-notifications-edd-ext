<?php
/*
Plugin Name: Pushover Notifications for Easy Digital Downloads
Plugin URI: http://wp-push.com
Description: Adds Easy Digital Downloads support to Pushover Notifications for WordPress
Version: 1.2.1
Author: Chris Klosowski
Author URI: http://wp-push.com
Text Domain: ckpn_edd
*/

// Define the plugin path
define( 'CKPN_EDD_PATH', plugin_dir_path( __FILE__ ) );

define( 'CKPN_TEXT_DOMAIN' , 'ckpn-edd' );
// plugin version
define( 'CKPN_EDD_VERSION', '1.2.1' );

// Define the URL to the plugin folder
define( 'CKPN_EDD_FOLDER', dirname( plugin_basename( __FILE__ ) ) );
define( 'CKPN_EDD_URL', plugins_url( 'pushover-notifications-edd-ext', 'pushover-notifications-edd-ext.php' ) );

define( 'EDD_CKPN_SL_STORE_API_URL', 'http://easydigitaldownloads.com' );
define( 'EDD_CKPN_SL_PRODUCT_NAME', 'Pushover Notifications for Easy Digital Downloads' );

if ( !class_exists( 'EDD_SL_Plugin_Updater' ) )
	include CKPN_EDD_PATH . '/includes/EDD_SL_Plugin_Updater.php';

include_once ABSPATH . 'wp-admin/includes/plugin.php';

class CKPushoverNotificationsEDD {
	private static $ckpn_edd_instance;

	private function __construct() {
		if ( !$this->checkCoreVersion() ) {
			add_action( 'admin_notices', array( $this, 'core_out_of_date_nag' ) );
		} else {
			// Unify with the settings
			add_filter( 'ckpn_options_defaults', array( $this, 'add_defaults' ), 1 );
			register_deactivation_hook( 'pushover-notifications-edd-ext/pushover-notifications-edd-ext.php', array( $this, 'on_deactivate' ) );
			// Admin Hooks

			add_action( 'admin_init', array( $this, 'admin_hooks' ) );
			add_action( 'admin_init', array( $this, 'ckpn_edd_activate_license' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'load_cusom_js' ) );
			add_action( 'edd_edit_discount_form_bottom', array( $this, 'discount_edit_form' ), 10, 2 );
			add_action( 'edd_add_discount_form_bottom', array( $this, 'discount_add_form' ) );
			add_action( 'edd_post_update_discount', array( $this, 'save_discount' ), 10, 2 );
			add_action( 'edd_post_insert_discount', array( $this, 'save_discount' ), 10, 2 );

			// Non-Admin Hooks
			add_action( 'init', array( $this, 'frontend_hooks' ) );
			add_action( 'init', array( $this, 'ckpn_edd_check_for_update' ) );
			add_action( 'init', array( $this, 'ckpn_edd_loaddomain' ) );

			// EDD Hooks
			add_action( 'edd_update_payment_status', array( $this, 'send_new_sale_notification' ), 10, 3 );
			add_action( 'edd_update_payment_status', array( $this, 'send_discount_usage' ), 10, 4 );
		}
	}

	public static function getInstance() {
		if ( !self::$ckpn_edd_instance ) {
			self::$ckpn_edd_instance = new CKPushoverNotificationsEDD();
		}

		return self::$ckpn_edd_instance;
	}

	private function checkCoreVersion() {
		// Make sure we have the required version of Pushover Notifications core plugin
		$plugin_folder = get_plugins( '/pushover-notifications' );
		$plugin_file = 'pushover-notifications.php';
		$core_version = $plugin_folder[$plugin_file]['Version'];
		$requires = '1.7.3.1';

		if ( version_compare( $core_version, $requires ) >= 0 ) {
			return true;
		}

		return false;
	}

	private function getOptions() {
		static $options = NULL;

		if ( $options !== NULL )
			return $options;

		$options = ckpn_get_options();

		return $options;
	}

	public function ckpn_edd_loaddomain() {
		load_plugin_textdomain( CKPN_TEXT_DOMAIN, false, '/pushover-notifications-edd-ext/languages/' );
	}

	public function load_cusom_js( $hook ) {
		if ( 'settings_page_pushover-notifications' != $hook )
			return;

		wp_enqueue_script( 'ckpn_edd_custom_js', CKPN_EDD_URL.'/includes/ckpn_edd_scripts.js', 'jquery', CKPN_EDD_VERSION, true );
	}

	public function on_deactivate() {
		$next = wp_next_scheduled( 'ckpn_edd_ext_daily_sales' );
		wp_unschedule_event( $next, 'ckpn_edd_ext_daily_sales' );

		$next = wp_next_scheduled( 'ckpn_edd_discount_alerts' );
		wp_unschedule_event( $next, 'ckpn_edd_discount_alerts' );
	}

	/*
	 * ckpn_edd_admin_hooks
	 *
	 * Hooks used in the admin part of the site.
	 *
	 */
	public function admin_hooks() {
		if ( is_plugin_active( 'pushover-notifications/pushover-notifications.php' ) ) {
			$this->determine_cron_schedule();
			add_action( 'ckpn_notification_checkbox_filter', array( $this, 'add_settings_fields' ), 99 );
		} else {
			add_action( 'admin_notices', array( $this, 'missing_core_nag' ) );
		}
	}

	/*
	 * missing_core_nag
	 *
	 * Warns a user if they don't have the core Pushover Notifications plugin.
	 *
	 *
	 * @return void
	 */
	function missing_core_nag() {
		printf( '<div class="error"> <p> %s </p> </div>', esc_html__( 'To use Pushover Notifications for Easy Digital Downloads you need to also install and activate the free plugin Pushover Notifications for WordPress.', CKPN_TEXT_DOMAIN ) );
	}

	/*
	 * core_out_of_date_nag
	 *
	 * Warns a user if the core Pushover Notifications plugin is not at the minimum required version
	 *
	 *
	 * @return void
	 */
	function core_out_of_date_nag() {
		printf( '<div class="error"> <p> %s </p> </div>', esc_html__( 'Your Pushover Notifications core plugin is out of date. Please update it in order to use Pushover Notifiations for Easy Digital Downloads.', CKPN_TEXT_DOMAIN ) );
	}

	/*
	 * ckpn_edd_frontend_hooks
	 *
	 * Hooks used in the frontend ( non-admin ) part of the site.
	 *
	 */
	public function frontend_hooks() {
		if ( is_plugin_active( 'pushover-notifications/pushover-notifications.php' ) ) {
			$current_options = $this->getOptions();
			if ( $current_options['edd_daily_sales'] ) {
				$this->determine_cron_schedule();
			}
		}
	}

	/*
	 * determine_cron_schedule
	 *
	 * Is used to figure out if our cron is already determined and then adds the hook for sending the daily stats
	 */
	private function determine_cron_schedule() {
		$current_options = $this->getOptions();
		if ( $current_options['edd_daily_sales'] ) {
			if ( !wp_next_scheduled( 'ckpn_edd_ext_daily_sales' ) ) {
				$next_run = strtotime( '23:00' );

				if ( (int)date_i18n( 'G' ) >= 23 )
					$next_run = strtotime( 'next day 23:00' );

				wp_schedule_event( $next_run, 'daily', 'ckpn_edd_ext_daily_sales' );
			}
			add_action( 'ckpn_edd_ext_daily_sales', array( $this, 'execute_daily_sales' ) );
		}
		if ( $current_options['edd_discount_notices'] ) {
			if ( !wp_next_scheduled( 'ckpn_edd_discount_alerts' ) ) {
				$next_run = strtotime( '08:00' );

				if ( (int)date_i18n( 'G' ) >= 8 )
					$next_run = strtotime( 'next day 08:00' );

				wp_schedule_event( $next_run, 'daily', 'ckpn_edd_discount_alerts' );
			}
			add_action( 'ckpn_edd_discount_alerts', array( $this, 'execute_daily_discount_notices' ) );
		}
	}

	/*
	 * ckpn_edd_add_defaults
	 *
	 * Hooks onto the core plugin filter for adding the default settings
	 * which allows a static settings call.
	 *
	 */
	public function add_defaults( $defaults ) {
		$ckpn_edd_defaults = array(
			'edd_ckpn_license_key'  => false,
			'edd_complete_purchase'  => false,
			'new_sales_cashregister' => false,
			'edd_daily_sales'   => false,
			'edd_discount_notices'  => false,
			'edd_discount_usage_25'  => false,
			'edd_discount_usage_50'  => false,
			'edd_discount_usage_75'  => false,
			'edd_discount_usage_100' => false,
			'edd_discount_days_14'  => false,
			'edd_discount_days_7'  => false,
			'edd_discount_days_1'  => false
		);

		return array_merge( $defaults, $ckpn_edd_defaults );
	}

	/*
	 * ckpn_edd_ext_inputs
	 *
	 * Adds input fields on the Pushover Notifications Settings page for additional settings
	 *
	 */
	public function add_settings_fields() {
		$current = $this->getOptions();
?>
		<tr valign="top">
			<th scope="row"><?php _e( 'Easy Digital Downloads Settings', CKPN_TEXT_DOMAIN ); ?></th>
			<td>
				<input type="text" name="ckpn_pushover_notifications_settings[edd_ckpn_license_key]" placeholder="<?php _e( 'Enter EDD Extension License Key', CKPN_TEXT_DOMAIN ); ?>" size="50" value="<?php echo $current['edd_ckpn_license_key']; ?>" /><br />
				<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_complete_purchase]" value="1" <?php if ( $current['edd_complete_purchase'] == '1' ) { ?>checked="checked"<?php } ?> /> <?php _e( 'New Sales', CKPN_TEXT_DOMAIN ); ?><br />
				&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" name="ckpn_pushover_notifications_settings[new_sales_cashregister]" value="1" <?php if ( $current['new_sales_cashregister'] == '1' ) { ?>checked="checked"<?php } ?> /> <?php _e( 'User Cash Register Sound?', CKPN_TEXT_DOMAIN ); ?><br />
				<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_daily_sales]" value="1" <?php if ( $current['edd_daily_sales'] == '1' ) { ?>checked="checked"<?php } ?> /> <span><?php _e( 'Daily Sales Report', CKPN_TEXT_DOMAIN ); ?></span> <sup>&dagger;</sup>&nbsp;&nbsp;<br />
				<input type="checkbox" id="edd_discount_notices" name="ckpn_pushover_notifications_settings[edd_discount_notices]" value="1" <?php if ( $current['edd_discount_notices'] == '1' ) { ?>checked="checked"<?php } ?> /> Enable Discount Notifications
				<div id="discount-code-settings" <?php if ( !$current['edd_discount_notices'] ) { ?>style="display: none"<?php } ?>>
					<strong>Notify me when a discount code usage percentage reaches:</strong><br />
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_usage_25]" value="1" <?php if ( $current['edd_discount_usage_25'] == '1' ) { ?>checked="checked"<?php } ?> /> 25%&nbsp;&nbsp;
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_usage_50]" value="1" <?php if ( $current['edd_discount_usage_50'] == '1' ) { ?>checked="checked"<?php } ?> /> 50%&nbsp;&nbsp;
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_usage_75]" value="1" <?php if ( $current['edd_discount_usage_75'] == '1' ) { ?>checked="checked"<?php } ?> /> 75%&nbsp;&nbsp;
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_usage_100]" value="1" <?php if ( $current['edd_discount_usage_100'] == '1' ) { ?>checked="checked"<?php } ?> /> 100%<br />
					<strong>Notify me when a discount code has X Days left:</strong> <sup>&dagger;</sup><br />
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_days_14]" value="1" <?php if ( $current['edd_discount_days_14'] == '1' ) { ?>checked="checked"<?php } ?> /> <?php _e( '14 Days', CKPN_TEXT_DOMAIN ); ?>&nbsp;&nbsp;
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_days_7]" value="1" <?php if ( $current['edd_discount_days_7'] == '1' ) { ?>checked="checked"<?php } ?> /> <?php _e( '7 Days', CKPN_TEXT_DOMAIN ); ?>&nbsp;&nbsp;
					<input type="checkbox" name="ckpn_pushover_notifications_settings[edd_discount_days_1]" value="1" <?php if ( $current['edd_discount_days_1'] == '1' ) { ?>checked="checked"<?php } ?> /> <?php _e( '1 Day', CKPN_TEXT_DOMAIN ); ?>&nbsp;&nbsp;
				</div>
				<br />
				<sup>&dagger;</sup> <a href="#" onClick="jQuery( '#cron-help' ).toggle(); return false;"><?php _e( 'Not receiving reports?', CKPN_TEXT_DOMAIN ); ?></a><br />
				<div id="cron-help" style="display:none">
					&nbsp;&nbsp;&nbsp;&nbsp;<?php _e( 'This feature uses WP-Cron to run. If your site doesn\'t get much traffic, the scheduled task to send your reports might not execute at the specified time. There are 2 options:', CKPN_TEXT_DOMAIN ); ?><br />
					&nbsp;&nbsp;&nbsp;&nbsp;<?php _e( '1. You may need to use the <a href="http://codecanyon.net/item/improved-cron/176543?ref=cklosowski" target="_blank">Improved Cron</a> plugin to help scheduled tasks run.', CKPN_TEXT_DOMAIN ); ?><br />
					&nbsp;&nbsp;&nbsp;&nbsp;<?php _e( '2. If you have access to create cron jobs and know how, you can use the following cron to execute wp-cron.php every hour.', CKPN_TEXT_DOMAIN ); ?><br />
					&nbsp;&nbsp;&nbsp;&nbsp;<code>0 */1 * * * GET <?php echo home_url(); ?>/wp-cron.php</code>
				</div>
			</td>
		</tr>
		<?php
	}

	/*
	 * send_notification
	 *
	 * Instantiates the Core Plugin and sends the title and message to the core for sending push notifications
	 *
	 */
	private function send_notification( $args ) {
		$ckpn_core = CKPushoverNotifications::getInstance();
		$ckpn_core->ckpn_send_notification( $args );
	}

	/*****************************************************
	 * Business Functions that execute the notifications *
	 *****************************************************/

	/*
	 * execute_daily_sales
	 *
	 * Worker function to send out daily stats. Daily earnings is a build in EDD function
	 * however the edd_get_sales_by_date() function doesn't determine a date, just the month and year.
	 * Created a little bit of code to limit the day as well.
	 *
	 * Instantiates the Pushover Notification Singleton class and then sends the message. All limiting
	 * and encoding is done on the Pushover Notification plugin side.
	 *
	 * We use date_i18n() to make sure that the date, month, and year provided match that of the local user.
	 */
	public function execute_daily_sales() {
		$day = date_i18n( 'j' ); $month = date_i18n( 'm' ); $year = date_i18n( 'Y' );

		$sales = get_posts(
			array(
				'post_type' => 'edd_payment',
				'posts_per_page' => -1,
				'day' => $day,
				'year' => $year,
				'monthnum' => $month,
				'meta_key' => '_edd_payment_mode',
				'meta_value' => 'live'
			)
		);
		$sales_count = 0;
		if ( $sales ) {
			$sales_count = count( $sales );
		}

		$title = sprintf( __( '%s: Earnings Report %s', CKPN_TEXT_DOMAIN ), get_bloginfo( 'name' ), date_i18n( get_option( 'date_format' ), strtotime( $month . '/' . $day ) ) );
		$message = sprintf( __( 'Earnings: %s %sSales: %d', CKPN_TEXT_DOMAIN ), edd_currency_filter( edd_format_amount( edd_get_earnings_by_date( $day, $month, $year ) ) ), "\n", $sales_count );

		$args = array( 'title' => $title, 'message' => $message );
		$this->send_notification( $args );
	}

	/*
	 * execute_daily_discount_notices
	 *
	 * Worker function to send out noticies of expiring discount codes
	 *
	 */
	public function execute_daily_discount_notices() {
		$current_options = $this->getOptions();

		if ( !$current_options['edd_discount_days_14'] && !$current_options['edd_discount_days_7'] && !$current_options['edd_discount_days_1'] )
			return;

		if ( !edd_has_active_discounts() )
			return;

		$args = array(
			'post_status' => 'active'
		);

		$discounts = edd_get_discounts( $args );

		$current_date = new DateTime( date( 'Y/m/d' ), new DateTimeZone( get_option( 'timezone_string' ) ) );
		$found_discounts = array( '14' => 0, '7' => 0, '1' => 0 );

		foreach ( $discounts as $discount ) {
			$send_discount_notification = get_post_meta( $discount->ID, '_ckpn_edd_discount_notify', true );
			if ( $send_discount_notification == 'off' )
				continue;

			if ( get_post_meta( $discount->ID, '_edd_discount_expiration', true ) == '' )
				continue;

			$end_date = new DateTime( edd_get_discount_expiration( $discount->ID ), new DateTimeZone( get_option( 'timezone_string' ) ) );
			$interval = $current_date->diff( $end_date );
			$days_left = (int)$interval->format( '%a' );
			switch ( $days_left ) {
			case '14':
				if ( $current_options['edd_discount_days_14'] )
					$found_discounts['14']++;

				break;
			case '7':
				if ( $current_options['edd_discount_days_7'] )
					$found_discounts['7']++;

				break;
			case'1':
				if ( $current_options['edd_discount_days_1'] )
					$found_discounts['1']++;

				break;
			}
		}

		$total_found = array_sum( $found_discounts );

		if ( $total_found == 0 )
			return;

		$title = sprintf( __( '%s: Discount Codes', CKPN_TEXT_DOMAIN ), get_bloginfo( 'name' ) );

		if ( $total_found == 1 ) {
			asort( $found_discounts );
			$found_discounts = array_reverse( $found_discounts, true );

			$days = array_shift( array_keys( $found_discounts ) );
			$days_string = sprintf( _n( 'tomorrow', 'in %d days', $days, CKPN_TEXT_DOMAIN ), $days );
			$message = sprintf( __( 'You have a discount code expiring %s', CKPN_TEXT_DOMAIN ), $days_string );
		} elseif ( $total_found > 1 ) {
			$message = sprintf( __( 'You have %d codes expiring soon', CKPN_TEXT_DOMAIN ), array_sum( $found_discounts ) );
			if ( $found_discounts['1'] > 0 ) {
				$expire = _n( 'expires', 'expire', $found_discounts['1'], CKPN_TEXT_DOMAIN );
				$message .= sprintf( __( ', %d of which %s tomorrow', CKPN_TEXT_DOMAIN ), $found_discounts['1'], $expire );
			}
		}

		$args = array( 'title' => $title, 'message' => $message );
		$this->send_notification( $args );
	}

	/*
	 * send_new_sale_notification
	 *
	 * Hooks onto the EDD on edd_update_payment_status. When the $new_status = complete
	 * a pushover notificaiton is sent stating such.
	 *
	 * @param payment_id - int - the payment ID being altered
	 * @param new_status - string - the new payment status
	 * @param old_status - string - the old payment status
	 *
	 * @return void
	 */
	public function send_new_sale_notification( $payment_id, $new_status, $old_status ) {
		$current_options = $this->getOptions();
		if ( is_plugin_active( 'pushover-notifications/pushover-notifications.php' ) && $current_options['edd_complete_purchase'] ) {
			if ( $new_status == 'complete' || $new_status == 'publish' ) {
				$payment    = edd_get_payment_meta( $payment_id );
				$cart_details = unserialize( $payment['cart_details'] );
				$user_info   = unserialize( $payment['user_info'] );

				$title = sprintf( __( '%s: New Sale!', CKPN_TEXT_DOMAIN ), get_bloginfo( 'name' ) );
				$message = '';
				foreach ( $cart_details as $item ) {
					$message .= $item['name'] . ': ' . edd_currency_filter( edd_format_amount( $item['price'] ) ) . "\n";
				}
				if ( isset( $user_info['discount'] ) && $user_info['discount'] !== 'none' ) {
					$message .= __( 'Discount: ', CKPN_TEXT_DOMAIN ) . $user_info['discount'] . "\n";
				}
				$message .= sprintf( __( 'Total Sale: %s', CKPN_TEXT_DOMAIN ), edd_currency_filter( edd_format_amount( $payment['amount'] ) ) );

				$args = array( 'title' => $title, 'message' => $message );

				// Cha-ching!
				if ( $current_options['new_sales_cashregister'] )
					$args['sound'] = 'cashregister';

				$this->send_notification( $args );
			}
		}
	}

	/*
	 * send_discount_usage
	 *
	 * Hooks onto the EDD on edd_update_payment_status. When the $new_status = complete
	 * a pushover notificaiton is sent stating such.
	 *
	 * @param payment_id - int - the payment ID being altered
	 * @param new_status - string - the new payment status
	 * @param old_status - string - the old payment status
	 *
	 * @return void
	 */
	public function send_discount_usage( $payment_id, $new_status, $old_status ) {
		$current_options = $this->getOptions();

		if ( is_plugin_active( 'pushover-notifications/pushover-notifications.php' ) && $current_options['edd_discount_notices'] ) {
			if ( $new_status == 'complete' || $new_status == 'publish' ) {

				$payment    = edd_get_payment_meta( $payment_id );
				$cart_details   = unserialize( $payment['cart_details'] );
				$user_info    = unserialize( $payment['user_info'] );

				if ( !isset( $user_info['discount'] ) || $user_info['discount'] == 'none' )
					return;

				$discount_id = edd_get_discount_id_by_code( $user_info['discount'] );

				$send_discount_notification = get_post_meta( $discount_id, '_ckpn_edd_discount_notify', true );
				if ( $send_discount_notification == 'off' )
					return false;

				$max_uses = edd_get_discount_max_uses( $discount_id );

				if ( $max_uses == 0 )
					return;

				$selected_pct = NULL;

				// Find the current usage
				$current_uses = edd_get_discount_uses( $discount_id );
				$current_pct = ( $current_uses / $max_uses ) * 100;

				// What will our new usage count be
				$new_uses = $current_uses + 1;
				$new_pct = ( $new_uses / $max_uses ) * 100;

				// If we're at the limit let us know
				if ( $new_uses == $max_uses ) {
					$selected_pct = '100';

					$title = sprintf( __( '%s: Discount Code Depleated', CKPN_TEXT_DOMAIN ), get_bloginfo( 'name' ) );
					$message = sprintf( __( 'The discount code %s has reached it\'s maximum usage.', CKPN_TEXT_DOMAIN ), $user_info['discount'] );

				} else { // We're not 100% used, see if we crossed a threshold

					if ( $current_pct < 25 && $new_pct >= 25 ) {
						$selected_pct = '25';
					} elseif ( $current_pct < 50 && $new_pct >= 50 ) {
						$selected_pct = '50';
					} elseif ( $current_pct < 75 && $new_pct >= 75 ) {
						$selected_pct = '75';
					}

					if ( !is_null( $selected_pct ) ) {
						$title = sprintf( __( '%s: Discount Code %s', CKPN_TEXT_DOMAIN ), get_bloginfo( 'name' ), $user_info['discount'] );
						$message = sprintf( __( '%s of codes have been redeemed. %d codes remain.', CKPN_TEXT_DOMAIN ), $selected_pct . '%', ( $max_uses - $new_uses ) );
					}
				}

				$option_key = 'edd_discount_usage_' . $selected_pct;

				if ( $selected_pct != NULL && $current_options[$option_key] ) {
					$args = array( 'title' => $title, 'message' => $message );
					$this->send_notification( $args );
				}
			}
		}
	}

	/*
	 * discount_edit_form
	 *
	 * Adds the selection to the bottom of the edit discount form
	 *
	 * @param discount_id - int - the ID of the discount
	 * @param discount - array - the discount details
	 *
	 * @return void
	 */

	public function discount_edit_form( $discount_id, $discount ) {
		$current_status = get_post_meta( $discount_id, '_ckpn_edd_discount_notify', true );
?>
		<table class="form-table">
			<tr class="form-field">
				<th scope="row" valign="top">
					<label for="ckpn-edd-discount-notifications"><?php _e( 'Usage Notifications', CKPN_TEXT_DOMAIN ); ?></label>
				</th>
				<td>
					<select name="discount-notifications" id="ckpn-edd-discount-notifications">
						<option value="on" <?php selected( $current_status, 'on' ); ?>><?php _e( 'On', CKPN_TEXT_DOMAIN ); ?></option>
						<option value="off" <?php selected( $current_status, 'off' ); ?>><?php _e( 'Off', CKPN_TEXT_DOMAIN ); ?></option>
					</select>
					<p class="description"><?php _e( 'Be notified of this discount code\'s usage &amp; expiration', CKPN_TEXT_DOMAIN ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/*
	 * discount_add_form
	 *
	 * Adds the selection to the bottom of the add discount form
	 *
	 * @param discount_id - int - the ID of the discount
	 * @param discount - array - the discount details
	 *
	 * @return void
	 */
	public function discount_add_form() {
?>
		<table class="form-table">
			<tr class="form-field">
				<th scope="row" valign="top">
					<label for="ckpn-edd-discount-notifications"><?php _e( 'Usage Notifications', CKPN_TEXT_DOMAIN ); ?></label>
				</th>
				<td>
					<select name="discount-notifications" id="ckpn-edd-discount-notifications">
						<option value="on"><?php _e( 'On', CKPN_TEXT_DOMAIN ); ?></option>
						<option value="off"><?php _e( 'Off', CKPN_TEXT_DOMAIN ); ?></option>
					</select>
					<p class="description"><?php _e( 'Be notified of this discount code\'s usage &amp; expiration', CKPN_TEXT_DOMAIN ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/*
	 * save_discount
	 *
	 * Processes the saving of a new discount or an edited discount
	 *
	 * @param discount_details - array - The discount post data
	 * @param discount_id - in - the ID of the discount
	 *
	 * @return void
	 */
	public function save_discount( $discount_details, $discount_id ) {
		$value = ( $_POST['discount-notifications'] == 'on' ) ? 'on' : 'off';
		update_post_meta( $discount_id, '_ckpn_edd_discount_notify', $value );
	}

	/*************************
	 * EDD Extension Methods *
	 *************************/

	/*
	 *ckpn_edd_check_for_update
	 *
	 * Check the EDD Auto Updater API if we have a new version
	 *
	 * @return void
	 */
	public function ckpn_edd_check_for_update() {
		$current_settings = $this->getOptions();
		$edd_sl_license_key = isset( $current_settings['edd_ckpn_license_key'] ) ? trim( $current_settings['edd_ckpn_license_key'] ) : '';

		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater( EDD_CKPN_SL_STORE_API_URL, __FILE__, array(
				'version'  => CKPN_EDD_VERSION,    // current version number
				'license'  => $edd_sl_license_key,   // license key ( used get_option above to retrieve from DB )
				'item_name'  => EDD_CKPN_SL_PRODUCT_NAME,  // name of this plugin
				'author'  => 'Chris Klosowski'    // author of this plugin
			)
		);
	}

	/*
	 * ckpn_edd_activate_license
	 *
	 * Activate the extension with Easy Ditital Downloads Licenseing API
	 */
	public function ckpn_edd_activate_license() {
		if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] !== 'pushover-notifications' )
			return;

		if ( isset( $_REQUEST['settings-updated'] ) && $_REQUEST['settings-updated'] !== 'true' )
			return;

		$current_options = $this->getOptions();
		if ( isset( $current_options['ckpn_edd_active'] ) && $current_options['ckpn_edd_active'] == 'valid' )
			return;

		$license = sanitize_text_field( $current_options['edd_ckpn_license_key'] );

		// data to send in our API request
		$api_params = array(
			'edd_action' => 'activate_license',
			'license'  => $license,
			'item_name'  => urlencode( EDD_CKPN_SL_PRODUCT_NAME ) // the name of our product in EDD
		);

		// Call the custom API.
		$response = wp_remote_get( add_query_arg( $api_params, EDD_CKPN_SL_STORE_API_URL ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		$current_options['ckpn_edd_active'] = $license_data->license;
		update_option( 'ckpn_pushover_notifications_settings', $current_options );
	}
}

$ckpn_edd_loaded = CKPushoverNotificationsEDD::getInstance();