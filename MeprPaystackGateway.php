<?php
if (!defined('ABSPATH')) {
  die('You are not allowed to call this page directly.');
}

class MeprPaystackGateway extends MeprBaseRealGateway
{
  public static $paystack_plan_id_str = '_mepr_paystack_plan_id';

  /** Used in the view to identify the gateway */
  public function __construct()
  {
    $this->name = __("Paystack", 'memberpress');
    $this->icon = MP_PAYSTACK_IMAGES_URL . '/cards.png';
    $this->desc = __('Pay via Paystack', 'memberpress');
    $this->set_defaults();
    $this->has_spc_form = true;

    $this->capabilities = array(
      'process-payments',
      'create-subscriptions',
      'suspend-subscriptions',
      'resume-subscriptions',
    );

    // Setup the notification actions for this gateway
    $this->notifiers = array(
      'whk' => 'webhook_listener',
      'callback' => 'verify_payment',
    );
  }

  public function load($settings)
  {
    $this->settings = (object) $settings;
    $this->set_defaults();
  }

  protected function set_defaults()
  {
    if (!isset($this->settings)) {
      $this->settings = array();
    }

    $this->settings = (object) array_merge(
      array(
        'gateway' => 'MeprPaystackGateway',
        'id' => $this->generate_id(),
        'label' => '',
        'use_label' => true,
        'icon' => MP_PAYSTACK_IMAGES_URL . '/paystack.png',
        'use_icon' => true,
        'use_desc' => true,
        'email' => '',
        'sandbox' => false,
        'force_ssl' => false,
        'debug' => false,
        'test_mode' => false,
        'api_keys' => array(
          'test' => array(
            'public' => '',
            'secret' => ''
          ),
          'live' => array(
            'public' => '',
            'secret' => ''
          )
        )
      ),
      (array) $this->settings
    );

    $this->id = $this->settings->id;
    $this->label = $this->settings->label;
    $this->use_label = $this->settings->use_label;
    $this->use_icon = $this->settings->use_icon;
    $this->use_desc = $this->settings->use_desc;
    $this->has_spc_form = $this->settings->use_paystack_checkout ? false : true;
    //$this->recurrence_type = $this->settings->recurrence_type;

    if ($this->is_test_mode()) {
      $this->settings->public_key = trim($this->settings->api_keys['test']['public']);
      $this->settings->secret_key = trim($this->settings->api_keys['test']['secret']);
    } else {
      $this->settings->public_key = trim($this->settings->api_keys['live']['public']);
      $this->settings->secret_key = trim($this->settings->api_keys['live']['secret']);
    }
  }

  /** Used to send data to a given payment gateway. In gateways which redirect
   * before this step is necessary this method should just be left blank.
   */
  public function process_payment($txn)
  {
    if (isset($txn) and $txn instanceof MeprTransaction) {
      $usr = $txn->user();
      $prd = $txn->product();
    } else {
      throw new MeprGatewayException(__('Payment transaction was unsuccessful, please try again.', 'memberpress'));
    }

    $mepr_options = MeprOptions::fetch();

    // Handle zero decimal currencies in Paystack
    $amount = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($txn->total), 0) : MeprUtils::format_float(($txn->total * 100), 0);

    // Get callback url
    // $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ?
    //   "https" : "http") . "://" . $_SERVER['HTTP_HOST'] .
    //   $_SERVER['REQUEST_URI'] . '&mepr_process_payment_form=1&mepr_transaction_id=' . $_REQUEST['mepr_transaction_id'];

    // Initialize the charge on Paystack's servers - this will charge the user's card
    $args = MeprHooks::apply_filters('mepr_paystack_payment_args', array(
      'amount' => $amount,
      'currency' => $mepr_options->currency_code,
      'email'   => $usr->user_email,
      'reference' => $txn->trans_num,
      'callback_url' => $this->notify_url('callback'),
      'description' => sprintf(__('%s (transaction: %s)', 'memberpress'), $prd->post_title, $txn->id),
      'metadata' => array(
        'platform' => 'MemberPress Paystack',
        'transaction_id' => $txn->id,
        'site_url' => esc_url(get_site_url()),
        'ip_address' => $_SERVER['REMOTE_ADDR']
      )
    ), $txn);

    // Initialize a new payment here
    $response = (object) $this->send_paystack_request("transaction/initialize/", $args);

