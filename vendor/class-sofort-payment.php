<?php
/**
 * @package     WordPress
 * @subpackage  Give
 * @author      Birgit Olzem
 * @copyright   2017, Birgit Olzem
 * @link        http://coachbirgit.com
 * @license     http://www.opensource.org/licenses/gpl-2.0.php GPL License
 */
/** @define "GIVE_SOFORT_DIR" "/Users/coachbirgit/PhpstormProjects/give-sofort" */

// No direct access is allowed
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require GIVE_SOFORT_DIR . 'vendor/autoload.php';


if ( ! class_exists( 'Give_Sofort_Gateway_Processor' ) ) :

	/**
	 * Handles the actual gateway (SOFORT Banking)
	 *
	 * Adds admin options, frontend fields
	 * and handles payment processing
	 *
	 * @since   1.0
	 */
	class Give_Sofort_Gateway_Processor {

		//public $payment_id;

		/**
		 * Initialize the gateway
		 *
		 * @since   1.0
		 * @uses    apply_filters()
		 */
		public function __construct() {
			// filters & actions

			add_action( 'give_sofort_form', 'give_sofort_payment_form' );

			add_action( 'give_gateway_sofort', array( $this, 'give_process_sofort_payment' ), 10, 1 );
			//add_action( 'give_sofort_payment_txn', array( $this, 'give_sofort_payment_listener' ), 10, 1  );

			add_action( 'give_handle_sofort_api_response', array( $this, 'give_sofort_payment_listener' ), 10, 1 );


		}


		function give_sofort_payment_form( $form_id ) {
			// Get sofort payment instruction.
			$sofort_instructions = give_get_sofort_payment_instruction( $form_id, true );
			ob_start();
			/**
			 * Fires before the offline info fields.
			 *
			 * @since 1.0
			 *
			 * @param int $form_id Give form id.
			 */
			do_action( 'give_before_sofort_info_fields', $form_id );
			?>
            <fieldset id="give_sofort_payment_info">
				<?php echo stripslashes( $offline_instructions ); ?>
            </fieldset>
			<?php
			/**
			 * Fires after the offline info fields.
			 *
			 * @since 1.0
			 *
			 * @param int $form_id Give form id.
			 */
			do_action( 'give_after_sofort_info_fields', $form_id );
			echo ob_get_clean();
		}

		/**
		 * Sofort. Payments
		 *
		 * @param $payment_data
		 */
		public function give_process_sofort_payment( $purchase_data ) {

			$errors = give_get_errors();

			// No errors: Continue with payment processing
			if ( ! $errors ) {


				$payment_data = array(
					'price'           => $purchase_data['price'],
					'give_form_title' => $purchase_data['post_data']['give-form-title'],
					'give_form_id'    => intval( $purchase_data['post_data']['give-form-id'] ),
					'give_price_id'   => isset( $purchase_data['post_data']['give-price-id'] ) ? $purchase_data['post_data']['give-price-id'] : '',
					'date'            => $purchase_data['date'],
					'user_email'      => $purchase_data['user_email'],
					'purchase_key'    => $purchase_data['purchase_key'],
					'currency'        => give_get_currency(),
					'user_info'       => $purchase_data['user_info'],
					'status'          => 'pending',
					/** THIS MUST BE SET TO PENDING TO AVOID PHP WARNINGS */
					'gateway'         => 'sofort'
					/** USE YOUR SLUG AGAIN HERE */
				);

				$payment_id = give_insert_payment( $payment_data );


				/**
				 * Here you will reach out to whatever payment processor you are building for and record a successful payment
				 *
				 * If it's not correct, make $payment false and attach errors
				 */

				$payment_amount = give_get_payment_amount( $payment_id );
				$api_amount     = (float) number_format( $payment_amount, 2, '.', '' );

				$api_config_key = give_get_option( 'sofort_config_key' );
				$api_reason1    = give_get_option( 'sofort_reason' );
				$api_reason2    = $purchase_data['post_data']['give-form-title'];
				$api_order_key  = $purchase_data['purchase_key'];
				$api_currency   = give_get_currency();
				// Get the success url.
				$api_success_page = add_query_arg( array(
					'payment-confirmation' => 'sofort',
					'payment-id'           => $payment_id,

				), get_permalink( give_get_option( 'success_page' ) ) );
				$api_abort_page   = give_get_failed_transaction_uri();

				/* @todo check if is this notification URI right? */
				//$api_notification_url = give_get_success_page_uri();
				$api_notification_url = home_url( '/?give-action=handle_sofort_api_response&payment-id=' . $payment_id );


				$api = new Sofort\SofortLib\Sofortueberweisung( $api_config_key );

				$api->setAmount( $api_amount );

				$api->setCurrencyCode( $api_currency );
				$api->setReason( $api_reason1, $api_reason2 );
				$api->setUserVariable( $payment_id );

				$api->setSuccessUrl( $api_success_page, true ); // i.e. http://my.shop/order/success
				$api->setAbortUrl( $api_abort_page );
				$api->setNotificationUrl( $api_notification_url );

				$api->sendRequest();

				if ( $api->isError() ) {
					// SOFORT-API didn't accept the data
					echo $api->getError();
				} else {


					// buyer must be redirected to $paymentUrl else payment cannot be successfully completed!
					$paymentUrl = $api->getPaymentUrl();
					header( 'Location: ' . $paymentUrl );

					//do_action( 'give_sofort_payment_txn', $payment_id);
				}


			} // end if $errors

			// Return api if set.
			/*  if ( isset( $api ) ) {
				  return $api;
			  } else {
				  return false;
			  }*/

		}

		public function give_sofort_payment_listener() {
			$api = new Sofort\SofortLib\Notification();

			$transaction_id = $api->getNotification( file_get_contents( 'php://input' ) );

			if ( ! $transaction_id ) {
				$this->log_error( 'Getting notification failed. No transaction id.' );
			}

			$api_config_key    = give_get_option( 'sofort_config_key' );
			$api_trust_pending = give_get_option( 'sofort_trust_pending' );

			$transaction_data = new Sofort\SofortLib\TransactionData( $api_config_key );

			$transaction_data->addTransaction( $transaction_id );

			// By default without setter Api version 1.0 will be used due to backward compatibility, please set ApiVersion to
			// latest version. Please note that the response might have a different structure and values For more details please
			// see our Api documentation on https://www.sofort.com/integrationCenter-ger-DE/integration/API-SDK/
			$transaction_data->setApiVersion( '2.0' );
			$transaction_data->getTransaction( $transaction_id );
			$transaction_data->sendRequest();

			$reason     = $transaction_data->getStatusReason();
			$payment_id = $transaction_data->getUserVariable();
			$status     = $transaction_data->getStatus();

			/* @var Give_Payment $payment */
			$payment = new Give_Payment( $payment_id );

			echo "Payment ID: ";
			print_r( $payment_id );
            echo chr( 13 );

			echo "Status: ";
			print_r( $status );
            echo chr( 13 );

			echo "Reason: ";
			print_r( $reason );
			echo chr( 13 );

			echo "API Trust pending: ";
			print_r( $api_trust_pending );
			echo chr( 13 );

			echo "Transaction ID: ";
			print_r( $transaction_id );
			echo chr( 13 );

			$this->log_message( 'Payment listener for payment #' . $payment_id . ' initialized.' );

			if ( $payment && 'pending' === $payment->post_status ) {

				if ( 'pending' === $status || 'untraceable' === $status ) {
					if ( 'not_credited_yet' == $reason && 'enabled' == $api_trust_pending ) :

						give_insert_payment_note( $payment_id, sprintf( /* translators: %s: Sofort transaction ID */
							__( 'Sofort Transaction ID: %s', 'give-sofort' ), $transaction_id ) );
						give_set_payment_transaction_id( $payment_id, $transaction_id );
						give_update_payment_status( $payment_id, 'publish' );

						$this->log_message( 'Pending and not credited. Trusting payment. Payment completed for payment #' . $payment_id . '.' );

						/** This line will finalize the donation, you can run some other verification function if you want before setting to publish */
						echo "Pending and not credited. Trusting payment. Payment completed.";

						exit;

					else:

						give_insert_payment_note( $payment_id, sprintf( /* translators: %s: Sofort transaction ID */
							__( 'Sofort Transaction ID: %s', 'give-sofort' ), $transaction_id ) );
						give_set_payment_transaction_id( $payment_id, $transaction_id );
						give_update_payment_status( $payment_id, 'pending' );
						echo "Pending and not credited.";

						$this->log_message( 'Pending and not credited for payment #' . $payment_id . '.' );

						exit;

					endif;

				} else if ( 'received' === $status ) {


					if ( $reason == 'credited' ) :

						give_insert_payment_note( $payment_id, sprintf( /* translators: %s: Sofort transaction ID */
							__( 'Sofort Transaction ID: %s', 'give-sofort' ), $transaction_id ) );
						give_set_payment_transaction_id( $payment_id, $transaction_id );
						give_update_payment_status( $payment_id, 'publish' );

						$this->log_message( 'Payment completed with reason: ' . $reason . ' for payment #' .  $payment_id . '.' );

						echo "Payment completed with reason: " . $reason;

						exit;

					else:

						give_insert_payment_note( $payment_id, sprintf( /* translators: %s: Sofort transaction ID */
							__( 'Sofort Transaction ID: %s', 'give-sofort' ), $transaction_id ) );
						give_set_payment_transaction_id( $payment_id, $transaction_id );
						give_update_payment_status( $payment_id, 'pending' );

						$this->log_message( 'Payment completed with reason: ' . $reason . ' for payment #' .  $payment_id . '.' );

						echo "Payment completed with reason: " . $reason;

						exit;

					endif;

				}

				return;

			}
		}

		/**
         * Logging error message
         *
         * @param string $message
         *
		 * @return int ID of the new log entry
		 */
        public function log_error( $message ) {
	        return give_record_gateway_error( 'Sofort Payment Gateway', $message );
        }

		/**
		 * Logging message
		 *
		 * @param string $message
		 *
		 * @return int ID of the new log entry
		 */
	    public function log_message( $message ) {
	        return give_record_log( 'Sofort Payment Gateway', $message, 0, 'api_request' );
        }

		/**
		 * Set notice for offline donation.
		 *
		 * @since 1.7
		 *
		 * @param string $notice
		 * @param int $id
		 *
		 * @return string
		 */
		function give_sofort_donation_receipt_status_notice( $notice, $id ) {
			$payment = new Give_Payment( $id );

			if ( 'sofort' !== $payment->gateway || $payment->is_completed() ) {
				return $notice;
			}

			return Give()->notices->print_frontend_notice( __( 'Payment Pending: Please follow the instructions below to complete your donation.', 'give' ), false, 'warning' );
		}


	} // give_process_sofort_payment()

	return new Give_Sofort_Gateway_Processor();

endif;