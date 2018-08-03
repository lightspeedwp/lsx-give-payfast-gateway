<?php
/**
 * Plugin Name: Give - PayFast Gateway
 * Plugin URI: https://www.lsdev.biz/product/givewp-payfast-integration-addon/
 * Description: LightSpeed’s PayFast Gateway for GiveWP is the only way to use the powerful Give plugin for WordPress to accept Rands in South Africa. Give is a flexible, robust, and simple WordPress plugin for accepting donations directly on your website.
 * Author: LightSpeed
 * Version: 1.1.2
 * Author URI: https://www.lsdev.biz/products/
 * License: GPL3+
 * Text Domain: replaceme
 * Domain Path: /languages/

 @package give-payfast
 **/

/**
 * Run when the plugin is active, and generate a unique password for the site instance.
 */
function give_payfast_activate_plugin() {
	$password = get_option( 'give_payfast_instance', false );
	if ( false === $password ) {
		$password = Give_Payfast_License::generatePassword();
		update_option( 'give_payfast_instance', $password );
	}
	return $password;
}
register_activation_hook( __FILE__, 'give_payfast_activate_plugin' );

/**
 * Includes the PayFast recurring class, if the recurring addon is active
 */
function give_payfast_recurring() {
	if ( class_exists( 'Give_Recurring' ) ) {
		include_once plugin_dir_path( __FILE__ ) . 'classes/class-give-recurring-payfast.php';
	}
}
add_action( 'init', 'give_payfast_recurring' );

/**
 * PayFast does not need a CC form, so remove it.
 */
add_action( 'give_payfast_cc_form', '__return_false' );

/**
 *    Registers our text domain with WP
 */
function give_payfast_load_textdomain() {
	load_plugin_textdomain( 'payfast_give', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'give_payfast_load_textdomain' );

/**
 * Registers the gateway
 */
function payfast_register_gateway( $gateways ) {
	$gateways['payfast'] = array(
		'admin_label'    => 'PayFast',
		'checkout_label' => __( 'PayFast', 'payfast_give' ),
	);
	return $gateways;
}
add_filter( 'give_payment_gateways', 'payfast_register_gateway' );

/**
 * Processes the order and redirect to the PayFast Merchant page
 */
function payfast_process_payment( $purchase_data, $recurring = false ) {
	$give_options = give_get_settings();

	// check there is a gateway name.
	if ( ! isset( $purchase_data['post_data']['give-gateway'] ) ) {
		return;
	}

	// collect payment data.
	$payment_data = array(
		'price'           => $purchase_data['price'],
		'give_form_title' => $purchase_data['post_data']['give-form-title'],
		'give_form_id'    => $purchase_data['post_data']['give-form-id'],
		'date'            => $purchase_data['date'],
		'user_email'      => $purchase_data['user_email'],
		'purchase_key'    => $purchase_data['purchase_key'],
		'currency'        => give_get_currency(),
		'user_info'       => $purchase_data['user_info'],
		'status'          => 'pending',
		'gateway'         => 'payfast',
	);
	$required     = array(
		'give_first' => __( 'First Name is not entered.', 'payfast_give' ),
		'give_last'  => __( 'Last Name is not entered.', 'payfast_give' ),
	);

	foreach ( $required as $field => $error ) {
		if ( ! $purchase_data['post_data'][ $field ] ) {
			give_set_error( 'billing_error', $error );
		}
	}

	$errors = give_get_errors();

	if ( $errors ) {
		// problems? send back.
		give_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['give-gateway'] );
	} else {

		// not a recurring- do payment insert.
		if ( false === $recurring ) {
			// record the pending payment.
			$payment = give_insert_payment( $payment_data );
			// check payment.
			if ( ! $payment ) {
				// problems? send back.
				give_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['give-gateway'] );
			}
		} else {

			$payment = $recurring->parent_payment_id;
		}

		$total = $purchase_data['price'];

		$seckey = $give_options['payfast_customer_id'] . $give_options['payfast_key'] . $total;
		$seckey = md5( $seckey );

		if ( give_is_test_mode() ) {
			// test mode.
			$payfast_url = 'https://sandbox.payfast.co.za/eng/process';
		} else {
			// live mode.
			$payfast_url = 'https://www.payfast.co.za/eng/process';
		}

		$redirect     = get_permalink( $give_options['success_page'] );
		$query_string = null;

		$permalink = give_get_failed_transaction_uri();
		$cancelurl = add_query_arg( 'error', '', $permalink );

		$payfast_args  = 'merchant_id=' . $give_options['payfast_customer_id'];
		$payfast_args .= '&merchant_key=' . $give_options['payfast_key'];
		$payfast_args .= '&return_url=' . urlencode( apply_filters( 'give_success_page_redirect', $redirect, 'payfast', $query_string ) );
		$payfast_args .= '&cancel_url=' . urlencode( $cancelurl );
		$payfast_args .= '&notify_url=' . urlencode( trailingslashit( home_url() ) );
		$payfast_args .= '&name_first=' . $purchase_data['post_data']['give_first'];
		$payfast_args .= '&name_last=' . $purchase_data['post_data']['give_last'];
		$payfast_args .= '&email_address=' . urlencode( $purchase_data['post_data']['give_email'] );
		$payfast_args .= '&m_payment_id=' . $payment;
		$payfast_args .= '&amount=' . $total;
		$payfast_args .= '&item_name=' . urlencode( $purchase_data['post_data']['give-form-title'] );
		$payfast_args .= '&custom_int1=' . $payment;
		$payfast_args .= '&custom_str1=' . $seckey;

		if ( false !== $recurring ) {
			$payfast_args .= '&custom_str2=' . $recurring->profile_id;
			$payfast_args .= '&subscription_type=1';
			switch ( $purchase_data['period'] ) {
				case 'month':
					$frequency = 3;
					break;
				case 'year':
					$frequency = 6;
					break;
			}
			$payfast_args .= '&frequency=' . $frequency;
			$payfast_args .= '&cycles=' . $purchase_data['times'];

		}

		if ( isset( $give_options['payfast_pass_phrase'] ) ) {
			$pass_phrase = trim( $give_options['payfast_pass_phrase'] );
		}
		if ( ! empty( $pass_phrase ) ) {
			$payfast_args .= '&pass_phrase=' . urlencode( $pass_phrase );
		}

		update_option( 'first_signature', md5( $payfast_args ) );

		$payfast_args .= '&signature=' . md5( $payfast_args );

		wp_redirect( $payfast_url . '?' . $payfast_args );
		exit();

	}

}
add_action( 'give_gateway_payfast', 'payfast_process_payment' );

