<?php
/*
Plugin Name: Royal Coffee WooCommerce-Sage Payment Gateway
Description: Extends WooCommerce with a Sage payment gateway.
Version: 1.0
Author: Sean Newby
*/

//add_action( 'plugins_loaded', 'woocommerce_sage_gateway_init' );
add_action( 'init', 'woocommerce_sage_gateway_init' );
function woocommerce_sage_gateway_init() {
  // check that WP_Payment_Gateway and SageHandler classes are loaded
  if ( ! class_exists( 'WC_Payment_Gateway' ) || ! class_exists( 'SageHandler' ) ) {
    return;
  }

  // localisation
	load_plugin_textdomain( 'wc-sage-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

  // show Credit Card Logos on form
  add_action( 'woocommerce_credit_card_form_start', function( $gateway_id ){

  	echo do_shortcode( '[woocommerce_accepted_payment_methods]' );

  } );

  // add Sage Gateway to WooCommerce
  add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_sage_gateway' );
  function woocommerce_add_sage_gateway( $methods ) {
    $methods[] = 'WC_Sage_Gateway';
    return $methods;
  }

  // Sage Payment Gateway
  class WC_Sage_Gateway extends WC_Payment_Gateway_CC {
    // global id for payment method
    public $id;
    // title shown on the top of the payment gateways gage
    public $method_title;
    // description for payment gateway
    public $method_description;
    // title used for vertical tabs
    public $title;
    // gateway icon for frontend use
    public $icon;
    // payment fields on checkout page
    public $has_fields;
    // suports
    public $supports;

    // construct
    public function __construct() {
      // set class variables
      $this->id                 = 'sage';
      $this->method_title       = __( 'Sage', 'wc-sage-gateway' );
      $this->method_description = __( 'Sage Payment Gateway Plug-in for WooCommerce', 'wc-sage-gateway' );
      $this->title              = __( 'Sage', 'wc-sage-gateway' );
      $this->icon               = null;
      $this->has_fields         = true;
      $this->supports           = array( 'default_credit_card_form' );

      // define settings
      $this->init_form_fields();

      // load settings
      $this->init_settings();

      // turn these settings into variables we can use
    	foreach ( $this->settings as $setting_key => $value ) {
    		$this->$setting_key = $value;
    	}

      // check for SSL
      do_action( 'admin_notices', array( &$this, 'do_ssl_check' ) );

		add_filter( 'woocommerce_credit_card_form_fields', array( $this, 'credit_card_form_fields' ), 10, 2 );
      // save settings
      if ( is_admin() ) {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
      }
    }
    // add credit card name to payment form
    function credit_card_form_fields( $fields, $id ) {
      $credit_card_name = '
		<p class="form-row form-row-wide">
			<label for="' . $id . '-card-name">Card Holder Name <span class="required">*</span></label>
			<input id="' . $id . '-card-name" class="input-text wc-credit-card-form-card-name" type="text" name="' . $id . '-card-name"  />
		</p>';

      return array_merge( array( 'card-name-field' => $credit_card_name ), $fields );
    }

    // build the administration fields for gateway
    public function init_form_fields() {
      $this->form_fields = array(
        'enabled' => array(
          'title'     => __( 'Enable/ Disable', 'wc-sage-gateway' ),
          'label'     => __( 'Enable this payment gateway', 'wc-sage-gateway' ),
          'type'      => 'checkbox',
          'default'   => 'no'
        ),
        'title' => array(
          'title'     => __( 'Title', 'wc-sage-gateway' ),
          'type'      => 'text',
          'desc_tip'  => __( 'Payment title the customer will see during the checkout process.', 'wc-sage-gateway' ),
          'default'   => __( 'Credit card', 'wc-sage-gateway' )
        ),
        'description' => array(
          'title'     => __( 'Description', 'wc-sage-gateway' ),
          'type'      => 'textarea',
          'desc_tip'  => __( 'Payment description the customer will see during the checkout process.', 'wc-sage-gateway' ),
          'default'   => __( 'Pay securely using your credit card.', 'wc-sage-gateway' )
        )
      );
    }

    // process payment
    public function process_payment( $order_id ) {
      global $woocommerce;

      // get order information
      $customer_order = new WC_Order( $order_id );

      /*************************************************************************
       * Ship to Code
       ************************************************************************/

      // set ship to code
      if ( ! isset( $_POST['ship_to_code'] ) || empty( $_POST['ship_to_code'] ) ) {
        wc_add_notice( __( 'Shipping address must be set.', 'wc-sage-gateway' ) , 'error' );
        return;
      } else {
        $ship_to_code = wc_clean( $_POST['ship_to_code'] );
      }

      /*************************************************************************
       * freight info
       ************************************************************************/
       // only the warehouse is required
       if ( ! isset( $_POST['warehouse'] ) && ! isset( $_SESSION['rc_ship_from_warehouse'] ) ) {
         wc_add_notice( __( 'Warehouse must be set.', 'wc-sage-gateway' ), 'error' );
         return;
       }


       // set freight info
       $freight_info = array();
       $freight_info['warehouse'] = ( isset( $_POST['warehouse'] ) ? wc_clean( $_POST['warehouse'] ) : $_SESSION['rc_ship_from_warehouse'] );
       $freight_info['comment']   = ( isset( $_POST['order_comments'] ) ? wc_clean( $_POST['order_comments'] ) : '' );
       $freight_info['carrier'] = null;

	   foreach( $customer_order->get_shipping_methods() as $shipping_method ) :

	   		switch ( substr( $shipping_method['method_id'], 0, 3) ){

	   			case 'hol' :
	   			case 'wil' :
	   			case 'fre' :
				   // check that freight information is set in the session
				   if ( isset( $_SESSION['rc_freight'] ) ) {
					 $freight_info['freight']['ship_status'] = 'Y';
					 $freight_info['freight']['shipping_cost'] = ( array_key_exists( 'freight_amount', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['freight_amount'] : null );
					 $freight_info['freight']['quote_number'] = ( array_key_exists( 'quote_number', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['quote_number'] : null );
					 $freight_info['freight']['shipment_id'] = ( array_key_exists( 'shipment_id', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['shipment_id'] : null );
					 $freight_info['freight']['carrier'] = ( array_key_exists( 'freight_carrier', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['freight_carrier'] : null );
					 $freight_info['freight']['destination_lift_gate'] = ( array_key_exists( 'destination_lift_gate', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['destination_lift_gate'] : null );
					 $freight_info['freight']['delivery_appointment'] = ( array_key_exists( 'delivery_appointment', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['delivery_appointment'] : null );
					 $freight_info['freight']['residential_delivery'] = ( array_key_exists( 'residential_delivery', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['residential_delivery'] : null );
					 $freight_info['freight']['inside_delivery'] = ( array_key_exists( 'inside_delivery', $_SESSION['rc_freight'] ) ? $_SESSION['rc_freight']['inside_delivery'] : null );
				   }
	   				break;

	   			case 'ups' :
	   				$freight_info['ups']['service'] = $shipping_method['name'];
	   				$freight_info['ups']['shipping_cost'] = $shipping_method['cost'];
	   				$freight_info['ship_status'] = 'Y';
	   				break;

	   		}


	   endforeach;


if( function_exists('kickout') ) kickout( 'process_payment', $_SESSION['rc_freight'], $customer_order, $freight_info, $customer_order->get_shipping_methods(), $_POST );

      /*************************************************************************
       * credit card info
       ************************************************************************/
      $credit_card_info = array();

      // credit card number
      if ( ! isset( $_POST[$this->id . '-card-number'] ) || empty( $_POST[$this->id . '-card-number'] ) ) {
        // missing credit card number
        wc_add_notice( __( 'Please enter your credit card number.', 'wc-sage-gateway' ), 'error' );
        return;
      }
      $credit_card_info['number'] = str_replace( array( ' ', '-' ), '', $_POST[$this->id . '-card-number'] );
      if ( strlen( $credit_card_info['number'] ) !== 16 ) {
        // invalid credit card number
        wc_add_notice( __( 'Please enter a valid credit card number.', 'wc-sage-gateway' ), 'error' );
        return;
      }

      // expiration
      if ( ! isset( $_POST[$this->id . '-card-expiry'] ) || empty( $_POST[$this->id . '-card-expiry'] ) ) {
        // missing credit card expiry
        wc_add_notice( __( 'Please enter your credit card expiry date.', 'wc-sage-gateway' ), 'error' );
        return;
      }
      $expiration = str_replace( array( ' ', '/' ), '', $_POST[$this->id . '-card-expiry'] );
      if ( strlen( $expiration ) !== 4 ) {
        // invalid expiry date
        wc_add_notice( __( 'Please enter a valid credit card expiry date.', 'wc-sage-gateway' ), 'error' );
        return;
      }
      $credit_card_info['expiration_month'] = substr( $expiration, 0, 2 );
      $credit_card_info['expiration_year'] = '20' . substr( $expiration, 2 );

      // name on card
      if ( ! isset( $_POST['sage-card-name'] ) || empty( $_POST['sage-card-name'] ) ) {
        // missing billing name
        wc_add_notice( __( 'Please enter your name.', 'wc-sage-gateway' ), 'error' );
        return;
      }
      $credit_card_info['name'] = $_POST['sage-card-name'];

      // cvc/cvv
      if ( ! isset( $_POST[$this->id . '-card-cvc'] ) || empty( $_POST[$this->id . '-card-cvc'] ) ) {
        // missing cvc
        wc_add_notice( __( 'Please enter your credit card cvc.', 'wc-sage-gateway' ), 'error' );
        return;
      }
      $credit_card_info['cvv'] = $_POST[$this->id . '-card-cvc'];
      if ( strlen( $credit_card_info['cvv'] ) !== 3 ) {
        // invalud cvc
        wc_add_notice( __( 'Please enter a valid credit card cvc.', 'wc-sage-gateway' ), 'error' );
        return;
      }

      /*************************************************************************
       * cart items
       ************************************************************************/
      $cart_items = array();
      $extract_cart_items = true;
      foreach ( $woocommerce->cart->get_cart() as $item ) {
        // get item code
        $item_code = get_post_meta( $item['product_id'], '_sage_sku', TRUE );
        if ( $item_code === false ) {
          // invalid item code
          $extract_cart_items = false;
        }
        // add cart item
        $cart_items[] = array(
          'item_code' => get_post_meta( $item['product_id'], '_sage_sku', TRUE ),
          'quantity'  => $item['quantity']
        );
      }

      if ( $extract_cart_items === false ) {
        wc_add_notice( __( 'Unable to extract cart items.', 'wc-sage-gateway' ), 'error' );
        return;
      }

      // process payment
      $transaction = SageHandler::process_payment( $ship_to_code, $freight_info, $credit_card_info, $cart_items );

		  if ($transaction === false || SageHandler_Error::is_error( $transaction ) ) {
	      // error happened - order not processed
        if ( SageHandler_Error::is_error( $transaction ) ) {
          // check for out of stock
          $error_data = $transaction->get_error_data( 'sold-out' );
          if ( is_array( $error_data ) ) {
            // sold out
            foreach ( $error_data as $item_code ) {
              // TODO look up item name
              wc_add_notice( sprintf( 'Sorry we are sold out of %s.', self::get_product_title_by_sage_sku( $item_code ) ), 'error' );
            }
          } else {
            foreach ( $transaction->get_errors() as $error ) {
              wc_add_notice( $error, 'error' );
            }
          }
        } else {
          // unable to complete transaction, non-specific error
          wc_add_notice( __( 'Sorry, we are unable to complete your transaction at this time.', 'wc-sage-gateway' ), 'error' );
        }
        // error(s)
        return;
      }

      // check for partial error
      if ( $transaction['errors'] === false || SageHandler_Error::is_error( $transaction['errors'] ) ) {
        // parital error
        if ( SageHandler_Error::is_error( $transaction['errors'] ) && $transaction['errors']->get_error_data( 'sold-out' ) ) {
          // some items are sold out - most likely
          foreach ( $transaction['errors']->get_error_data( 'sold-out' ) as $item_code ) {
            wc_add_notice( sprintf( __( 'Sorry we are sold out of %s.' ), self::get_product_title_by_sku( $item_code ) ), 'notice' );
          }
          $customer_order->add_order_note( sprintf( __( 'Partial Order, sold out of "%s".', 'wc-sage-gateway' ), implode('", "', $transaction['errors']->get_error_data( 'sold-out' ) ) ) );
        } else {
          // TODO: partial order, uncaught error
          $customer_order->add_order_note( __( 'Partial Order, uncaught error.', 'wc-sage-gateway' ) );
        }
      } else {
        // successful transaction
        $customer_order->add_order_note( __( sprintf( 'Sage payment completed.  Sales order number: %s', $transaction['sales_order_no'] ), 'wc-sage-gateway' ) );

      }

      // mark order as paid
			$customer_order->payment_complete();
		unset( $_SESSION['rc_freight'] );
		unset( $_SESSION['rc_freight_options'] );

      // redirect to thank you page
      return array( 'result' => 'success', 'redirect' => $this->get_return_url( $customer_order ) );
    }

    // get product title given a sku number
    public static function get_product_title_by_sage_sku( $sku ) {
      global $wpdb;

      $post_title = $wpdb->get_var( $wpdb->prepare( "SELECT `b`.`post_title` FROM " . $wpdb->postmeta . " as `a` INNER JOIN " . $wpdb->posts . " as b ON a.post_id = b.ID WHERE a.meta_key = '_sage_sku' AND a.meta_value = '%s'", $sku ) );

      return $post_title;
    }
  }
}
