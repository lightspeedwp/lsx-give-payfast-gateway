<?php
/**
 * An extension of the Give_Recurring_Gateway class
 *
 * @package   give-payfast
 * @author    LightSpeed
 * @license   GPL-3.0+
 * @link
 * @copyright 2016 LightSpeed Team
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Give_Recurring_Gateway' ) ) {
	return;
}

global $give_recurring_payfast;

/**
 * Class Give_Recurring_PayFast
 */
class Give_Recurring_PayFast extends Give_Recurring_Gateway {


	/**
	 * Setup gateway ID and possibly load API libraries.
	 *
	 * @access public
	 * @since  1.0
	 * @return void
	 */
	public function init() {

		$this->id = 'payfast';

		// create as pending.
		$this->offsite = true;

		// Cancellation action.
		add_action( 'give_recurring_cancel_payfast_subscription', array( $this, 'cancel' ), 10, 2 );
		add_action( 'give_subscription_cancelled', array( $this, 'cancel' ), 11, 2 );

		// Validate payfast periods.
		add_action( 'save_post', array( $this, 'validate_recurring_period' ) );
	}

	/**
	 * Creates subscription payment profiles and sets the IDs so they can be stored.
	 *
	 * @access public
	 * @since  1.0
	 */
	public function create_payment_profiles() {

		// Creates a payment profile and then sets the profile ID.
		$this->subscriptions['profile_id'] = 'payfast-' . $this->purchase_data['purchase_key'];

	}

	/**
	 * Validate PayFast Recurring Donation Period
	 *
	 * @description: Additional server side validation for Standard recurring
	 *
	 * @param int $form_id
	 *
	 * @return mixed
	 */
	function validate_recurring_period( $form_id = 0 ) {

		global $post;
		$recurring_option = isset( $_REQUEST['_give_recurring'] ) ? $_REQUEST['_give_recurring'] : 'no';
		$set_or_multi     = isset( $_REQUEST['_give_price_option'] ) ? $_REQUEST['_give_price_option'] : '';

		// Sanity Checks.
		if ( ! class_exists( 'Give_Recurring' ) ) {
			return $form_id;
		}
		if ( 'no' == $recurring_option ) {
			return $form_id;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_REQUEST['bulk_edit'] ) ) {
			return $form_id;
		}
		if ( isset( $post->post_type ) && $post->post_type == 'revision' ) {
			return $form_id;
		}
		if ( ! isset( $post->post_type ) || $post->post_type != 'give_forms' ) {
			return $form_id;
		}
		if ( ! current_user_can( 'edit_give_forms', $form_id ) ) {
			return $form_id;
		}

		// Is this gateway active.
		if ( ! give_is_gateway_active( $this->id ) ) {
			return $form_id;
		}

		$message = __( 'PayFast Only allows for Monthly and Yearly recurring donations. Please revise your selection.', 'give-recurring' );

		if ( $set_or_multi === 'multi' && $recurring_option == 'yes_admin' ) {

			$prices = isset( $_REQUEST['_give_donation_levels'] ) ? $_REQUEST['_give_donation_levels'] : array( '' );
			foreach ( $prices as $price_id => $price ) {
				$period = isset( $price['_give_period'] ) ? $price['_give_period'] : 0;

				if ( in_array( $period, array( 'day', 'week' ) ) ) {
					wp_die( $message, __( 'Error', 'give-recurring' ), array( 'response' => 400 ) );
				}
			}
		} elseif ( Give_Recurring()->is_recurring( $form_id ) ) {

			$period = isset( $_REQUEST['_give_period'] ) ? $_REQUEST['_give_period'] : 0;

			if ( in_array( $period, array( 'day', 'week' ) ) ) {
				wp_die( $message, __( 'Error', 'give-recurring' ), array(
					'response' => 400,
				) );
			}
		}

		return $form_id;

	}

	/**
	 * Determines if the subscription can be cancelled
	 *
	 * @access public
	 * @return bool
	 */
	public function can_cancel( $ret, $subscription ) {
		$ret = false;
		if ( 'active' === $subscription->status ) {
			$ret = true;
		}
		return $ret;
	}

	/**
	 * Contacts Payfast and "Cancels" a subscription
	 *
	 * @access public
	 * @return bool
	 */
	public function cancel( $subscription_id, $subscription ) {
		$give_options = give_get_settings();

		if ( isset( $subscription->gateway ) && 'payfast' !== $subscription->gateway ) {
			return false;
		}

		// pass_phrase - must be set on the merchant account for recurring billing.
		$pass_phrase = $give_options['payfast_pass_phrase'];
		if ( isset( $give_options['payfast_pass_phrase'] ) && ! empty( $give_options['payfast_pass_phrase'] ) ) {
			$pass_phrase = trim( $give_options['payfast_pass_phrase'] );
		}

		// array of the data that will be sent to the API for use in the signature generation
		// amount, item_name, & item_description must be added here when performing an update call.
		$hash_array = array(
			'merchant-id' => '10003644',
			'version'     => 'v1',
			'timestamp'   => date( 'Y-m-d' ) . 'T' . date( 'H:i:s' ),
		);

		// $pf_data
		$pf_data = $hash_array;

		// construct variables.
		foreach ( $pf_data as $key => $val ) {
			$pf_data[ $key ] = stripslashes( trim( $val ) );
		}

		// check if a pass_phrase has been set - must be set.
		if ( isset( $pass_phrase ) ) {
			$pf_data['pass_phrase'] = stripslashes( trim( $pass_phrase ) );
		}

		// sort the array by key, alphabetically.
		ksort( $pf_data );

		// normalise the array into a parameter string.
		$pf_param_string = '';
		foreach ( $pf_data as $key => $val ) {
			$pf_param_string .= $key . '=' . urlencode( $val ) . '&';
		}

		// remove the last '&' from the parameter string.
		$pf_param_string = substr( $pf_param_string, 0, -1 );

		// hash and push the signature.
		$signature = md5( $pf_param_string );

		// payload array - required for update call (body values are amount, frequency, date).
		$payload = []; // used for CURLOPT_POSTFIELDS.

		// set up the url.
		$url = 'https://api.payfast.co.za/subscriptions/' . $subscription->profile_id . '/cancel';
		if ( give_is_test_mode() ) {
			$url .= '?testing=true';
		}

		// set up cURL.
		$ch = curl_init( $url ); // add "?testing=true" to the end when testing.
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $payload ) ); // for the body values such as amount, frequency, & date.
		curl_setopt( $ch, CURLOPT_VERBOSE, true );
		curl_setopt(
			$ch, CURLOPT_HTTPHEADER, array(
				'version: v1',
				'merchant-id: ' . '10003644',
				'signature: ' . $signature,
				'timestamp: ' . $hash_array['timestamp'],
			)
		);

		// execute and close cURL.
		$data = curl_exec( $ch );
		curl_close( $ch );

		$data = json_decode( $data );

		if ( isset( $data->code ) && $data->code === '200' ) {
			return true;
		} else {
			return false;
		}

	}


	/**
	 * Creates payment and redirects to PayFast
	 *
	 * @access public
	 * @since  1.0
	 * @return void
	 */
	public function complete_signup() {

		$subscription = new Give_Subscription( $this->subscriptions['profile_id'], true );
		payfast_process_payment( $this->purchase_data, $subscription );

	}
}

$give_recurring_payfast = new Give_Recurring_PayFast();