/**
 * Processes the order and redirect to the PayFast Merchant page
 */

function payfast_get_realip() {
	$client  = wp_unslash( $_SERVER['HTTP_CLIENT_IP'] );
	$forward = wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	$remote  = wp_unslash( $_SERVER['REMOTE_ADDR'] );

	if ( filter_var( $client, FILTER_VALIDATE_IP ) ) {
		$ip = $client;
	} elseif ( filter_var( $forward, FILTER_VALIDATE_IP ) ) {
		$ip = $forward;
	} else {
		$ip = $remote;
	}

	return $ip;
}

/**
 * An action that handles the call from PayFast to tell Give the order was Completed
 */
function payfast_ipn() {
	$give_options = give_get_settings();

	if ( isset( $_REQUEST['m_payment_id'] ) ) {

		if ( give_is_test_mode() ) {
			$pf_host = 'https://sandbox.payfast.co.za/eng/query/validate';
			give_insert_payment_note( $_REQUEST['m_payment_id'], 'ITN callback has been triggered.' );
		} else {
			$pf_host = 'https://www.payfast.co.za/eng/query/validate';
		}

		$pf_error        = false;
		$pf_param_string  = '';
		$validate_string = '';

		if ( ! $pf_error ) {
			// Strip any slashes in data.
			foreach ( $_POST as $key => $val ) {
				$_POST[ $key ] = stripslashes( $val );
			}

			foreach ( $_POST as $key => $val ) {
				if ( $key != 'signature' ) {
					$pf_param_string .= $key . '=' . urlencode( $val ) . '&';
				}
			}
			$validate_string = $pf_param_string = substr( $pf_param_string, 0, - 1 );

			if ( isset( $give_options['payfast_pass_phrase'] ) ) {
				$pass_phrase = trim( $give_options['payfast_pass_phrase'] );
				if ( ! empty( $pass_phrase ) ) {
					$pf_param_string .= '&pass_phrase=' . urlencode( $pass_phrase );
				}
			}
		}
		$signature = md5( $pf_param_string );

		if ( give_is_test_mode() ) {
			give_insert_payment_note( $_REQUEST['m_payment_id'], sprintf( __( 'Signature Returned %1$s. Generated Signature %2$s.', 'payfast_give' ), $_POST['signature'], $signature ) );
		}

		if wp_verify_nonce( $signature != $_POST['signature'] ) {
			$pf_error = 'SIGNATURE';
			$error   = array(
				'oursig' => $signature,
				'vars'   => $_POST,
			);
		}

		if ( ! $pf_error ) {
			$valid_hosts = array(
				'www.payfast.co.za',
				'sandbox.payfast.co.za',
				'w1w.payfast.co.za',
				'w2w.payfast.co.za',
			);

			$valid_ips  = array();
			$sender_ip = payfast_get_realip();
			foreach ( $valid_hosts as $pf_hostname ) {
				$ips = gethostbynamel( $pf_hostname );

				if ( $ips !== false ) {
					$valid_ips = array_merge( $valid_ips, $ips );
				}
			}

			$valid_ips = array_unique( $valid_ips );

			if ( ! in_array( $sender_ip, $valid_ips ) ) {
				$pf_error = array(
					'FROM'  => $sender_ip,
					'VALID' => $valid_ips,
				);
			}
		}

		/*
		* If it fails for any reason, add that to the order.
		*/
		if ( false !== $pf_error ) {
			//
			give_insert_payment_note( $_POST['m_payment_id'], sprintf( __( 'Payment Failed. The error is %s.', 'payfast_give' ), print_r( $pf_error, true ) ) );
		} else {

			$response = wp_remote_post(
				$pf_host, array(
					'method'      => 'POST',
					'timeout'     => 60,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking'    => true,
					'headers'     => array(),
					'body'        => $validate_string,
					'cookies'     => array(),
				)
			);

			if ( give_is_test_mode() ) {
				give_insert_payment_note(
					$_POST['m_payment_id'], sprintf(
						//
						__( 'PayFast ITN Params - %1$s %2$s.', 'payfast_give' ), $pf_host, print_r(
							array(
								'method'      => 'POST',
								'timeout'     => 60,
								'redirection' => 5,
								'httpversion' => '1.0',
								'blocking'    => true,
								'headers'     => array(),
								'body'        => $validate_string,
								'cookies'     => array(),
							), true
						)
					)
				);
				//
				give_insert_payment_note( $_POST['m_payment_id'], sprintf( __( 'PayFast ITN Response. %s.', 'payfast_give' ), print_r( $response['body'], true ) ) );
			}

			if ( ! is_wp_error( $response ) && ( $response['response']['code'] >= 200 || $response['response']['code'] < 300 ) ) {
				$res = $response['body'];
				if ( $res === false ) {
					$pf_error = $response;

				}
			}
		}

		if ( ! $pf_error ) {
			$lines = explode( "\n", $res );
		}

		if ( ! $pf_error ) {
			$result = trim( $lines[0] );

			if ( strcmp( $result, 'VALID' ) === 0 ) {
				if ( $_POST['payment_status'] === 'COMPLETE' ) {

					if ( ! empty( $_POST['custom_str2'] ) ) {
						$subscription = new Give_Subscription( $_POST['custom_str2'], true );
						// Retrieve pending subscription from database and update it's status to active and set proper profile ID.
						$subscription->update(
							array(
								'profile_id' => $_POST['token'],
								'status'     => 'active',
							)
						);
					}
					give_set_payment_transaction_id( $_POST['m_payment_id'], $_POST['pf_payment_id'] );
					// 
					give_insert_payment_note( $_POST['m_payment_id'], sprintf( __( 'PayFast Payment Completed. The Transaction Id is %s.', 'payfast_give' ), $_POST['pf_payment_id'] ) );
					give_update_payment_status( $_POST['m_payment_id'], 'publish' );

				} else {
					//  
					give_insert_payment_note( $_POST['m_payment_id'], sprintf( __( 'PayFast Payment Failed. The Response is %s.', 'payfast_give' ), print_r( $response['body'], true ) ) );
				}
			}
		}
	}
}
add_action( 'wp_head', 'payfast_ipn' );

