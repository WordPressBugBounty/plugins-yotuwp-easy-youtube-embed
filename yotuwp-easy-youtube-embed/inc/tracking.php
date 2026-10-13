<?php

/**
 * 
 */
class PB_SDK_Tracking{

    private $data;

    public function __construct(){
        add_action( 'init',                         array( $this, 'schedule_send' ) );
        add_action( 'admin_notices',                array( $this, 'admin_notice'     ) );
        add_action( 'yotuwp_opt_into_tracking',     array( $this, 'check_for_optin'  ) );
		add_action( 'yotuwp_opt_out_of_tracking',   array( $this, 'check_for_optout' ) );
		add_action( 'yotuwp_confirm_tracking_optin', array( $this, 'check_for_optin_confirmation' ) );
    }

    private function prepare_data() {

		$data = array();

		$theme_data = wp_get_theme();
		$theme      = $theme_data->Name . ' ' . $theme_data->Version;

		$data['php_version'] = phpversion();
		$data['version'] = YOTUWP_VERSION;
		$data['wp_version']  = get_bloginfo( 'version' );
		$data['server']      = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ) : '';

		$data['multisite']   = is_multisite();
		$data['url']         = home_url();
		$data['domain']      = preg_replace( '/www\./i', '', sanitize_text_field( $_SERVER['SERVER_NAME'] ) );
		$data['theme']       = $theme;
		$data['email']       = get_bloginfo( 'admin_email' );
		$data['first_name']  = '';
		$data['last_name']   = '';

		if (function_exists('wp_get_current_user')) {
			$userinfo = wp_get_current_user();
			if ( is_object($userinfo) && $userinfo->user_email !='') {
				$data['email']      = $userinfo->user_email;
				$data['first_name'] = $userinfo->first_name;
				$data['last_name']  = $userinfo->last_name;
			}
		}
		
		if( ! function_exists( 'get_plugins' ) ) {
			include ABSPATH . '/wp-admin/includes/plugin.php';
		}

