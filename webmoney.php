<?php
/*
	Name: WebMoney Payment Method for Restrict Content Pro
	Version: 0.0.1
	License: MIT

	THIS PLUGIN WORKS CORRECTLY ONLY FOR NEWER VERSIONS OF RCP >= 3.0
*/

if (!defined('ABSPATH')) exit;

function rcp_webmoney_init()
{
	if (!class_exists('RCP_Payment_Gateway')) return;
	class RCP_Payment_Gateway_WebMoney extends RCP_Payment_Gateway
	{
		/**
		 * SignUp process => before payment
		 */
		function process_signup()
		{
			global $rcp_options;
			/**
			 * Result URL (containing the query fields for triggering the webhooks)
			 */
			$result = add_query_arg(array(
				'listener' => 'webmoney',
				'key'      => $this->subscription_key,
				'custom'   => $this->user_id,
				'amount'   => $this->initial_amount,
				'email'   => $this->email,
				'sname'    => urlencode($this->subscription_name),
			), home_url('index.php'));
			echo '<form action="https://merchant.wmtransfer.com/lmi/payment.asp" method="POST" accept-charset="utf-8" name="process">
						<input type="hidden" name="LMI_PAYMENT_AMOUNT"	   	value="' . $this->initial_amount . '">
						<input type="hidden" name="LMI_PAYEE_PURSE"    	  	value="' . $rcp_options['webmoney_payment_purse'] . '">
						<input type="hidden" name="LMI_PAYMENT_NO"    	  	value="' . rand(999, 999999999999) . '">
						<input type="hidden" name="LMI_PAYMENT_DESC"      	value="' . $rcp_options['webmoney_payment_desc'] . '">
						<input type="hidden" name="LMI_PAYMER_EMAIL"      	value="' . $this->email . '">
						<input type="hidden" name="LMI_SUCCESS_URL"         value="' . $rcp_options['webmoney_success'] . '" />
						<input type="hidden" name="LMI_SUCCESS_METHOD"     	value="2" />
						<input type="hidden" name="LMI_FAIL_URL"          	value="' . $rcp_options['webmoney_fail'] . '" />
						<input type="hidden" name="LMI_FAIL_METHOD" 		  	value="2" />
						<input type="hidden" name="LMI_SIM_MODE" 				  	value="0" />
						<input type="hidden" name="LMI_RESULT_URL"          value="' . $result . '">
						<noscript><input type="submit" class="webmoney_btn btn button" value="Pay with WebMoney" /></noscript>
					</form><script>document.process.submit();</script>';
			exit;
		}

		/**
		 * Webhooks process => after payment
		 */
		public function process_webhooks()
		{
			if (!(isset($_GET['listener']) && $_GET['listener'] == 'webmoney')) return;
			global $rcp_options, $wpdb, $rcp_payments_db_name;

			/**
			 * Check for fail
			 */
			$FAILREQUEST = @$_REQUEST['LMI_FAILREQUEST'];
			$PAYMENT_PURSE = @$_REQUEST['LMI_PAYEE_PURSE'];
			if (!$FAILREQUEST && !$PAYMENT_PURSE) {
				exit('NOP'); // No Operation (In case when the webmoney-gateway sends prerequest form)
			}

			/**
			 * Needed variables
			 */
			$PAYMENT_AMOUNT = @$_REQUEST['LMI_PAYMENT_AMOUNT'];
			$PAYMENT_NO = @$_REQUEST['LMI_PAYMENT_NO'];
			$V2_HASH = @$_REQUEST['LMI_HASH2'];
			$MODE = @$_REQUEST['LMI_MODE'];
			$SYS_INVS_NO = @$_REQUEST['LMI_SYS_INVS_NO'];
			$SYS_TRANS_NO = @$_REQUEST['LMI_SYS_TRANS_NO'];
			$SYS_TRANS_DATE = @$_REQUEST['LMI_SYS_TRANS_DATE'];
			$PAYER_PURSE = @$_REQUEST['LMI_PAYER_PURSE'];
			$PAYER_WM = @$_REQUEST['LMI_PAYER_WM'];
			$PAYMENT_ID =  ($SYS_INVS_NO && $SYS_TRANS_NO)
				? $PAYER_WM . '-' . $SYS_INVS_NO . '-' . $SYS_TRANS_NO : ('ERROR-' . time());
			$secret = $rcp_options['webmoney_secret'];
			$amount = number_format($_GET['amount'], 2, '.', '');
			$email = $_GET['email'];
			$currency = $this->currency ?: rcp_get_currency();
			try {
				$to_hash = array(
					$PAYMENT_PURSE,
					$PAYMENT_AMOUNT,
					$PAYMENT_NO,
					$MODE,
					$SYS_INVS_NO,
					$SYS_TRANS_NO,
					$SYS_TRANS_DATE,
					$secret,
					$PAYER_PURSE,
					$PAYER_WM
				);
				$hash = strtoupper(hash('sha256', implode(';', $to_hash))); // Create HASH server-side for verification
			} catch (Exception $e) {
				$hash = "";
			}

			$log = print_r(
				array(
					'GET' => $_GET,
					'POST' => $_POST,
					'REQUEST' => $_REQUEST,
					'PAYEE_ACCOUNT' => $rcp_options['webmoney_payment_purse'],
					'hash' => $hash,
					'amount' => $amount,
					'currency' => $currency,
					'email' => $email
				),
				1
			) . PHP_EOL;
			$log_file = dirname(__FILE__) . '/logs/success' . $PAYMENT_ID;
			$log_file_failed = dirname(__FILE__) . '/logs/fail/' . $PAYMENT_NO . '-' . $PAYER_WM;
			// INIT LOG FILE
			if ($FAILREQUEST == 1) {
				file_put_contents($log_file_failed, $log, FILE_APPEND);
				exit('TRANSACTION FAILED');
			} else {
				file_put_contents($log_file, $log, FILE_APPEND);
			}
			function log_and_exit($log_file, $msg)
			{
				file_put_contents($log_file, $msg, FILE_APPEND);
				exit($msg);
			}

			// VERIFY HASH
			if ($hash == $V2_HASH) {
				if ($PAYMENT_PURSE == $rcp_options['webmoney_payment_purse']) {
					if ($PAYMENT_AMOUNT == $amount) {
						$rcp_payments = new RCP_Payments();
						$pay = $rcp_payments->get_payment_by('subscription_key', $_GET['key']);
						if (empty($pay)) {
							$msg = "Found No Subscription Data";
							log_and_exit($log_file, $msg);
						}
						if ($rcp_payments->payment_exists($PAYMENT_ID)) {
							$msg = "Detected Duplicate IPN";
							log_and_exit($log_file, $msg);
						}
						/**
						 * Update payment info of rcp db
						 */
						$rcp_payments->update($pay->id, array(
							'transaction_id' => $PAYMENT_ID,
							'payment_type' => 'webmoney',
							'status' => 'complete'
						));

						/**
						 * OPERATIVE MODE
						 */
						if ($MODE == 0) {
							// Update member subscription level only when it is an active-mode
							$membership = rcp_get_membership_by('subscription_key', $pay->subscription_key);
							$membership->set_recurring(false);
							$membership->renew();
							$wpdb->update($wpdb->prefix . 'rcp_memberships', array('expiration_date' => $membership->calculate_expiration(true)), array('id' => $membership->get_id()));
							$msg = "SUCCESS";
							log_and_exit($log_file, $msg);
						}
						/**
						 * TEST MODE
						 */
						else if ($MODE == 1) {
							$msg = "TEST:SUCCESS";
							log_and_exit($log_file, $msg);
						}
						/**
						 * UNKNOWN MODE
						 */
						else {
							$msg = "UNKNOWN:SUCCESS :: " . $MODE;
							log_and_exit($log_file, $msg);
						}
					} else {
						$msg = "Does Not Match The Payment Amount";
						log_and_exit($log_file, $msg);
					}
				} else {
					$msg = "Does Not Match The Merchant Purse";
					log_and_exit($log_file, $msg);
				}
			} else {
				$msg = "RECEIVED HASH IS INVALID <IMPORTANT>";
				log_and_exit($log_file, $msg);
			}
		}
	}
}
add_action('plugins_loaded', 'rcp_webmoney_init', 11);