/**
 * Registers our PayFast setting with Give.
 *
 * @param  $settings
 * @return array
 */
function payfast_add_settings( $settings ) {

	$payfast_settings = array(

		array(
			'id'   => 'payfast_settings',
			'name' => __( 'PayFast Settings', 'payfast_give' ),
			'type' => 'give_title',
		),
		array(
			'id'   => 'payfast_api_email',
			'name' => __( 'Email Address', 'payfast_give' ),
			'desc' => __( 'This is the email address you used to purchase the plugin.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
		array(
			'id'   => 'payfast_customer_id',
			'name' => __( 'PayFast Merchant Id', 'payfast_give' ),
			'desc' => __( 'Please enter your PayFast Merchant Id; this is needed in order to take payment.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
		array(
			'id'   => 'payfast_key',
			'name' => __( 'PayFast Key', 'payfast_give' ),
			'desc' => __( 'Please enter your PayFast Key; this is needed in order to take payment.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
		array(
			'id'   => 'payfast_pass_phrase',
			'name' => __( 'pass_phrase', 'payfast_give' ),
			'desc' => __( 'This is set by yourself in the "Settings" section of the logged in area of the PayFast Dashboard.', 'payfast_give' ),
			'type' => 'text',
			'size' => 'regular',
		),
	);

	return array_merge( $settings, $payfast_settings );
}
add_filter( 'give_settings_gateways', 'payfast_add_settings' );