    if ($response->status) {
      return MeprUtils::wp_redirect("{$response->data['authorization_url']}");
    }
    return false;
  }

  /** This method should be used by the class to verify a successful payment by the given
   * the gateway. This method should also be used by any IPN requests or Silent Posts.
   */
  public function verify_payment()
  {
    $this->email_status("Silent Post Just Came In (" . $_SERVER['REQUEST_METHOD'] . "):\n" . MeprUtils::object_to_string($_REQUEST, true) . "\n", $this->settings->debug);

    // get the transaction reference from paystack callback
    $reference = $_REQUEST['reference'];

    $this->email_status('Paystack Verify Charge Transaction Happening Now ... ' . $reference, $this->settings->debug);

    $response = (object) $this->send_paystack_request("transaction/verify/{$reference}", [], 'get');
    $_REQUEST['data'] = $charge = (object) $response->data;
    $this->email_status('Paystack Verification: ' . MeprUtils::object_to_string($charge), $this->settings->debug);

    if (!$response->status || $charge->status == 'failed') {
      $this->record_payment_failure();
    }

    // Get Transaction from paystack reference or charge id
    $obj = MeprTransaction::get_one_by_trans_num($reference);
    $txn = new MeprTransaction();
    $txn->load_data($obj);

    if ($txn->status == MeprTransaction::$pending_str) {
      $txn->status == MeprTransaction::$confirmed_str;
      $txn->store();
    }

    // Redirect to thank you page (even when payment fails)
    $mepr_options = MeprOptions::fetch();
    $product = new MeprProduct($txn->product_id);
    $sanitized_title = sanitize_title($product->post_title);
    $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
    if ($txn->subscription_id > 0) {
      $sub = $txn->subscription();
      $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
    }
    MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
  }

  /** Used to record a successful payment by the given gateway. It should have
   * the ability to record a successful payment or a failure. It is this method
   * that should be used when receiving a Paystack Webhook.
   */
  public function record_payment()
  {
    $this->email_status("Starting record_payment: " . MeprUtils::object_to_string($_REQUEST), $this->settings->debug);
    if (isset($_REQUEST['data'])) {
      $charge = (object) $_REQUEST['data'];
      $this->email_status("record_payment: \n" . MeprUtils::object_to_string($charge, true) . "\n", $this->settings->debug);
      $obj = MeprTransaction::get_one_by_trans_num($charge->reference);
      if (is_object($obj) and isset($obj->id)) {
        $txn = new MeprTransaction();
        $txn->load_data($obj);
        $usr = $txn->user();

        // Just short circuit if the txn has already completed
        if ($txn->status == MeprTransaction::$complete_str)
          return;

        $txn->status  = MeprTransaction::$complete_str;
        // This will only work before maybe_cancel_old_sub is run
        $upgrade = $txn->is_upgrade();
        $downgrade = $txn->is_downgrade();

        $event_txn = $txn->maybe_cancel_old_sub();
        $txn->store();

        $this->email_status("Standard Transaction\n" . MeprUtils::object_to_string($txn->rec, true) . "\n", $this->settings->debug);

        $prd = $txn->product();

        if ($prd->period_type == 'lifetime') {
          if ($upgrade) {
            $this->upgraded_sub($txn, $event_txn);
          } else if ($downgrade) {
            $this->downgraded_sub($txn, $event_txn);
          } else {
            $this->new_sub($txn);
          }

          MeprUtils::send_signup_notices($txn);
        }

        MeprUtils::send_transaction_receipt_notices($txn);
        // MeprUtils::send_cc_expiration_notices($txn);
        return $txn;
      }
    }

    return false;
  }

  /** Used to record a declined payment. */
  public function record_payment_failure()
  {
    if (isset($_REQUEST['data'])) {
      $charge = (object) $_REQUEST['data'];
      $txn_res = MeprTransaction::get_one_by_trans_num($_REQUEST['reference']);

      if (is_object($txn_res) and isset($txn_res->id)) {
        $txn = new MeprTransaction($txn_res->id);
        $txn->trans_num = $charge->id ?? $_REQUEST['reference'];
        $txn->status = MeprTransaction::$failed_str;
        $txn->store();
      } elseif (isset($charge) && isset($charge->customer) && ($sub = MeprSubscription::get_one_by_subscr_id($charge->customer))) {
        $first_txn = $sub->first_txn();

        if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
          $coupon_id = $sub->coupon_id;
        } else {
          $coupon_id = $first_txn->coupon_id;
        }

        $txn = new MeprTransaction();
        $txn->user_id = $sub->user_id;
        $txn->product_id = $sub->product_id;
        $txn->coupon_id = $coupon_id;
        $txn->txn_type = MeprTransaction::$payment_str;
        $txn->status = MeprTransaction::$failed_str;
        $txn->subscription_id = $sub->id;
        $txn->trans_num = $charge->id;
        $txn->gateway = $this->id;

        if (MeprUtils::is_zero_decimal_currency()) {
          $txn->set_gross((float) $charge->amount);
        } else {
          $txn->set_gross((float) $charge->amount / 100);
        }

        $txn->store();

        //If first payment fails, Paystack will not set up the subscription, so we need to mark it as cancelled in MP
        if ($sub->txn_count == 0) {
          $sub->status = MeprSubscription::$cancelled_str;
        } else {
          $sub->status = MeprSubscription::$active_str;
        }
        $sub->gateway = $this->id;
        $sub->expire_txns(); //Expire associated transactions for the old subscription
        $sub->store();
      } else {
        return false; // Nothing we can do here ... so we outta here
      }

      MeprUtils::send_failed_txn_notices($txn);

      return $txn;
    }

    return false;
  }

  /** This method should be used by the class to record a successful refund from
   * the gateway. This method should also be used by any IPN requests or Silent Posts.
   */
  public function process_refund(MeprTransaction $txn)
  {
    // $args = MeprHooks::apply_filters('mepr_paystack_refund_args', array(), $txn);
    // $refund = (object) $this->send_paystack_request("charges/{$txn->trans_num}/refund", $args);
    // $this->email_status("Paystack Refund: " . MeprUtils::object_to_string($refund), $this->settings->debug);
    // $_REQUEST['data'] = $refund;
    // return $this->record_refund();
  }

  /** This method should be used by the class to record a successful refund from
   * the gateway. This method should also be used by any IPN requests or Silent Posts.
   */
  public function record_refund()
  {
    // if (isset($_REQUEST['data'])) {
    //   $charge = (object) $_REQUEST['data'];
    //   $obj = MeprTransaction::get_one_by_trans_num($charge->id);

    //   if (!is_null($obj) && (int) $obj->id > 0) {
    //     $txn = new MeprTransaction($obj->id);

    //     // Seriously ... if txn was already refunded what are we doing here?
    //     if ($txn->status == MeprTransaction::$refunded_str) {
    //       return $txn->id;
    //     }

    //     $txn->status = MeprTransaction::$refunded_str;
    //     $txn->store();

    //     MeprUtils::send_refunded_txn_notices($txn);

    //     return $txn->id;
    //   }
    // }

    // return false;
  }

  public function process_trial_payment($txn)
  {
    $mepr_options = MeprOptions::fetch();
    $sub = $txn->subscription();

    //Prepare the $txn for the process_payment method
    $txn->set_subtotal($sub->trial_amount);
    $txn->status = MeprTransaction::$pending_str;

    var_dump($txn);
    exit;
    //Attempt processing the payment here 
    $this->process_payment($txn);

    return $this->record_trial_payment($txn);
  }

  public function record_trial_payment($txn)
  {
    $sub = $txn->subscription();

    //Update the txn member vars and store
    $txn->txn_type = MeprTransaction::$payment_str;
    $txn->status = MeprTransaction::$complete_str;
    $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($sub->trial_days), 'Y-m-d 23:59:59');
    $txn->store();

    return true;
  }

  /** Used to record a successful recurring payment by the given gateway. It
   * should have the ability to record a successful payment or a failure. It is
   * this method that should be used when receiving a Paystack Webhook.
   */
  public function record_subscription_payment()
  {
    if (isset($_REQUEST['data'])) {
      $invoice = (object) $_REQUEST['data'];

      // Make sure there's a valid subscription for this request and this payment hasn't already been recorded
      if (
        !isset($invoice) || !isset($invoice->customer) ||
        !($sub = MeprSubscription::get_one_by_subscr_id($invoice->subscription['subscription_code'])) || MeprTransaction::txn_exists($invoice->transaction['reference'])
      ) {
        return false;
      }

      //If this isn't for us, bail
      if ($sub->gateway != $this->id) {
        return false;
      }

      $first_txn = $txn = $sub->first_txn();

      if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
        $coupon_id = $sub->coupon_id;
      } else {
        $coupon_id = $first_txn->coupon_id;
      }

      $this->email_status(
        "record_subscription_payment:" .
          "\nSubscription: " . MeprUtils::object_to_string($sub, true) .
          "\nTransaction: " . MeprUtils::object_to_string($txn, true),
        $this->settings->debug
      );

      $txn = new MeprTransaction();
      $txn->user_id    = $sub->user_id;
      $txn->product_id = $sub->product_id;
      $txn->status     = MeprTransaction::$complete_str;
      $txn->coupon_id  = $coupon_id;
      $txn->trans_num  = $invoice->transaction['reference'];
      $txn->gateway    = $this->id;
      $txn->subscription_id = $sub->id;

      if (MeprUtils::is_zero_decimal_currency()) {
        $txn->set_gross((float) $invoice->amount);
      } else {
        $txn->set_gross((float) $invoice->amount / 100);
      }

      $sdata = $this->send_paystack_request("customers/{$sub->subscr_id}", array(), 'get');

      // 'subscription' attribute went away in 2014-01-31
      //$txn->expires_at = MeprUtils::ts_to_mysql_date($sdata['subscription']['current_period_end'], 'Y-m-d 23:59:59');

      $this->email_status(
        "/customers/{$sub->subscr_id}\n" .
          MeprUtils::object_to_string($sdata, true) .
          MeprUtils::object_to_string($txn, true),
        $this->settings->debug
      );

      $txn->store();

      // // Update Paystack Metadata Asynchronously
      // $job = new MeprUpdatePaystackMetadataJob();
      // $job->gateway_settings = $this->settings;
      // $job->transaction_id = $txn->id;
      // $job->enqueue();

      $sub->subscr_id = $invoice->subscription['subscription_code'];
      $sub->status = MeprSubscription::$active_str;

      if ($card = $this->get_card($invoice)) {
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year  = $card['exp_year'];
        $sub->cc_last4     = $card['last4'];
      }

      $sub->gateway = $this->id;
      $sub->store();
      // If a limit was set on the recurring cycles we need
      // to cancel the subscr if the txn_count >= limit_cycles_num
      // This is not possible natively with Paystack so we
      // just cancel the subscr when limit_cycles_num is hit
      $sub->limit_payment_cycles();

      $this->email_status(
        "Subscription Transaction\n" .
          MeprUtils::object_to_string($txn->rec, true),
        $this->settings->debug
      );

      MeprUtils::send_transaction_receipt_notices($txn);
      MeprUtils::send_cc_expiration_notices($txn);

      return $txn;
    }

    return false;
  }

  /** Used to send subscription data to a given payment gateway. In gateways
   * which redirect before this step is necessary this method should just be
   * left blank.
   */
  public function process_create_subscription($txn)
  {
    // file_put_contents('/Users/wisdomanthoni/WorkSpace/Projects/Paystack/memberpress/wp-content/plugins/memberpress-paystack/test.txt', $txn);
    if (isset($txn) and $txn instanceof MeprTransaction) {
      $usr = $txn->user();
      $prd = $txn->product();
    } else {
      throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
    }

    $mepr_options = MeprOptions::fetch();
    $sub = $txn->subscription();

    //error_log("********** MeprPaystackGateway::process_create_subscription Subscription:\n" . MeprUtils::object_to_string($sub));

    //Get the customer
    $customer = $this->paystack_customer($txn->subscription_id);
    $sub->subscr_id = $customer->customer_code;
    $sub->store();

    // Get the plan
    $plan = $this->paystack_plan($txn->subscription(), true);

    // Default to 0 for infinite occurrences
    $total_occurrences = $sub->limit_cycles ? $sub->limit_cycles_num : 0;

    $reference = $txn->trans_num;

    // if(!$prd->is_one_time_payment() && ($sub = $txn->subscription())) {
    //   $sub->token = $reference;
    //   $sub->store();
    // }

    $args = MeprHooks::apply_filters('mepr_paystack_subscription_args', array(
      'callback_url' => $this->notify_url('callback'),
      'reference' => $reference,
      'email' => $usr->user_email,
      'customer' => $customer->customer_code,
      'plan' => $plan->plan_code,
      'invoice_limit' => $total_occurrences,
      'currency' => $mepr_options->currency_code,
      "start_date" => MeprUtils::get_date_from_ts((time() + (($sub->trial) ? MeprUtils::days($sub->trial_days) : 0)), 'Y-m-d'),
      'metadata' => array(
        'platform' => 'MemberPress umINkWVxMn6uQwi4iX8pEWN36wTa0w0n',
        'transaction_id' => $txn->id,
        'subscription_id' => $sub->subscr_id,
        'site_url' => esc_url(get_site_url()),
        'ip_address' => $_SERVER['REMOTE_ADDR']
      )
    ), $txn, $sub);

    $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn, true) . "\n", $this->settings->debug);

    // $subscr = $this->send_paystack_request("subscriptions", $args);

    //error_log("********** MeprPaystackGateway::process_create_subscription altered Subscription:\n" . MeprUtils::object_to_string($sub));

    // $_REQUEST['data'] = $customer;

    // return $this->record_create_subscription();

    // Initialize a new payment here
    $response = (object) $this->send_paystack_request("transaction/initialize/", $args);
    if ($response->status) {
      return MeprUtils::wp_redirect("{$response->data['authorization_url']}");
    }
    return false;
  }

  /** Used to record a successful subscription by the given gateway. It should have
   * the ability to record a successful subscription or a failure. It is this method
   * that should be used when receiving a Paystack Webhook.
   */
  public function record_create_subscription()
  {
    $mepr_options = MeprOptions::fetch();

    if (isset($_REQUEST['data'])) {
      $sdata = (object) $_REQUEST['data'];
      //error_log("********** MeprPaystackGateway::record_create_subscription sData: \n" . MeprUtils::object_to_string($sdata));
      $sub = MeprSubscription::get_one_by_subscr_id($sdata->customer['customer_code']);
      //error_log("********** MeprPaystackGateway::record_create_subscription Subscription: \n" . MeprUtils::object_to_string($sub));
      $sub->status = MeprSubscription::$active_str;
      $sub->subscr_id = $sdata->subscription_code;

      $card = $this->get_card($sdata);
      if (!empty($card) && $card['reusable']) {
        $sub->cc_last4 = $card['last4'];
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year = $card['exp_year'];
      }

      $sub->created_at = gmdate('c');
      $sub->store();

      // This will only work before maybe_cancel_old_sub is run
      $upgrade = $sub->is_upgrade();
      $downgrade = $sub->is_downgrade();

      $event_txn = $sub->maybe_cancel_old_sub();

      $txn = $sub->first_txn();

      if ($txn == false || !($txn instanceof MeprTransaction)) {
        $txn = new MeprTransaction();
        $txn->user_id = $sub->user_id;
        $txn->product_id = $sub->product_id;
      }

      $old_total = $txn->total;

      // If no trial or trial amount is zero then we've got to make
      // sure the confirmation txn lasts through the trial
      if (!$sub->trial || ($sub->trial and $sub->trial_amount <= 0.00)) {
        $trial_days = ($sub->trial) ? $sub->trial_days : $mepr_options->grace_init_days;
        $txn->status  = MeprTransaction::$confirmed_str;
        $txn->trans_num   = $sub->subscr_id;
        $txn->txn_type   = MeprTransaction::$subscription_confirmation_str;
        $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($trial_days), 'Y-m-d 23:59:59');
        $txn->set_subtotal(0.00); // Just a confirmation txn
        $txn->store();
      }

      // $txn->set_gross($old_total); // Artificially set the subscription amount

      if ($upgrade) {
        $this->upgraded_sub($sub, $event_txn);
      } else if ($downgrade) {
        $this->downgraded_sub($sub, $event_txn);
      } else {
        $this->new_sub($sub, true);
      }

      MeprUtils::send_signup_notices($txn);

      return array('subscription' => $sub, 'transaction' => $txn);
    }

    return false;
  }

  public function process_update_subscription($sub_id)
  {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);

    //Fix for duplicate token errors when the_content is somehow run more than once despite our best efforts to avoid it
    static $subscr;
    if (!is_null($subscr)) {
      return $subscr;
    }

    if (!isset($_REQUEST['paystack-trxref'])) {
      ob_start();
      print_r($_REQUEST);
      $err = ob_get_clean();
      throw new MeprGatewayException(__('There was a problem sending your credit card details to the processor. Please try again later. 1', 'memberpress') . ' 3 ' . $err);
    }

    // get the customer
    $customer = $this->paystack_customer($sub_id);

    //TODO check for $customer === false - do better error handling here

    $usr = $sub->user();

    $args = MeprHooks::apply_filters('mepr_paystack_update_subscription_args', array(), $sub);

    $subscr = (object) $this->send_paystack_request("customers/{$customer->id}", $args, 'post');
    $sub->subscr_id = $subscr->id;

    if ($card = $this->get_card($subscr)) {
      $sub->cc_last4 = $card['last4'];
      $sub->cc_exp_month = $card['exp_month'];
      $sub->cc_exp_year = $card['exp_year'];
    }

    $sub->store();

    return $subscr;
  }

  /** This method should be used by the class to record a successful cancellation
   * from the gateway. This method should also be used by any IPN requests or
   * Silent Posts.
   */
  public function record_update_subscription()
  {
    // No need for this one with paystack
  }

  /** Used to suspend a subscription by the given gateway.
   */
  public function process_suspend_subscription($sub_id)
  {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);

    // If there's not already a customer then we're done here
    if (!($customer = $this->paystack_customer($sub_id))) {
      return false;
    }

    $args = MeprHooks::apply_filters('mepr_paystack_suspend_subscription_args', array(), $sub);

    // Yeah ... we're cancelling here bro ... with paystack we should be able to restart again
    $res = $this->send_paystack_request("customers/{$customer->id}/subscription", $args, 'delete');
    $_REQUEST['data'] = $res;

    return $this->record_suspend_subscription();
  }

  /** This method should be used by the class to record a successful suspension
   * from the gateway.
   */
  public function record_suspend_subscription()
  {
    if (isset($_REQUEST['data'])) {
      $sdata = (object) $_REQUEST['data'];
      if ($sub = MeprSubscription::get_one_by_subscr_id($sdata->customer)) {
        // Seriously ... if sub was already cancelled what are we doing here?
        if ($sub->status == MeprSubscription::$suspended_str) {
          return $sub;
        }

        $sub->status = MeprSubscription::$suspended_str;
        $sub->store();

        MeprUtils::send_suspended_sub_notices($sub);
      }
    }

    return false;
  }

  /** Used to suspend a subscription by the given gateway.
   */
  public function process_resume_subscription($sub_id)
  {
    $mepr_options = MeprOptions::fetch();
    MeprHooks::do_action('mepr-pre-paystack-resume-subscription', $sub_id); //Allow users to change the subscription programatically before resuming it
    $sub = new MeprSubscription($sub_id);

    $customer = $this->paystack_customer($sub_id);

    //Set enough of the $customer data here to get this resumed
    if (empty($customer)) {
      $customer = (object) array('id' => $sub->subscr_id);
    }

    $orig_trial        = $sub->trial;
    $orig_trial_days   = $sub->trial_days;
    $orig_trial_amount = $sub->trial_amount;

    if ($sub->is_expired() and !$sub->is_lifetime()) {
      $expiring_txn = $sub->expiring_txn();

      // if it's already expired with a real transaction
      // then we want to resume immediately
      if (
        $expiring_txn != false && $expiring_txn instanceof MeprTransaction &&
        $expiring_txn->status != MeprTransaction::$confirmed_str
      ) {
        $sub->trial = false;
        $sub->trial_days = 0;
        $sub->trial_amount = 0.00;
        $sub->store();
      }
    } else {
      $sub->trial = true;
      $sub->trial_days = MeprUtils::tsdays(strtotime($sub->expires_at) - time());
      $sub->trial_amount = 0.00;
      $sub->store();
    }

    // Create new plan with optional trial in place ...
    $plan = $this->paystack_plan($sub, true);

    $sub->trial        = $orig_trial;
    $sub->trial_days   = $orig_trial_days;
    $sub->trial_amount = $orig_trial_amount;
    $sub->store();

    $args = MeprHooks::apply_filters('mepr_paystack_resume_subscription_args', array('plan' => $plan->id, 'tax_percent' => MeprUtils::format_float($sub->tax_rate)), $sub);

    $this->email_status(
      "process_resume_subscription: \n" .
        MeprUtils::object_to_string($sub, true) . "\n",
      $this->settings->debug
    );

    $subscr = $this->send_paystack_request("customers/{$sub->subscr_id}/subscription", $args, 'post');

    $_REQUEST['data'] = $customer;
    return $this->record_resume_subscription();
  }

  /** This method should be used by the class to record a successful resuming of
   * as subscription from the gateway.
   */
  public function record_resume_subscription()
  {
    if (isset($_REQUEST['data'])) {
      $mepr_options = MeprOptions::fetch();

      $sdata = (object) $_REQUEST['data'];
      $sub = MeprSubscription::get_one_by_subscr_id($sdata->id);
      $sub->status = MeprSubscription::$active_str;

      if ($card = $this->get_card($sdata)) {
        $sub->cc_last4 = $card['last4'];
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year = $card['exp_year'];
      }

      $sub->store();

      //Check if prior txn is expired yet or not, if so create a temporary txn so the user can access the content immediately
      $prior_txn = $sub->latest_txn();
      if ($prior_txn == false || !($prior_txn instanceof MeprTransaction) || strtotime($prior_txn->expires_at) < time()) {
        $txn = new MeprTransaction();
        $txn->subscription_id = $sub->id;
        $txn->trans_num  = $sub->subscr_id . '-' . uniqid();
        $txn->status     = MeprTransaction::$confirmed_str;
        $txn->txn_type   = MeprTransaction::$subscription_confirmation_str;
        $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days(0), 'Y-m-d 23:59:59');
        $txn->set_subtotal(0.00); // Just a confirmation txn
        $txn->store();
      }

      MeprUtils::send_resumed_sub_notices($sub);

      return array('subscription' => $sub, 'transaction' => (isset($txn)) ? $txn : $prior_txn);
    }

    return false;
  }

  /** Used to cancel a subscription by the given gateway. This method should be used
   * by the class to record a successful cancellation from the gateway. This method
   * should also be used by any IPN requests or Silent Posts.
   */
  public function process_cancel_subscription($sub_id)
  {
    // $mepr_options = MeprOptions::fetch();
    // $sub = new MeprSubscription($sub_id);

    // If there's not already a customer then we're done here
    if (!($customer = $this->paystack_customer($sub_id))) {
      return false;
    }

    // $args = MeprHooks::apply_filters('mepr_paystack_cancel_subscription_args', array(), $sub);
    // $res = $this->send_paystack_request("customers/{$customer->id}/subscription", $args, 'delete');

    $_REQUEST['data']['customer'] = (object) $customer;

    return $this->record_cancel_subscription();
  }

  /** This method should be used by the class to record a successful cancellation
   * from the gateway. This method should also be used by any IPN requests or
   * Silent Posts.
   */
  public function record_cancel_subscription()
  {
    if (isset($_REQUEST['data'])) {
      $sdata = (object) $_REQUEST['data'];
      if ($sub = MeprSubscription::get_one_by_subscr_id($sdata->customer->customer_code)) {
        // Seriously ... if sub was already cancelled what are we doing here?
        // Also, for paystack, since a suspension is only slightly different
        // than a cancellation, we kick it into high gear and check for that too

        if (
          $sub->status == MeprSubscription::$cancelled_str or
          $sub->status == MeprSubscription::$suspended_str
        ) {
          return $sub;
        }

        $sub->status = MeprSubscription::$cancelled_str;
        $sub->store();

        if (isset($_REQUEST['expire']))
          $sub->limit_reached_actions();

        if (!isset($_REQUEST['silent']) || ($_REQUEST['silent'] == false))
          MeprUtils::send_cancelled_sub_notices($sub);
      }
    }

    return false;
  }

  /** This gets called on the 'init' hook when the signup form is processed ...
   * this is in place so that payment solutions like paypal can redirect
   * before any content is rendered.
   */
  public function process_signup_form($txn)
  {
    //if($txn->amount <= 0.00) {
    //  MeprTransaction::create_free_transaction($txn);
    //  return;
    //}
  }

  public function display_payment_page($txn)
  {
    // Nothing to do here ...
  }

  /** This gets called on wp_enqueue_script and enqueues a set of
   * scripts for use on the page containing the payment form
   */
  public function enqueue_payment_form_scripts()
  { }

  /** This gets called on the_content and just renders the payment form
   */
  public function display_payment_form($amount, $user, $product_id, $txn_id)
  {
    $mepr_options = MeprOptions::fetch();
    $prd = new MeprProduct($product_id);
    $coupon = false;

    $txn = new MeprTransaction($txn_id);

    //Artifically set the price of the $prd in case a coupon was used
    if ($prd->price != $amount) {
      $coupon = true;
      $prd->price = $amount;
    }

    $invoice = MeprTransactionsHelper::get_invoice($txn);
    echo $invoice;
    ?>
    <div class="mp_wrapper mp_payment_form_wrapper">
      <?php
          $this->display_on_site_form($txn);
          // if ($txn->is_one_time_payment()) {
          //   $this->display_paystack_checkout_form($txn);
          // } else {
          //   $this->display_on_site_form($txn);
          // }
          ?>
    </div>
  <?php
    }

    public function display_paystack_checkout_form($txn)
    {
      $mepr_options = MeprOptions::fetch();
      $user         = $txn->user();
      $prd          = $txn->product();
      $amount       = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($txn->total), 0) : MeprUtils::format_float(($txn->total * 100), 0);
      //Adjust for trial periods/coupons
      if (($sub = $txn->subscription()) && $sub->trial) {
        $amount = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($sub->trial_amount), 0) : MeprUtils::format_float(($sub->trial_amount * 100), 0);
      }
      ?>
    <form action="" method="POST">
      <input type="hidden" name="mepr_process_payment_form" value="Y" />
      <input type="hidden" name="mepr_transaction_id" value="<?php echo $txn->id; ?>" />
      <script src="https://js.paystack.co/v1/inline.js" class="paystack-button" data-amount="<?php echo $amount; ?>" data-reference="<?php echo $txn->id; ?>" data-key="<?php echo $this->settings->public_key; ?>" data-image="<?php echo MeprHooks::apply_filters('mepr-paystack-checkout-data-image-url', '', $txn); ?>" data-name="<?php echo esc_attr($prd->post_title); ?>" data-panel-label="<?php _ex('Submit', 'ui', 'memberpress'); ?>" data-label="<?php _ex('Pay Now', 'ui', 'memberpress'); ?>" data-billing-address="<?php echo ($mepr_options->show_address_fields && $mepr_options->require_address_fields) ? 'true' : 'false'; ?>" data-email="<?php echo esc_attr($user->user_email); ?>" data-first_name="<?php echo esc_attr($user->first_name); ?>" data-lastname="<?php echo esc_attr($user->last_name); ?>" data-currency="<?php echo $mepr_options->currency_code; ?>" data-locale="<?php echo $mepr_options->language_code; ?>">
      </script>
    </form>
  <?php
    }

    public function display_on_site_form($txn)
    {
      ?>
    <div class="mp_wrapper mp_payment_form_wrapper">
      <?php MeprView::render('/shared/errors', get_defined_vars()); ?>
      <form action="" method="post" id="mepr_paystack_payment_form" class="mepr-checkout-form mepr-form mepr-card-form" novalidate>
        <input type="hidden" name="mepr_process_payment_form" value="Y" />
        <input type="hidden" name="mepr_transaction_id" value="<?php echo $txn->id; ?>" />

        <?php MeprHooks::do_action('mepr-paystack-payment-form', $txn); ?>
        <div class="mepr_spacer">&nbsp;</div>

        <input type="submit" class="mepr-submit" value="<?php _e('Pay Now', 'memberpress'); ?>" />
        <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
        <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
      </form>
    </div>
  <?php
    }

    public function process_payment_form($txn)
    {
      // $prd = $txn->product();
      // var_dump($prd->is_one_time_payment());
      // exit;
      //Call the parent to handle the rest of this
      parent::process_payment_form($txn);
    }

    /** Validates the payment form before a payment is processed */
    public function validate_payment_form($errors)
    {
      // This is done in the javascript with Paystack
    }

    /** Displays the form for the given payment gateway on the MemberPress Options page */
    public function display_options_form()
    {
      $mepr_options = MeprOptions::fetch();

      $test_secret_key      = trim($this->settings->api_keys['test']['secret']);
      $test_public_key      = trim($this->settings->api_keys['test']['public']);
      $live_secret_key      = trim($this->settings->api_keys['live']['secret']);
      $live_public_key      = trim($this->settings->api_keys['live']['public']);
      $force_ssl            = ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true);
      $debug                = ($this->settings->debug == 'on' or $this->settings->debug == true);
      $test_mode            = ($this->settings->test_mode == 'on' or $this->settings->test_mode == true);
      $use_paystack_checkout  = ($this->settings->use_paystack_checkout == 'on' or $this->settings->use_paystack_checkout == true);
      $churn_buster_enabled = ($this->settings->churn_buster_enabled == 'on' or $this->settings->churn_buster_enabled == true);
      $churn_buster_uuid    = trim($this->settings->churn_buster_uuid);

      $test_secret_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][test][secret]";
      $test_public_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][test][public]";
      $live_secret_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][live][secret]";
      $live_public_key_str      = "{$mepr_options->integrations_str}[{$this->id}][api_keys][live][public]";
      $force_ssl_str            = "{$mepr_options->integrations_str}[{$this->id}][force_ssl]";
      $debug_str                = "{$mepr_options->integrations_str}[{$this->id}][debug]";
      $test_mode_str            = "{$mepr_options->integrations_str}[{$this->id}][test_mode]";
      $use_paystack_checkout_str  = "{$mepr_options->integrations_str}[{$this->id}][use_paystack_checkout]";
      $churn_buster_enabled_str = "{$mepr_options->integrations_str}[{$this->id}][churn_buster_enabled]";
      $churn_buster_uuid_str    = "{$mepr_options->integrations_str}[{$this->id}][churn_buster_uuid]";

      ?>
    <table id="mepr-paystack-test-keys-<?php echo $this->id; ?>" class="form-table mepr-paystack-test-keys mepr-hidden">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $test_public_key_str; ?>"><?php _e('Test Public Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $test_public_key_str; ?>" value="<?php echo $test_public_key; ?>" /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $test_secret_key_str; ?>"><?php _e('Test Secret Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $test_secret_key_str; ?>" value="<?php echo $test_secret_key; ?>" /></td>
        </tr>
      </tbody>
    </table>
    <table id="mepr-paystack-live-keys-<?php echo $this->id; ?>" class="form-table mepr-paystack-live-keys">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $live_public_key_str; ?>"><?php _e('Live Public Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $live_public_key_str; ?>" value="<?php echo $live_public_key; ?>" /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $live_secret_key_str; ?>"><?php _e('Live Secret Key*:', 'memberpress'); ?></label></th>
          <td><input type="text" class="mepr-auto-trim" name="<?php echo $live_secret_key_str; ?>" value="<?php echo $live_secret_key; ?>" /></td>
        </tr>
      </tbody>
    </table>
    <table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $test_mode_str; ?>"><?php _e('Test Mode', 'memberpress'); ?></label></th>
          <td><input class="mepr-paystack-testmode" data-integration="<?php echo $this->id; ?>" type="checkbox" name="<?php echo $test_mode_str; ?>" <?php echo checked($test_mode); ?> /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $force_ssl_str; ?>"><?php _e('Force SSL', 'memberpress'); ?></label></th>
          <td><input type="checkbox" name="<?php echo $force_ssl_str; ?>" <?php echo checked($force_ssl); ?> /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="<?php echo $debug_str; ?>"><?php _e('Send Debug Emails', 'memberpress'); ?></label></th>
          <td><input type="checkbox" name="<?php echo $debug_str; ?>" <?php echo checked($debug); ?> /></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label><?php _e('Paystack Webhook URL:', 'memberpress'); ?></label></th>
          <td><?php MeprAppHelper::clipboard_input($this->notify_url('whk')); ?></td>
        </tr>
      </tbody>
    </table>
  <?php
    }

    /** Validates the form for the given payment gateway on the MemberPress Options page */
    public function validate_options_form($errors)
    {
      $mepr_options = MeprOptions::fetch();

      $testmode = isset($_REQUEST[$mepr_options->integrations_str][$this->id]['test_mode']);
      $testmodestr  = $testmode ? 'test' : 'live';

      if (
        !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) or
        empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) or
        !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public']) or
        empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public'])
      ) {
        $errors[] = __("All Paystack keys must be filled in.", 'memberpress');
      }

      return $errors;
    }

    /** This gets called on wp_enqueue_script and enqueues a set of
     * scripts for use on the front end user account page.
     */
    public function enqueue_user_account_scripts()
    {
      // $sub = (isset($_GET['action']) && $_GET['action'] == 'update' && isset($_GET['sub'])) ? new MeprSubscription((int) $_GET['sub']) : false;
      // if ($sub !== false && $sub->gateway == $this->id) {
      //   wp_enqueue_script('paystack-js', 'https://js.paystack.com/v3/', array(), MEPR_VERSION . time());
      //   wp_enqueue_script('paystack-account-create-token', MEPR_GATEWAYS_URL . '/paystack/account_create_token.js', array('paystack-js'), MEPR_VERSION . time());
      //   wp_localize_script('paystack-account-create-token', 'MeprPaystackGateway', array('public_key' => $this->settings->public_key));
      // }
    }

    /** Displays the update account form on the subscription account page **/
    public function display_update_account_form($sub_id, $errors = array(), $message = '')
    {
      $mepr_options = MeprOptions::fetch();
      $customer = $this->paystack_customer($sub_id);
      $sub = new MeprSubscription($sub_id);
      $user = $sub->user();

      $cc_exp_month = isset($_REQUEST['card-expiry-month']) ? $_REQUEST['card-expiry-month'] : $sub->cc_exp_month;
      $cc_exp_year = isset($_REQUEST['card-expiry-year']) ? $_REQUEST['card-expiry-year'] : $sub->cc_exp_year;

      if ($card = $this->get_default_card($customer, $sub)) {
        $card_num = MeprUtils::cc_num($card['last4']);
        $card_name = (isset($card['name']) and $card['name'] != 'undefined') ? $card['name'] : $user->get_full_name();
      } else {
        $card_num = $sub->cc_num();
        $card_name = $user->get_full_name();
      }

      ?>
    <div class="mp_wrapper">
      <form action="" method="post" id="mepr-paystack-payment-form">
        <input type="hidden" name="_mepr_nonce" value="<?php echo wp_create_nonce('mepr_process_update_account_form'); ?>" />
        <input type="hidden" name="card-name" value="<?php echo $card_name; ?>" />
        <input type="hidden" name="address_required" value="<?php echo $mepr_options->show_address_fields && $mepr_options->require_address_fields ? 1 : 0 ?>" />

        <?php if ($mepr_options->show_address_fields && $mepr_options->require_address_fields) : ?>
          <input type="hidden" name="card-address-1" value="<?php echo get_user_meta($user->ID, 'mepr-address-one', true); ?>" />
          <input type="hidden" name="card-address-2" value="<?php echo get_user_meta($user->ID, 'mepr-address-two', true); ?>" />
          <input type="hidden" name="card-city" value="<?php echo get_user_meta($user->ID, 'mepr-address-city', true); ?>" />
          <input type="hidden" name="card-state" value="<?php echo get_user_meta($user->ID, 'mepr-address-state', true); ?>" />
          <input type="hidden" name="card-zip" value="<?php echo get_user_meta($user->ID, 'mepr-address-zip', true); ?>" />
          <input type="hidden" name="card-country" value="<?php echo get_user_meta($user->ID, 'mepr-address-country', true); ?>" />
        <?php endif; ?>


        <div class="mepr_update_account_table">
          <div><strong><?php _e('Update your Credit Card information below', 'memberpress'); ?></strong></div><br />
          <div class="mepr-paystack-errors"></div>
          <?php MeprView::render('/shared/errors', get_defined_vars()); ?>

          <div class="mp-form-row">
            <div class="mp-form-label">
              <label><?php _ex('Credit Card', 'ui', 'memberpress'); ?></label>
              <span id="card-errors" role="alert" class="paystack_element_error"></span>
            </div>
            <div id="card-element" class="paystack_element_input">
              <!-- a Paystack Element will be inserted here. -->
            </div>
          </div>

          <div class="mepr_spacer">&nbsp;</div>
          <input type="submit" class="mepr-submit" value="<?php _ex('Submit', 'ui', 'memberpress'); ?>" />
          <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />

          <noscript>
            <p class="mepr_nojs"><?php _e('Javascript is disabled in your browser. You will not be able to complete your purchase until you either enable JavaScript in your browser, or switch to a browser that supports it.', 'memberpress'); ?></p>
          </noscript>
        </div>
      </form>
    </div>
<?php
  }

  /** Validates the payment form before a payment is processed */
  public function validate_update_account_form($errors = array())
  {
    return $errors;
  }

  /** Used to update the credit card information on a subscription by the given gateway.
   * This method should be used by the class to record a successful cancellation from
   * the gateway. This method should also be used by any IPN requests or Silent Posts.
   */
  public function process_update_account_form($sub_id)
  {
    $this->process_update_subscription($sub_id);
  }

  /** Returns boolean ... whether or not we should be sending in test mode or not */
  public function is_test_mode()
  {
    return (isset($this->settings->test_mode) and $this->settings->test_mode);
  }

  public function force_ssl()
  {
    return (isset($this->settings->force_ssl) and ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true));
  }

  /** Get the renewal base date for a given subscription. This is the date MemberPress will use to calculate expiration dates.
   * Of course this method is meant to be overridden when a gateway requires it.
   */
  public function get_renewal_base_date(MeprSubscription $sub)
  {
    global $wpdb;
    $mepr_db = MeprDb::fetch();

    $q = $wpdb->prepare(
      "
        SELECT e.created_at
          FROM {$mepr_db->events} AS e
         WHERE e.event='subscription-resumed'
           AND e.evt_id_type='subscriptions'
           AND e.evt_id=%d
         ORDER BY e.created_at DESC
         LIMIT 1
      ",
      $sub->id
    );

    $renewal_base_date = $wpdb->get_var($q);
    if (!empty($renewal_base_date)) {
      return $renewal_base_date;
    }

    return $sub->created_at;
  }

  /** Paystack SPECIFIC METHODS **/
  public function webhook_listener()
  {
    $this->email_status("Webhook Just Came In (" . $_SERVER['REQUEST_METHOD'] . "):\n" . MeprUtils::object_to_string($_REQUEST, true) . "\n", $this->settings->debug);
    // retrieve the request's body
    $request = @file_get_contents('php://input');

    if ($this->validate_webhook($request) == true) {
      // parse it as JSON
      $request = (object) json_decode($request, true);
      $_REQUEST['data'] = $obj = (object) $request->data;

      if ($request->event == 'charge.success') {
        $this->email_status("###Event: {$request->event}\n" . MeprUtils::object_to_string($request, true) . "\n", $this->settings->debug);

        // if(isset($obj->metadata['invoice_action'])) {
        //   $this->record_subscription_payment();
        // }

        if (isset($obj->id) && empty($obj->plan)) {
          $this->record_payment();
        }

        // } else if ($request->event == 'charge.refunded') {
        //   $this->record_refund();
      } else if ($request->event == 'subscription.create') {
        $this->record_create_subscription(); // done on page
      } else if ($request->event == 'subscription.enable') {
        $this->record_update_subscription(); // done on page
      } else if ($request->event == 'subscription.disable') {
        $this->record_cancel_subscription();
      } else if ($request->event == 'invoice.create' || $request->event == 'invoice.update') {
        if (!$request->data['paid']) return;
        $this->record_subscription_payment();
      }
      // else if ($request->event == 'invoice.payment_failed') {
      //   $this->record_payment_failure();
      // } 
    }
  }

  public function validate_webhook($input)
  {
    return $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] == hash_hmac('sha512', $input, $this->settings->secret_key);
  }

  // Originally I thought these should be associated with
  // our membership objects but now I realize they should be
  // associated with our subscription objects
  public function paystack_plan($sub, $is_new = false)
  {
    try {
      if ($is_new) {
        $paystack_plan = $this->create_new_plan($sub);
      } else {
        $plan_code = $this->get_plan_code($sub);
        if (empty($plan_code)) {
          $paystack_plan = $this->create_new_plan($sub);
        } else {
          $paystack_plan = $this->send_paystack_request("plan/{$plan_code}", array(), 'get');
        }
      }
    } catch (Exception $e) {
      // The call resulted in an error ... meaning that
      // there's no plan like that so let's create one

      // Don't enclose this in try/catch ... we want any errors to bubble up
      $paystack_plan = $this->create_new_plan($sub);
    }

    return (object) $paystack_plan->data;
  }

  public function get_plan_code($sub)
  {
    $meta_plan_code = $sub->token;

    if (is_null($meta_plan_code)) {
      return $sub->id;
    } else {
      return $meta_plan_code;
    }
  }

  public function create_new_plan($sub)
  {
    $mepr_options = MeprOptions::fetch();
    $prd          = $sub->product();
    $user         = $sub->user();

    // There's no plan like that so let's create one
    if ($sub->period_type == 'months')
      $interval = 'monthly';
    else if ($sub->period_type == 'years')
      $interval = 'yearly';
    else if ($sub->period_type == 'weeks')
      $interval = 'weekly';

    //Handle zero decimal currencies in Paystack
    $amount = (MeprUtils::is_zero_decimal_currency()) ? MeprUtils::format_float(($sub->price), 0) : MeprUtils::format_float(($sub->price * 100), 0);

    $args = MeprHooks::apply_filters('mepr_paystack_create_plan_args', array(
      'amount' => $amount,
      'interval' => $interval,
      'invoice_limit' => $sub->limit_cycles,
      'name' => "{$prd->post_title} (User Id {$user->ID})",
      'currency' => $mepr_options->currency_code,
      'description' => substr(str_replace(array("'", '"', '<', '>', '$', '®'), '', get_option('blogname')), 0, 21) //Get rid of invalid chars
    ), $sub);

    if ($sub->trial) {
      $args = array_merge(array("trial_period_days" => $sub->trial_days), $args);
    }

    $paystack_plan = (object) $this->send_paystack_request('plan', $args);

    $sub->token = $paystack_plan->data['plan_code'];
    $sub->store();

    return $paystack_plan;
  }

  public function paystack_customer($sub_id)
  {
    // $mepr_options     = MeprOptions::fetch();
    $sub              = new MeprSubscription($sub_id);
    $user             = $sub->user();
    $paystack_customer  = null;
    $uid              = uniqid();
    $paystack_args = MeprHooks::apply_filters('mepr_paystack_customer_args', array(
      'email' => $user->user_email,
      'first_name' => $user->first_name,
      'last_name' => $user->last_name,
    ), $sub);

    $this->email_status("###{$uid} Paystack Customer (should be blank at this point): \n" . MeprUtils::object_to_string($paystack_customer, true) . "\n", $this->settings->debug);
    if (strpos($sub->subscr_id, 'CUS_') === 0) {
      $paystack_customer = (object) $this->send_paystack_request('customer/' . $sub->subscr_id . '/', $paystack_args, 'put');

      if ($paystack_customer->status == false) {
        return false;
      }
    } else {
      $paystack_customer = (object) $this->send_paystack_request('customer', $paystack_args);
      if ($paystack_customer->status == false) {
        return false;
      }
      $sub->subscr_id = $paystack_customer->data['customer_code'];
      $sub->store();
    }
    $this->email_status("###{$uid} Paystack Customer (should not be blank at this point): \n" . MeprUtils::object_to_string($paystack_customer, true) . "\n", $this->settings->debug);

    return (object) $paystack_customer->data;
  }

  public function get_auth_token($sub)
  {
    $user = $sub->user();
    return get_user_meta($user->ID, 'mepr_paystack_auth_token', false);
  }

  public function set_auth_token($sub, $auth)
  {
    $user = $sub->user();
    $auth_token = $auth->authorization_code;
    return update_user_meta($user->ID, 'mepr_paystack_auth_token', $auth_token);
  }

  public function send_paystack_request(
    $endpoint,
    $args = array(),
    $method = 'post',
    $domain = 'https://api.paystack.co/',
    $blocking = true,
    $idempotency_key = false
  ) {
    $mepr_options = MeprOptions::fetch();
    $uri = "{$domain}{$endpoint}";

    $args = MeprHooks::apply_filters('mepr_paystack_request_args', $args);

    $arg_array = array(
      'method'    => strtoupper($method),
      'body'      => $args,
      'timeout'   => 15,
      'blocking'  => $blocking,
      'sslverify' => $mepr_options->sslverify,
      'headers'   => $this->get_headers()
    );

    if (false !== $idempotency_key) {
      $arg_array['headers']['Idempotency-Key'] = $idempotency_key;
    }

    $arg_array = MeprHooks::apply_filters('mepr_paystack_request', $arg_array);

    // $uid = uniqid();
    // $this->email_status("###{$uid} Paystack Call to {$uri} API Key: {$this->settings->secret_key}\n" . MeprUtils::object_to_string($arg_array, true) . "\n", $this->settings->debug);

    $resp = wp_remote_request($uri, $arg_array);

    // If we're not blocking then the response is irrelevant
    // So we'll just return true.
    if ($blocking == false)
      return true;

    if (is_wp_error($resp)) {
      throw new MeprHttpException(sprintf(__('You had an HTTP error connecting to %s', 'memberpress'), $this->name));
    } else {
      if (null !== ($json_res = json_decode($resp['body'], true))) {
        //$this->email_status("###{$uid} Paystack Response from {$uri}\n" . MeprUtils::object_to_string($json_res, true) . "\n", $this->settings->debug);
        if (isset($json_res['error']) || $json_res['status'] == false)
          throw new MeprRemoteException("{$json_res['message']}");
        else
          return $json_res;
      } else // Un-decipherable message
        throw new MeprRemoteException(sprintf(__('There was an issue with the payment processor. Try again later.', 'memberpress'), $this->name));
    }

    return false;
  }

  /** Get the default card object from a subscribed customer response */
  public function get_default_card($data, $sub)
  {
    $data = (object) $data; // ensure we're dealing with a stdClass object
    $default_card_token = $this->get_auth_token($sub);

    if (isset($default_card_token)) {
      foreach ($data->authorizations as $authorization) {
        if ($authorization['authorization_code'] == $default_card_token && $authorization['channel'] == 'card') {
          return $authorization;
        }
      }
    }
    return false;
  }


  /** Get card object from a subscription charge response */
  public function get_card($data)
  {
    if (isset($data->authorization) && $data->authorization['channel'] == 'card') {
      return $data->authorization;
    }
  }

  /**
   * Generates the headers to pass to API request.
   */
  public function get_headers()
  {
    return apply_filters(
      'mepr_paystack_request_headers',
      [
        'Authorization' => "Bearer {$this->settings->secret_key}",
      ]
    );
  }
}
