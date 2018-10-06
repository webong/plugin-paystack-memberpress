<?php
/**
 * Plugin Name: Paystack Gateway for MemberPress
 * Plugin URI: https://paystack.com
 * Description: Processes payments via Paystack for MemberPress
 * Version: 1.0.0
 * Author: Paystack
 * License: GPLv2 or later
 */
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

add_filter( 'mepr-gateway-paths', 'MeprPaystackGateway_gateway_paths' );

function MeprPaystackGateway_gateway_paths( $paths ) {
  $paths[] = dirname( __FILE__ );
  return $paths;
}

function wp_enqueue_paystack_script() {
  global $pagenow;

  if ($pagenow != 'admin.php') {
      return;
  }

  wp_enqueue_script ( 'paystack-memberpress-script', plugins_url() . '/paystack-memberpress/assets/js/script.js' );
}

add_action( 'admin_enqueue_scripts', 'wp_enqueue_paystack_script' );
add_action('parse_request', array(MeprPaystackGateway, 'handle_api_requests'), 0);

/** Lays down the interface for Gateways in MemberPress **/
class MeprPaystackGateway extends MeprBaseRealGateway {
  /** Used in the view to identify the gateway */
  public function __construct()
  {
    $this->name = __("Paystack", 'memberpress');
    $this->icon = MEPR_IMAGES_URL . '/checkout/cards.png';
    $this->desc = __('Pay with your card via Paystack', 'memberpress');
    $this->set_defaults();

    $this->capabilities = array(
      'process-payments',
      // 'create-subscriptions',
      // 'process-refunds',
      // 'cancel-subscriptions',
      // 'update-subscriptions',
      // 'suspend-subscriptions',
      // 'send-cc-expirations'
    );

    // Setup the notification actions for this gateway
    $this->notifiers = array( 'whk' => 'listener' );
  }

  // /**
	//  * Gateway paths.
	//  *
	//  * @param array $paths Array with gateway paths.
	//  * @return array
	//  */
  // public 

  public function load($settings)
  {
    $this->settings = (object)$settings;
    $this->set_defaults();
  }