		$plugins        = array_keys( get_plugins() );
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $plugins as $key => $plugin ) {
			if ( in_array( $plugin, $active_plugins ) ) {
				unset( $plugins[ $key ] );
			}
		}

		$data['active_plugins']   = $active_plugins;
		$data['inactive_plugins'] = $plugins;
		$data['locale']           = get_locale();
		$data['last_sync']        = time();

		$this->data = $data;
	}

    public function admin_notice() {

		if ( !current_user_can( 'manage_options' ) ) {
			return;
		}

		// A confirmation email is pending (from either the banner below or the
		// General Settings > API toggle) — show a status reminder instead of the
		// opt-in pitch, regardless of the yotuwp_tracking_notice dismiss flag, since
		// this is new information, not a repeat of the same ask.
		$pending = get_option( 'yotuwp_tracking_pending', false );
		if ( is_array( $pending ) && $pending['expires'] > time() ) {
			echo '<div class="updated"><p>';
			_e( '<strong>YotuWP - YouTube Gallery</strong>: we sent a confirmation email to activate tracking/email opt-in. Please check your inbox (and spam folder) and click the confirm link — nothing is activated until you do.', 'yotuwp-easy-youtube-embed' );
			echo '</p></div>';
			return;
		}

		$hide_notice = get_option( 'yotuwp_tracking_notice', false );

		$date_now     = date( 'Y-m-d' );
		$install_date = get_option( 'yotuwp_install_date', $date_now);

		if ( $hide_notice || ( time() - strtotime( $install_date ) < 86400 ) ) {
			return;
		}

		if (
			stristr( network_site_url( '/' ), 'dev'       ) !== false ||
			stristr( network_site_url( '/' ), 'localhost' ) !== false ||
			stristr( network_site_url( '/' ), ':8888'     ) !== false // This is common with MAMP on OS X
		) {
			update_option( 'yotuwp_tracking_notice', '1' );
		} else {

			$optin_url  = add_query_arg( 'ytwp_action', 'opt_into_tracking' );
			$optout_url = add_query_arg( 'ytwp_action', 'opt_out_of_tracking' );

			echo '<div class="updated"><p>';
			_e( 'Allow <strong>YotuWP - YouTube Gallery</strong> to track plugin usage and send this email address occasional product update/offer emails? Become a contributor by opting in — we will email a confirmation link first and nothing activates until you click it. You can unsubscribe from emails at any time.', 'yotuwp-easy-youtube-embed' );
			echo '&nbsp;<br/><a href="' . esc_url( $optin_url ) . '" class="button button-primary">' . __( 'Sure, I would love to help.', 'yotuwp-easy-youtube-embed' ) . '</a>';
			echo '&nbsp;<a href="' . esc_url( $optout_url ) . '" class="ytwp-skip">' . __( 'No, thanks', 'yotuwp-easy-youtube-embed' ) . '</a>';
			echo '</p></div>';
		}
	}

    public function schedule_send() {
		if ( yotuwp_doing_cron() ) {
			add_action( 'yotuwp_weekly_scheduled_events', array( $this, 'send_checkin' ) );
		}
    }
    
    public function check_for_optin( $data ) {

		if ( !current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->request_optin_confirmation();

		update_option( 'yotuwp_tracking_notice', true );
        wp_redirect( remove_query_arg( 'ytwp_action' ) ); exit;
	}

	public function check_for_optout( $data ) {

		if ( !current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( 'yotuwp_tracking_pending' );
		update_option( 'yotuwp_allow_tracking', false );
		update_option( 'yotuwp_tracking_notice', true );
		wp_redirect( remove_query_arg( 'ytwp_action' ) ); exit;
    }

	private function get_notify_email() {

		$email = get_bloginfo( 'admin_email' );

		if ( function_exists( 'wp_get_current_user' ) ) {
			$userinfo = wp_get_current_user();
			if ( is_object( $userinfo ) && $userinfo->user_email != '' ) {
				$email = $userinfo->user_email;
			}
		}

		return $email;
	}

	// Double opt-in step: generates a single-use, expiring token and emails a
	// confirmation link via the site's own wp_mail() rather than subscribing
	// immediately. This deliberately never touches Mautic/SES — the confirmation
	// email is a native WordPress notification (same trust level as a password-reset
	// email), so it can't affect sender reputation on the marketing-send side, and it
	// proves a human with admin access on this site actually wants this, not just
	// "a checkbox got saved." Called from both the admin-notice banner (check_for_optin)
	// and the General Settings > API "Enable Tracking?" toggle (YotuWP::__call()) —
	// both are equally weak single-click/toggle-and-save paths without this step.
	public function request_optin_confirmation() {

		$token = wp_generate_password( 32, false, false );

		update_option( 'yotuwp_tracking_pending', array(
			'token'   => $token,
			'expires' => time() + 7 * DAY_IN_SECONDS,
		) );

		$confirm_url = add_query_arg( array(
			'ytwp_action' => 'confirm_tracking_optin',
			'ytwp_token'  => $token,
		), admin_url() );

		$to      = $this->get_notify_email();
		$subject = __( '[Action required] Confirm YotuWP email opt-in', 'yotuwp-easy-youtube-embed' );
		$message = sprintf(
			__( "You (or someone with admin access on your WordPress site) just requested to opt YotuWP - YouTube Gallery into anonymous usage tracking and occasional product update/offer emails sent to this address.\n\nNothing is activated yet. If this was you, confirm here:\n%s\n\nThis link expires in 7 days and can only be used once. If you didn't request this, you can safely ignore this email -- nothing will be activated and no further email will be sent.", 'yotuwp-easy-youtube-embed' ),
			$confirm_url
		);

		wp_mail( $to, $subject, $message );
	}

	public function check_for_optin_confirmation( $data ) {

		$pending = get_option( 'yotuwp_tracking_pending', false );
		$token   = isset( $data['ytwp_token'] ) ? sanitize_text_field( $data['ytwp_token'] ) : '';

		$valid = (
			current_user_can( 'manage_options' ) &&
			is_array( $pending ) &&
			!empty( $token ) &&
			hash_equals( $pending['token'], $token ) &&
			$pending['expires'] >= time()
		);

		delete_option( 'yotuwp_tracking_pending' );

		if ( $valid ) {
			update_option( 'yotuwp_allow_tracking', true );
			$this->send_checkin( true );
			update_option( 'yotuwp_tracking_notice', true );
		}

		wp_redirect( remove_query_arg( array( 'ytwp_action', 'ytwp_token' ) ) ); exit;
	}
    
    public function send_checkin( $override = false, $ignore_last_checkin = false ) {

		$home_url = trailingslashit( home_url() );
		
		// Allows us to stop our own site from checking in, and a filter for our additional sites
		if ( $home_url === 'https://www.yotuwp.com/' ) {
			return false;
		}

		if ( !get_option( 'yotuwp_allow_tracking', false ) && !$override ) {
			return false;
		}
        
		// Send a maximum of once per week
        $last_send = get_option( 'yotuwp_tracking_last_send' );
        
		if ( is_numeric( $last_send ) && $last_send > strtotime( '-2 week' ) && !$ignore_last_checkin ) {
			return false;
		}

		$this->prepare_data();

		$response = wp_remote_post( 'https://api.yotuwp.com/tracking/?ytwp_action=checkin&t='.time(), array(
			'method'      => 'POST',
			'timeout'     => 10,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking'    => false,
			'body'        => $this->data,
			'user-agent'  => 'YOTUWP/' . YOTUWP_VERSION . '; ' . get_bloginfo( 'url' )
		) );
		
		update_option( 'yotuwp_tracking_last_send', time() );

		return true;

	}

	public function get()
	{
		$this->prepare_data();
		return $this->data;
	}

}

$ytwp_tracking = new PB_SDK_Tracking();