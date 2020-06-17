<?php
/**
 * An extension of the Give-Recurring-Gateway Class
 *
 * @package   give-payfast
 * @author    LightSpeed
 * @license   GPL-3.0+
 * @link
 * @copyright 2018 LightSpeed Team
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
		if ( 'revision' == isset( $post->post_type ) && $post->post_type ) {
			return $form_id;
		}
		if ( 'give_forms' != isset( $post->post_type ) || $post->post_type ) {
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

		if ( 'yes_admin' == $set_or_multi && 'multi' == $recurring_option ) {

			$prices = isset( $_REQUEST['_give_donation_levels'] ) ? $_REQUEST['_give_donation_levels'] : array( '' );
			foreach ( $prices as $price_id => $price ) {
				$period = isset( $price['_give_period'] ) ? $price['_give_period'] : 0;

				if ( in_array( $period, array( 'day', 'week' ) ) ) {
					wp_die( esc_html( $message ), esc_html__( 'Error', 'give-recurring' ), array(
						'response' => 400,
					) );
				}
			}
		} elseif ( Give_Recurring()->is_recurring( $form_id ) ) {

			$period = isset( $_REQUEST['_give_period'] ) ? $_REQUEST['_give_period'] : 0;

			if ( in_array( $period, array( 'day', 'week' ) ) ) {
				wp_die( esc_html( $message ) , esc_html__( 'Error', 'give-recurring' ), array(
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
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $payload ) ); // for the body values such as amount, frequency, & date.
		curl_setopt( $ch, CURLOPT_VERBOSE, true );
		curl_setopt(
			$ch, CURLOPT_HTTPHEADER, array(
				'version: v1',
				'merchant-id: 10003644',
				'signature: ' . $signature,
				'timestamp: ' . $hash_array['timestamp'],
			)
		);

		// execute and close cURL.
		$data = curl_exec( $ch );
		curl_close( $ch );

		$data = json_decode( $data );

		if ( '200' === isset( $data->code ) && $data->code ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Processes the recurring donation form and sends sets up the subscription data for hand-off to the gateway.
	 *
	 * @param $donation_data
	 *
	 * @access      public
	 * @since       1.0
	 * @return      void
	 *
	 */
	public function process_checkout( $donation_data ) {

		// If not a recurring purchase so bail.
		if ( ! Give_Recurring()->is_donation_recurring( $donation_data ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $donation_data['gateway_nonce'], 'give-gateway' ) ) {
			wp_die( __( 'Nonce verification failed.', 'give-recurring' ), __( 'Error', 'give-recurring' ), array( 'response' => 403 ) );
		}

		// Initial validation.
		do_action( 'give_recurring_process_checkout', $donation_data, $this );

		$errors = give_get_errors();

		if ( $errors ) {
			give_send_back_to_checkout( '?payment-mode=' . $this->id );
		}

		$this->purchase_data = apply_filters( 'give_recurring_purchase_data', $donation_data, $this );
		$this->user_id       = $donation_data['user_info']['id'];
		$this->email         = $donation_data['user_info']['email'];

		if ( empty( $this->user_id ) ) {
			$subscriber = new Give_Donor( $this->email );
		} else {
			$subscriber = new Give_Donor( $this->user_id, true );
		}

		if ( empty( $subscriber->id ) ) {

			$name = sprintf(
				'%s %s',
				( ! empty( $donation_data['user_info']['first_name'] ) ? trim( $donation_data['user_info']['first_name'] ) : '' ),
				( ! empty( $donation_data['user_info']['last_name'] ) ? trim( $donation_data['user_info']['last_name'] ) : '' )
			);

			$subscriber_data = array(
				'name'    => trim( $name ),
				'email'   => $donation_data['user_info']['email'],
				'user_id' => $this->user_id,
			);

			$subscriber->create( $subscriber_data );

		}

		$this->customer_id = $subscriber->id;

		// Get billing times.
		$times = ! empty( $this->purchase_data['times'] ) ? intval( $this->purchase_data['times'] ) : 0;
		// Get frequency value.
		$frequency = ! empty( $this->purchase_data['frequency'] ) ? intval( $this->purchase_data['frequency'] ) : 1;

		$payment_data = array(
			'price'           => $this->purchase_data['price'],
			'give_form_title' => $this->purchase_data['post_data']['give-form-title'],
			'give_form_id'    => intval( $this->purchase_data['post_data']['give-form-id'] ),
			//'give_price_id'   => $this->get_price_id(),
			'date'            => $this->purchase_data['date'],
			'user_email'      => $this->purchase_data['user_email'],
			'purchase_key'    => $this->purchase_data['purchase_key'],
			'currency'        => give_get_currency(),
			'user_info'       => $this->purchase_data['user_info'],
			'status'          => 'pending',
		);

		// Record the pending payment.
		$this->payment_id = give_insert_payment( $payment_data );

		$this->subscriptions = apply_filters( 'give_recurring_subscription_pre_gateway_args', array(
			'name'             => $this->purchase_data['post_data']['give-form-title'],
			'id'               => $this->purchase_data['post_data']['give-form-id'], // @TODO Deprecate w/ backwards compatiblity.
			'form_id'          => $this->purchase_data['post_data']['give-form-id'],
			//'price_id'         => $this->get_price_id(),
			'initial_amount'   => give_sanitize_amount_for_db( $this->purchase_data['price'] ), // add fee here in future.
			'recurring_amount' => give_sanitize_amount_for_db( $this->purchase_data['price'] ),
			'period'           => $this->get_interval( $this->purchase_data['period'], $frequency ),
			'frequency'        => $this->get_interval_count( $this->purchase_data['period'], $frequency ), // Passed interval. Example: charge every 3 weeks.
			'bill_times'       => give_recurring_calculate_times( $times, $frequency ),
			'profile_id'       => '', // Profile ID for this subscription - This is set by the payment gateway.
			'transaction_id'   => '', // Transaction ID for this subscription - This is set by the payment gateway.
		) );

		do_action( 'give_recurring_pre_create_payment_profiles', $this );

		// Create subscription payment profiles in the gateway.
		$this->create_payment_profiles();

		do_action( 'give_recurring_post_create_payment_profiles', $this );

		// Look for errors after trying to create payment profiles.
		$errors = give_get_errors();

		if ( $errors ) {
			give_send_back_to_checkout( '?payment-mode=' . $this->id );
		}

		// Record the subscriptions and finish up.
		$this->record_signup();

		// Finish the signup process.
		// Gateways can perform off-site redirects here if necessary.
		$this->complete_signup();

		// Look for any last errors.
		$errors = give_get_errors();

		// We shouldn't usually get here, but just in case a new error was recorded,
		// we need to check for it.
		if ( $errors ) {
			give_send_back_to_checkout( '?payment-mode=' . $this->id );
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
