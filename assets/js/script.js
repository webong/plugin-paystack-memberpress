jQuery(document).ready(function($) {
  $('div#integration').on('change', 'select.mepr-gateways-dropdown', function() {
    var data_id = $(this).attr('data-id');
    var gateway = $(this).val();
    var data = {
      action: 'mepr_gateway_form',
      option_nonce: MeprOptionData.option_nonce,
      g: gateway
    };
    
    $.post(ajaxurl, data, function(response) {
      console.log(response)
      if( response.error === undefined ) {
        $('#mepr-integration-'+data_id).replaceWith(response.form);
        $('.clippy').clippy({clippy_path: MeprOptions.jsUrl + '/clippy.swf', width: '14px'});
        if( gateway === 'MeprPaystackGateway' ) {
          console.log(response.id);
          
          $('#mepr-paystack-test-keys-'+response.id).show();
        }
      }
      else {
        alert('Error');
      }
    }, 'json');
    return false;
  });
  
  $('input.mepr-paystack-testmode').each( function() {
    var integration = $(this).data('integration');

    if( $(this).is(':checked') ) {
      $('#mepr-paystack-test-keys-'+integration).show();
    }
    else {
      $('#mepr-paystack-live-keys-'+integration).show();
    }
  });

  $('div#integration').on('change', 'input.mepr-paystack-testmode', function() {
    var integration = $(this).data('integration');
    if( $(this).is(':checked') ) {
      $('#mepr-paystack-live-keys-'+integration).hide();
      $('#mepr-paystack-test-keys-'+integration).show();
    }
    else {
      $('#mepr-paystack-live-keys-'+integration).show();
      $('#mepr-paystack-test-keys-'+integration).hide();
    }
  });
})