  protected function set_defaults() {
    if(!isset($this->settings)) {
      $this->settings = array();
    }

    $this->settings = (object)array_merge(
      array(
        'gateway' => 'MeprPaystackGateway',
        'id' => $this->generate_id(),
        'label' => '',
        'use_label' => true,
        'use_icon' => true,
        'use_desc' => true,
        'email' => '',
        'sandbox' => false,
        'force_ssl' => false,
        'debug' => false,
        'test_mode' => false,
        'use_paystack_checkout' => false,
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
      (array)$this->settings
    );

    $this->id = $this->settings->id;
    $this->label = $this->settings->label;
    $this->use_label = $this->settings->use_label;
    $this->use_icon = $this->settings->use_icon;
    $this->use_desc = $this->settings->use_desc;
    //$this->recurrence_type = $this->settings->recurrence_type;

    if($this->is_test_mode()) {
      $this->settings->public_key = trim($this->settings->api_keys['test']['public']);
      $this->settings->secret_key = trim($this->settings->api_keys['test']['secret']);
    }
    else {
      $this->settings->public_key = trim($this->settings->api_keys['live']['public']);
      $this->settings->secret_key = trim($this->settings->api_keys['live']['secret']);
    }
  }

  /** Used to send data to a given payment gateway. In gateways which redirect
    * before this step is necessary this method should just be left blank.
    */
  public function process_payment($txn) {
    $mepr_options = MeprOptions::fetch();
    $mode = $this->settings->test_mode;
    $gateway_id = $txn->gateway;
    $ref = $_GET['reference'];

    $api_data = $mepr_options->integrations[$gateway_id];

    if ($api_data['test_mode'] == 'on'){
      $secret_key   = $api_data['api_keys']['test']['secret'];
    } else {
      $secret_key   = $api_data['api_keys']['live']['secret'];
    }

    $url = "https://api.paystack.co/transaction/verify/" . $ref;
    $args = array(
      'headers' => array(
          'Authorization' => 'Bearer ' . $secret_key,
      ),
    );
    
    $request = wp_remote_get($url, $args);
    if (is_wp_error($request)) {
        return false; // Bail early
    }
    $body = wp_remote_retrieve_body($request);
    $result = json_decode($body);
    
    $charge = $result->data;

    $txn->trans_num = $charge->reference;
    $txn->response = json_encode($charge);
    // $txn->store(); 

    $_REQUEST['data'] = $charge;

    if ($result->data->status == 'success') {
      // the transaction was successful, you can deliver value
      echo json_encode ([
        'url' => get_page_link($mepr_options->thankyou_page_id),
        'status' => 'true',
      ]);
      return $this->record_payment();
    } else {
      // the transaction was not successful, do not deliver value'
      throw new MeprGatewayException( __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') );
      return $this->record_payment_failure();
    }
  }

  /** Used to record a successful payment by the given gateway. It should have
    * the ability to record a successful payment or a failure.
    */
  public function record_payment() {

    if(isset($_REQUEST['data'])) {
      $charge = (object)$_REQUEST['data'];

      $obj = MeprTransaction::get_one_by_trans_num($charge->reference);

      if(is_object($obj) and isset($obj->id)) {
        $txn = new MeprTransaction();
        $txn->load_data($obj);
        $usr = $txn->user();

        // Just short circuit if the txn has already completed
        if($txn->status == MeprTransaction::$complete_str)
          return;

        $txn->status    = MeprTransaction::$complete_str;
        $txn->response  = $charge;

        // This will only work before maybe_cancel_old_sub is run
        $upgrade = $txn->is_upgrade();
        $downgrade = $txn->is_downgrade();

        $txn->maybe_cancel_old_sub();
        $txn->store();



        $prd = $txn->product();

        if( $prd->period_type=='lifetime' ) {
          if( $upgrade ) {
            $this->upgraded_sub($txn);
            MeprUtils::send_upgraded_txn_notices( $txn );
          }
          else if( $downgrade ) {
            $this->downgraded_sub($txn);
            MeprUtils::send_downgraded_txn_notices( $txn );
          }
          else {
            $this->new_sub($txn);
          }

          MeprUtils::send_signup_notices( $txn );
        }

        MeprUtils::send_transaction_receipt_notices( $txn );
        MeprUtils::send_cc_expiration_notices( $txn );
      }
    }

    return false;
  }

  /** This method should be used by the class to push a request to to the gateway.
    */
  public function process_refund(MeprTransaction $txn) { }

  /** This method should be used by the class to record a successful refund from
    * the gateway. This method should also be used by any IPN requests or Silent Posts.
    */
  public function record_refund() { }

  /** Used to record a successful recurring payment by the given gateway. It
    * should have the ability to record a successful payment or a failure. It is
    * this method that should be used when receiving an IPN from PayPal or a
    * Silent Post from Authorize.net.
    */
  public function record_subscription_payment() { }

  /** Used to record a declined payment. */
  public function record_payment_failure() { }

  /** Used for processing and recording one-off subscription trial payments */
  public function process_trial_payment($transaction) { }
  public function record_trial_payment($transaction) { }

  /** Used to send subscription data to a given payment gateway. In gateways
    * which redirect before this step is necessary this method should just be
    * left blank.
    */
  public function process_create_subscription($transaction) { }

  /** Used to record a successful subscription by the given gateway. It should have
    * the ability to record a successful subscription or a failure. It is this method
    * that should be used when receiving an IPN from PayPal or a Silent Post
    * from Authorize.net.
    */
  public function record_create_subscription() { }

  public function process_update_subscription($subscription_id) { }

  /** This method should be used by the class to record a successful cancellation
    * from the gateway. This method should also be used by any IPN requests or
    * Silent Posts.
    */
  public function record_update_subscription() { }

  /** Used to suspend a subscription by the given gateway.
    */
  public function process_suspend_subscription($subscription_id) { }

  /** This method should be used by the class to record a successful suspension
    * from the gateway.
    */
  public function record_suspend_subscription() { }

  /** Used to suspend a subscription by the given gateway.
    */
  public function process_resume_subscription($subscription_id) { }

  /** This method should be used by the class to record a successful resuming of
    * as subscription from the gateway.
    */
  public function record_resume_subscription() { }

  /** Used to cancel a subscription by the given gateway. This method should be used
    * by the class to record a successful cancellation from the gateway. This method
    * should also be used by any IPN requests or Silent Posts.
    */
  public function process_cancel_subscription($subscription_id) { }

  /** This method should be used by the class to record a successful cancellation
    * from the gateway. This method should also be used by any IPN requests or
    * Silent Posts.
    */
  public function record_cancel_subscription() { }

  /** Gets called when the signup form is posted used for running any payment
    * method specific actions when processing the customer signup form.
    */
  public function process_signup_form($transaction) { }

  /** Gets called on the 'init' action after the signup form is submitted. If
    * we're using an offsite payment solution like PayPal then this method
    * will just redirect to it.
    */
  public function display_payment_page($transaction) { }

  /** This gets called on wp_enqueue_script and enqueues a set of
    * scripts for use on the page containing the payment form
    */
  public function enqueue_payment_form_scripts() { }

  /** This spits out html for the payment form on the registration / payment
    * page for the user to fill out for payment.
    */

  public function display_payment_form($amount, $user, $product_id, $txn_id) {
    $mepr_options = MeprOptions::fetch();
    $prd = new MeprProduct($product_id);
    $coupon = false;

    $txn = new MeprTransaction($txn_id);

    //Artifically set the price of the $prd in case a coupon was used
    if($prd->price != $amount) {
      $coupon = true;
      $prd->price = $amount;
    }

    $invoice = MeprTransactionsHelper::get_invoice($txn);
    echo $invoice;

    ?>
      <div class="mp_wrapper mp_payment_form_wrapper">
        <?php
          // if(!$this->settings->use_stripe_checkout) {
            // $this->display_on_site_form($txn);
          // }
          // else {
            $this->display_paystack_checkout_form($txn);
          // }
        ?>
      </div>
    <?php
  }

  public static function handle_api_requests()
    {
      global $wp;
      
      if (!empty($_GET['mepr-paystack'])) { // WPCS: input var okay, CSRF ok.
          $wp->query_vars['mepr-paystack'] = sanitize_key(wp_unslash($_GET['mepr-paystack'])); // WPCS: input var okay, CSRF ok.
          $key = $wp->query_vars['mepr-paystack'];
          if ($key && ($key === 'verify') && isset($_GET['reference'])) {
              // handle verification here
              $body = $_POST["body"];
              $txn = json_decode(json_encode($body), FALSE);
              $r = new MeprPaystackGateway();
              $r->process_payment($txn);
              die();
          }
      }
    }

  public function display_paystack_checkout_form($txn) {
    $mepr_options = MeprOptions::fetch();
    $user         = $txn->user();
    $prd          = $txn->product();
    $amount       = $txn->rec;
    $mode         = $this->settings->test_mode;
    $ref          = $txn->rec->trans_num;

    if ($mode == 'on'){
      $public_key   = $this->settings->api_keys['test']['public'];
    } else {
      $public_key   = $this->settings->api_keys['live']['public'];
    }

    $verify_url = home_url()  . '?' . http_build_query(
      [
        'mepr-paystack' => 'verify',
        'reference' => $ref,
      ]
    );
    ?>
    
    <form action="" method="POST">
      <input type="hidden" name="mepr_process_payment_form" value="Y" />
      <input type="hidden" name="mepr_transaction_id" value="<?php echo $txn->id; ?>" />
      <input type="button" onclick="PayWithMeprPaystack()" value="submit"/>
      <script src="https://js.paystack.co/v1/inline.js"></script>
      <script>
        function PayWithMeprPaystack(){
          var handler = PaystackPop.setup({
            key: '<?php echo $public_key; ?>',
            ref: '<?php echo $ref ?>',
            email: '<?php echo esc_attr($user->user_email); ?>',
            amount: <?php echo esc_attr($amount->total) * 100; ?>,
            currency: '<?php echo $mepr_options->currency_code; ?>',
            metadata: {
              custom_fields: [
                  {
                      display_name: "Mobile Number",
                      variable_name: "mobile_number",
                      value: "+2348012345678"
                  }
              ]
            },
            callback: function(response){
              jQuery.ajax({
                url: '<?php echo $verify_url ?>',
                data: { 
                  'body': <?php echo $txn ?>
                },
                method: 'post',
                success: function (responseJSON) {
                  dx = JSON.parse(responseJSON);
                  console.log(dx);
                  
                  window.location.replace(dx.url);
                },
                error: function(err){
                  console.log(err);
                  window.history.back();
                }
              })
            },
            onClose: function(){
              window.history.back();
            }
          })
          handler.openIframe();
        }
      </script>
    </form>
    
    <?php
  }

  /** Validates the payment form before a payment is processed */
  public function validate_payment_form($errors) { }

  /** Displays the form for the given payment gateway on the MemberPress Options page */
  public function display_options_form() {
    $mepr_options = MeprOptions::fetch();

    $test_secret_key      = trim($this->settings->api_keys['test']['secret']);
    $test_public_key      = trim($this->settings->api_keys['test']['public']);
    $live_secret_key      = trim($this->settings->api_keys['live']['secret']);
    $live_public_key      = trim($this->settings->api_keys['live']['public']);
    $force_ssl            = ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true);
    $debug                = ($this->settings->debug == 'on' or $this->settings->debug == true);
    $test_mode            = ($this->settings->test_mode == 'on' or $this->settings->test_mode == true);
    $use_paystack_checkout  = ($this->settings->use_paystack_checkout == 'on' or $this->settings->use_paystack_checkout == true);

    ?>
    <table id="mepr-paystack-test-keys-<?php echo $this->id; ?>" class="mepr-paystack-test-keys mepr-hidden">
      <tr>
        <td><?php _e('Test Secret Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][test][secret]" value="<?php echo $test_secret_key; ?>" /></td>
      </tr>
      <tr>
        <td><?php _e('Test Public Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][test][public]" value="<?php echo $test_public_key; ?>" /></td>
      </tr>
    </table>
    <table id="mepr-paystack-live-keys-<?php echo $this->id; ?>" class="mepr-paystack-live-keys mepr-hidden">
      <tr>
        <td><?php _e('Live Secret Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][live][secret]" value="<?php echo $live_secret_key; ?>" /></td>
      </tr>
      <tr>
        <td><?php _e('Live Public Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][live][public]" value="<?php echo $live_public_key; ?>" /></td>
      </tr>
    </table>
    <table>
      <!-- <tr>
        <td colspan="2"><input class="mepr-paystack-checkout" type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][use_paystack_checkout]"<?php echo checked($use_paystack_checkout); ?> />&nbsp;<?php _e('Use Paystack Checkout (Beta)', 'memberpress'); ?></td>
      </tr> -->
      <tr>
        <td colspan="2"><input class="mepr-paystack-testmode" data-integration="<?php echo $this->id; ?>" type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][test_mode]"<?php echo checked($test_mode); ?> />&nbsp;<?php _e('Test Mode', 'memberpress'); ?></td>
      </tr>
      <tr>
        <td colspan="2"><input type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][force_ssl]"<?php echo checked($force_ssl); ?> />&nbsp;<?php _e('Force SSL', 'memberpress'); ?></td>
      </tr>
      <!-- <tr>
        <td colspan="2"><input type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][debug]"<?php echo checked($debug); ?> />&nbsp;<?php _e('Send Debug Emails', 'memberpress'); ?></td>
      </tr> -->
      <tr>
        <td><?php _e('Paystack Webhook URL:', 'memberpress'); ?></td>
        <td><input type="text" onfocus="this.select();" onclick="this.select();" readonly="true" class="clippy_input" value="<?php echo $this->notify_url('whk'); ?>" /><span class="clippy"><?php echo $this->notify_url('whk'); ?></span></td>
      </tr>
    </table>
    <?php
   }

  /** Validates the form for the given payment gateway on the MemberPress Options page */
  public function validate_options_form($errors) {
    $mepr_options = MeprOptions::fetch();

    $testmode = isset($_REQUEST[$mepr_options->integrations_str][$this->id]['test_mode']);
    $testmodestr  = $testmode ? 'test' : 'live';

    if( !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) or
         empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) or
        !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public']) or
         empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public']) ) {
      $errors[] = __("All Paystack API keys must be filled in.", 'memberpress');
    }

    return $errors;
  }

  /** Displays the update account form on the subscription account page **/
  public function display_update_account_form($subscription_id, $errors=array(), $message="") { }

  /** Validates the payment form before a payment is processed */
  public function validate_update_account_form($errors=array()) { }

  /** Actually pushes the account update to the payment processor */
  public function process_update_account_form($subscription_id) { }

  /** Returns boolean ... whether or not we should be sending in test mode or not */
  public function is_test_mode() { 
    return (isset($this->settings->test_mode) and $this->settings->test_mode);
   }

  /** Returns boolean ... whether or not we should be forcing ssl */
  public function force_ssl() { }
}