/**
 * Register webmoney gateway to restrict-content-pro plugin
 */
function rcp_register_webmoney_payment_gateway($gateways)
{
	return array_merge(array('webmoney' => array('label' => 'WebMoney', 'admin_label' => 'WebMoney', 'class' => 'RCP_Payment_Gateway_WebMoney')), $gateways);
}
add_filter('rcp_payment_gateways', 'rcp_register_webmoney_payment_gateway');

/**
 * Add option-fields to restrict-content-pro settings
 */
function rcp_show_webmoney_setting($rcp_options)
{
?>
	<hr />
	<table class="form-table">
		<tr valign="top">
			<th colspan=2>
				<h3> WebMoney Gateway Setting </h3>
			</th>
		</tr>
		<tr>
			<th><label for="rcp_settings[webmoney_payment_purse]"> WebMoney Purse ID </label></th>
			<td><input class="regular-text" id="rcp_settings[webmoney_payment_purse]" style="width: 300px;" name="rcp_settings[webmoney_payment_purse]" value="<?= @$rcp_options['webmoney_payment_purse'] ?>" /></td>
		</tr>
		<tr>
			<th><label for="rcp_settings[webmoney_secret]"> WebMoney Secret Key </label></th>
			<td><input class="regular-text" id="rcp_settings[webmoney_secret]" style="width: 300px;" name="rcp_settings[webmoney_secret]" value="<?= @$rcp_options['webmoney_secret'] ?>" /></td>
		</tr>
		<tr>
		<tr>
			<th><label for="rcp_settings[webmoney_payment_desc]"> WebMoney Description Text </label></th>
			<td><input class="regular-text" id="rcp_settings[webmoney_payment_desc]" style="width: 300px;" name="rcp_settings[webmoney_payment_desc]" value="<?= @$rcp_options['webmoney_payment_desc'] ?>" /></td>
		</tr>
		<tr>
			<th><label for="rcp_settings[webmoney_success]"> WebMoney Success URL </label></th>
			<td><input class="regular-text" id="rcp_settings[webmoney_success]" style="width: 300px;" name="rcp_settings[webmoney_success]" value="<?= @$rcp_options['webmoney_success'] ?>" /></td>
		</tr>
		<tr>
			<th><label for="rcp_settings[webmoney_fail]"> WebMoney Fail URL </label></th>
			<td><input class="regular-text" id="rcp_settings[webmoney_fail]" style="width: 300px;" name="rcp_settings[webmoney_fail]" value="<?= @$rcp_options['webmoney_fail'] ?>" /></td>
		</tr>
	</table>
<?php
}
add_action('rcp_payments_settings', 'rcp_show_webmoney_setting');

?>