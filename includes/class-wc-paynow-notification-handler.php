<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Paynow\Model\Payment\Status;

class WC_Gateway_Paynow_Notification_Handler extends WC_Gateway_Paynow {

	public function __construct() {
		parent::__construct();
		add_action( 'woocommerce_api_wc_gateway_paynow', array( $this, 'handle_notification' ) );
	}

	/**
	 * Handle notification request
	 */
	public function handle_notification() {
		if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] )
		     || ! isset( $_GET['wc-api'] )
		     || ( 'WC_Gateway_Paynow_Notification_Handler' !== $_GET['wc-api'] )
		) {
			status_header( 400 );
		}

		$payload           = trim( file_get_contents( 'php://input' ) );
		$headers           = WC_Paynow_Helper::get_request_headers();
		$notification_data = json_decode( $payload, true );

		try {
			new \Paynow\Notification( $this->signature_key, $payload, $headers );
			$order = wc_get_order( $notification_data['externalId'] );

			if ( ! $order ) {
				$error_message = 'Order was not found for ' . $notification_data['externalId'];
				WC_Paynow_Logger::log( 'Error: ' . $error_message );
				status_header( 400 );
				exit;
			}

			$this->process_notification( $order, $notification_data );
		} catch ( \Exception $exception ) {
			WC_Paynow_Logger::log( 'Error: ' . $exception->getMessage() );
			status_header( 400 );
			exit;
		}

		status_header( 202 );
		exit;
	}

	/**
	 * @param WC_order $order
	 * @param array $notification_data
	 */
	private function process_notification( $order, $notification_data ) {
		$notification_status = $notification_data['status'];

		if ( $this->is_correct_status( $this->map_order_status( $order ), $notification_status ) ) {
			switch ( $notification_status ) {
				case Status::STATUS_PENDING:
					break;
				case Status::STATUS_REJECTED:
					$order->update_status( 'failed', __( 'Payment has not been authorized by the buyer.', 'woocommerce-gateway-paynow' ) );
					break;
				case Status::STATUS_CONFIRMED:
					$order->payment_complete( $notification_data['paymentId'] );
					$order->add_order_note( __( 'Payment has been authorized by the buyer.', 'woocommerce-gateway-paynow' ) );
					break;
				case Status::STATUS_ERROR:
					$order->update_status( 'failed', __( 'Error occurred during the payment process and the payment could not be completed.', 'woocommerce-gateway-paynow' ) );
					break;
			}
		}
	}

	/**
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	private function map_order_status( $order ) {
		if ( $order->has_status( 'on-hold' ) ) {
			return Status::STATUS_NEW;
		} elseif ( $order->has_status( [ 'pending', 'processing' ] ) ) {
			return Status::STATUS_PENDING;
		} elseif ( $order->has_status( 'completed' ) ) {
			return Status::STATUS_CONFIRMED;
		} elseif ( $order->has_status( 'failed' ) ) {
			return Status::STATUS_ERROR;
		}

		return Status::STATUS_PENDING;
	}

	/**
	 * @param $previous_status
	 * @param $next_status
	 *
	 * @return bool
	 */
	private function is_correct_status( $previous_status, $next_status ) {
		$payment_status_flow    = [
			Status::STATUS_NEW       => [
				Status::STATUS_PENDING,
				Status::STATUS_ERROR
			],
			Status::STATUS_PENDING   => [
				Status::STATUS_CONFIRMED,
				Status::STATUS_REJECTED
			],
			Status::STATUS_REJECTED  => [ Status::STATUS_CONFIRMED ],
			Status::STATUS_CONFIRMED => [],
			Status::STATUS_ERROR     => []
		];
		$previous_status_exists = isset( $payment_status_flow[ $previous_status ] );
		$is_change_possible     = in_array( $next_status, $payment_status_flow[ $previous_status ] );

		return $previous_status_exists && $is_change_possible;
	}
}

new WC_Gateway_Paynow_Notification_Handler();