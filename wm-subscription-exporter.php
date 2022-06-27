<?php
/*
Plugin Name:  Woocommerce Subscription Exporter
Plugin URI:   https://www.thefold.co.nz 
Description:  A PHP class that creates and formats an importable subscription data in CSV format. The source CSV must come from WM Access Database.
Version:      1.0
Author:       Darwin Jerome
Author URI:   https://darwin.tardio.info
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  wm-subscription-exporter
Domain Path:  /languages
*/

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class WMSUBSCRIPTION {
  /** @var string The plugin version number. */
	var $version = '1.0.1';

    /**
	 * __construct()
	 * A dummy constructor to ensure ATBTRAILMAP is only setup once.
	 * @param	void
	 * @return	void
	 */	
	function __construct() {
		// Do nothing.
	}

    /**
	 * define()
	 * Defines a constant if doesnt already exist.
	 * @param	string $name The constant name.
	 * @param	mixed $value The constant value.
	 * @return	void
	 */
	function define( $name, $value = true ) {
		if( !defined($name) ) {
			define( $name, $value );
		}
	}

  /**
	 * initialize()
	 * Sets up the ATBTRAILMAP plugin.
	 * @param	void
	 * @return	void
	 */
	function initialize() {

    // Define constants.
    $this->define( 'WMSE_PATH', plugin_dir_path( __FILE__ ) );
    $this->define( 'WMSE__URL', plugin_dir_url( __FILE__ ) );
    $this->define( 'WMSE_BASENAME', plugin_basename( __FILE__ ) );
    $this->define( 'WMSE_VERSION', $this->version );
  
    // Add actions.
		add_action( 'wp', array($this, 'init'), 5 );
  }

  /**
	 * init()
	 * Completes the setup process on "init" of earlier.
	 * @param	void
	 * @return	void
	 */
	function init() {
    // Register scripts.

    // Run methods
    $this->generateCSVforImport();
  }

  function generateCSVforImport(){
    if( is_front_page() && current_user_can( 'administrator' ) ){
  
      if(!isset($_GET['mode']) && $_GET['mode'] != 'compare'){
        return;
      }
      console_log("Subscription export mode...");
  
      // Get ACCESSDB CSV subs
      $csvfile = $this->getUploadPath() . "TBLSUBSCRIBER.csv";
      $subs_from_csv = json_decode( $this->get_subscriptions_data_csv( $csvfile ) );
  
      // For Testing
      // $subs_from_csv = array_slice($subs_from_csv, -50);
  
      console_log( "[Subscriber/Users] CSV Count: " . count($subs_from_csv) );
  
      $results = [];
      $i = 0;
  
      $existing_customer = [];
  
      foreach ($subs_from_csv as $subs){
  
        // if no customer email, generate one
        if(!isset($subs->EmailAddress) || empty($subs->EmailAddress)){
          $customer_email = $this->generateCustomEmail($subs->SubscriberID);
        }else{
          $customer_email = $subs->EmailAddress;
        }
  
        // Get WC_Customer object using customer_email
        $customer = $this->getUserDatabyEmail( $customer_email );
  
        $customer_id 										= "";
        $customer_username 							=	$subs->SubscriberID;
        $is_customer										= "False";
        $subscription_id								= "";
        $subscription_post_parent 			= "";
        $subscription_id 								= "";
        $subscription_post_parent 			= "";
  
        // get Woocommerce customer ID
        if( $customer == true ){
          $customer_id 									= $customer->ID;
          $is_customer									= "True";
  
          if( !empty( $customer->get_username() ) && null !== $customer->get_username() ){
            $customer_username 					=	$customer->get_username();
          }
  
          // Track existing customer
          $existing_customer[$customer->ID]['ID'] = $customer_id;
          $existing_customer[$customer->ID]['username'] = $customer_username;
          $existing_customer[$customer->ID]['email'] = $customer_email;
  
          // Find subscription ID
          $subscriptions = $this->getSubscriptionsByUser($customer_id);
  
          if(isset($subscriptions) && !empty($subscriptions)){
            $subscription_id = $subscriptions['subscription_id'];
            $subscription_post_parent = $subscriptions['post_parent'];
          }
        }
  
        // Get billing period and billing interval
        $billing_settings = $this->createBillingPeriod($subs->SubTypeName);
  
        // compute next_payment_date based on billing_settings and last_payment_date
        $last_payment_date = $this->formatDateTime($subs->DatePaid);
  
        $start_date = $this->formatDateTime($subs->StartDate);
        $expiry_date = $this->formatDateTime($subs->ExpiryDate);
  
        $next_payment_date = $this->createNextPaymentDate($last_payment_date, $billing_settings, $subs->RecurringPayment, $expiry_date);
  
        // make sure that start_date is in the past
        $start_date = $this->createStartDate($start_date, $billing_settings);
        
        $results[$i]['subscription_id'] 								= $subscription_id;
        $results[$i]['customer_id'] 										= $customer_id;
        $results[$i]['customer_username'] 							= $customer_username;
        $results[$i]['customer_email'] 									= $customer_email;
        $results[$i]['subscription_status'] 						= 'active';
        $results[$i]['date_created'] 										= $start_date;
        $results[$i]['trial_end_date'] 									= '0';
        $results[$i]['next_payment_date'] 							= $next_payment_date;
        $results[$i]['last_order_date_created'] 				= $last_payment_date;
        $results[$i]['end_date'] 												= $expiry_date;
        $results[$i]['post_parent'] 										= $subscription_post_parent;
        $results[$i]['billing_period'] 									= $billing_settings['period'];
        $results[$i]['billing_interval'] 								= $billing_settings['interval'];
        
        // create shipping method
        $shipping_settings = $this->createShippingMethod($subs->tblSubscriber_Country);
  
        $results[$i]['order_shipping'] 									= $shipping_settings['cost'];
        
        $results[$i]['order_shipping_tax'] 							= 0;
        $results[$i]['order_tax'] 											= 0;
        $results[$i]['cart_discount'] 									= 0;
        $results[$i]['cart_discount_tax'] 							= 0;
        $results[$i]['order_total'] 										= $subs->LastPaid;
        $results[$i]['order_currency'] 									= 'NZD';
  
        // check payment method
        $payment_settings = $this->createPaymentMethod($subs->RecurringPayment);
  
        $results[$i]['payment_method'] 									= $payment_settings['method'];
        $results[$i]['payment_method_title'] 						= $payment_settings['title'];
        $results[$i]['payment_method_post_meta'] 				= '';
        $results[$i]['payment_method_user_meta'] 				= '';
  
        $results[$i]['shipping_method'] 								= $shipping_settings['method'];
  
        if( isset($subs->Gift) &&  $subs->Gift == "TRUE"){
          $billing_first_name 													 = $subs->DonorFirstName;
          $billing_last_name 													 	 = $subs->DonorLastName;
          $billing_email			 													 = $subs->DonorEmail;
          $billing_phone			 													 = '';
          $billing_address_1 														 = $subs->tblDonor_Address1;
          $billing_address_2 														 = $subs->tblDonor_Address2 . " " . $subs->tblDonor_Address3;
          $billing_postcode 														 = $subs->tblDonor_Postcode;
          $billing_city 																 = $subs->tblDonor_City;				
          $billing_state 																 = '';
          $billing_country 															 = $subs->tblDonor_Country;
          $billing_company			 												 = $subs->DonorCompanyName;
        }else{
          $billing_first_name 													 = $subs->FirstName;
          $billing_last_name 														 = $subs->LastName;
          $billing_email			 													 = $customer_email;
          $billing_phone			 											     = ( isset( $subs->HomePhone ) && !empty( $subs->HomePhone ) ) ? $subs->HomePhone : $subs->MobilePhone;
          $billing_address_1 														 = $subs->tblSubscriber_Address1;
          $billing_address_2 														 = $subs->tblSubscriber_Address2 . " " . $subs->tblSubscriber_Address3;
          $billing_postcode 														 = $subs->tblSubscriber_Postcode;
          $billing_city 																 = $subs->tblSubscriber_City;
          $billing_state  															 = $subs->tblSubscriber_City;
          $billing_country 	 														 = $subs->tblSubscriber_Country;
          $billing_company	 														 = $subs->CompanyName;
        }
  
        $results[$i]['billing_first_name'] 							= $billing_first_name;
        $results[$i]['billing_last_name'] 							= $billing_last_name;
        $results[$i]['billing_email'] 									= $billing_email;
        $results[$i]['billing_phone'] 									= $billing_phone;
        $results[$i]['billing_address_1'] 							= $billing_address_1;
        $results[$i]['billing_address_2'] 							= $billing_address_2;
        $results[$i]['billing_postcode'] 								= $billing_postcode;
        $results[$i]['billing_city'] 										= $billing_city;
        $results[$i]['billing_state'] 									= $billing_state;
        $results[$i]['billing_country'] 								= $billing_country ;
        $results[$i]['billing_company'] 								= $billing_company;
        $results[$i]['shipping_first_name'] 						= $subs->FirstName;
        $results[$i]['shipping_last_name'] 							= $subs->LastName;
        $results[$i]['shipping_address_1'] 							= $subs->tblSubscriber_Address1;
        $results[$i]['shipping_address_2'] 							= $subs->tblSubscriber_Address2 . ", " . $subs->tblSubscriber_Address1;
        $results[$i]['shipping_postcode'] 							= $subs->tblSubscriber_Postcode;
        $results[$i]['shipping_city'] 									= $subs->tblSubscriber_City;
        $results[$i]['shipping_state'] 									= $subs->tblSubscriber_City;
        $results[$i]['shipping_country'] 								= $subs->tblSubscriber_Country;
        $results[$i]['shipping_company'] 								= $subs->CompanyName;
        $results[$i]['customer_note'] 									= '';
  
        // order_items-> product_id:1|name:Imported Subscription with Custom Line Item Name|quantity:4|total:38.00|meta:|tax:3.80
        $results[$i]['order_items'] 										= $billing_settings['order_items'];
        // order_notes-> product_id:1|name:Imported Subscription with Custom Line Item Name|quantity:4|total:38.00|meta:|tax:3.80
        $results[$i]['order_notes'] 										= '';
        // coupon_items-> code:rd5|description:|amount:20.00;code:rd5pc|description:|amount:2.00
        $results[$i]['coupon_items'] 										= '';
        // fee_items-> name:Custom Fee|total:5.00|tax:0.50
        $results[$i]['fee_items'] 											= '';
        // tax_items-> id:4|code:Sales Tax|total:4.74
        $results[$i]['tax_items'] 											= '';
        $results[$i]['download_permissions'] 						= '0';
        $results[$i]['is_existing_customer'] 						= $is_customer;
        $results[$i]['order_product_id'] 								= $billing_settings['product_id'];
        $results[$i]['_wt_import_key'] 								  = $subscription_post_parent;
        $i++;
      }
      console_log( "[Subscriptions] Results Count: " . count($results) );
      console_log( "[Subscriber] Existing Customer: " . count($existing_customer) );

      $column_headers = array('subscription_id', 'customer_id', 'customer_username', 'customer_email', 'subscription_status',
                              'date_created', 'trial_end_date', 'next_payment_date', 'last_order_date_created', 'end_date', 'post_parent',
                              'billing_period', 'billing_interval', 'order_shipping', 'order_shipping_tax', 'order_tax', 
                              'cart_discount', 'cart_discount_tax', 'order_total', 'order_currency', 'payment_method',
                              'payment_method_title', 'payment_method_post_meta', 'payment_method_user_meta', 'shipping_method',
                              'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_address_1', 
                              'billing_address_2', 'billing_postcode', 'billing_city', 'billing_state', 'billing_country',
                              'billing_company', 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2',
                              'shipping_postcode', 'shipping_city', 'shipping_state', 'shipping_country', 'shipping_company', 'customer_note',
                              'order_items', 'order_notes', 'coupon_items', 'fee_items', 'tax_items', 'download_permissions', 'is_existing_customer', 'product_id', '_wt_import_key');
      
      // Export file
      $this->exportToCSV($column_headers, $results);
    }
  }
  
  function formatDateTime($dateString){
    // Expected Output: 2016-04-29 00:44:44
    if($dateString){	
      $output = DateTime::createFromFormat('m/d/y', $dateString);
      $newDateString = $output->format('Y-m-d') . " 10:00:00";
      return $newDateString;
    }
  }
  
  function getUserDatabyEmail($email){
    $user = get_user_by( 'email', $email );
    return $user;
  }
  
  function generateCustomEmail($subscriber_id){
    if( isset($subscriber_id) && !empty($subscriber_id) ){
      return "subscriber+" . $subscriber_id . "@lifestylepublishing.co.nz";
    }
  }
  
  function getSubscriptionsByUser($user_id){
    $subscriptions = wcs_get_users_subscriptions($user_id);
    $subscriptions_data = [];
    foreach ($subscriptions as $subscription){
      if($subscription->get_status() == "active"){
        $subscriptions_data['subscription_id'] = $subscription->get_id();
        $subscriptions_data['subscription_status'] = $subscription->get_status();
        $subscriptions_data['post_parent'] = $subscription->get_parent_id();
      }
    }
    return $subscriptions_data;
  }
  
  function exportToCSV($column_headers, $results){ 
    if ($results){			
      $filename = 'subscriptions_' . time() . '.csv';
      $filename_fullpath = $this->getUploadPath() . $filename;
      $fp = fopen($filename_fullpath, 'w');
      fputcsv($fp, $column_headers);
      foreach ($results as $fields) {
        fputcsv($fp, $fields);
      }
      fclose($fp);
      console_log("Subscription export COMPLETED");
    }
  }
  
  function getUploadPath(){
    $uploadsurl = wp_upload_dir();
    $upload_dir = $uploadsurl['basedir'] . "/wm-subscriptions-formatter/";
  
    if (!file_exists($upload_dir)) {
      $dir = mkdir($upload_dir, 0777, true);
      if($dir){
        return $upload_dir;
      }
    }
    return $upload_dir;
  }
  
  function createBillingPeriod($subtype){
    $billing = "";
  
    if($subtype){
      // 14 months
      $billing_annualextra = array('Print + Web - Annual - Lapsed 14 Issue',
                                    'Print + Web - Annual - Lapsed 14 Issue Recurring');  
      // Yearly
      $billing_annual = array('P+W - 12mth',
                          'P+W - 12mth - Recurring',
                          'P+W + Digital - Annual Recurring',
                          'Isubscribe - 12mth',											
                          'FMC - Annual Recurring',											
                          'P+W - AUS Z1 - 12mth Recurring',
                          'FMC - Annual',
                          'P+W - Europe, Canada Z4 - 12mth',
                          'P+W - USA Z5 - 12mth Recurring',
                          'P+W - Asia Z3 - 12mth recurring',
                          'P+W - 12mth Gift',
                          'P+W + Digital - AUS - 12mth Recurring',
                          'P+W - AUS Z1 - 12mth',
                          'P+W - Europe, Canada Z4 - 12mth recurring',
                          'Magshop - 12mth',
                          'P+W - USA Z5 - 12mth',
                          'P+W + Digital - Annual',
                          'P+W + Dig - USA Z5  - 12m Recurring',
                          'P+W+Dig - Asia Z3 - 12mth recurring',
                          'P+W + Digital - AUS - 12mth',
                          'Ebsco Renewal', 'Ebsco New', 'Prize', 'Complimentary - Reducing');      
      // Every 6 months
      $billing_biannual = array('P+W - 6 mth - Recurring',
                                'iSubscribe - 6 mth',
                                'P+W - Europe, Canada Z4 - 6mth recurring',
                                'P+W - 6 mth',
                                'Magshop - 6 mth');
      // Every 6 months
      $billing_threemonths = array('P+W - 3mth Recurring', 'P+W - AUS Z1- 3mth Recurring');  
      // Every 2 months
      $billing_twomonths = array('P+W - 2mth Recurring', 'P+W - AUS Z1 - 2mth Recurring', 'P+W - USA Z5 - 2mth Recurring');
  
      if (in_array($subtype, $billing_annualextra)) {
        // billing_interval = 14, billing_period = month
        $billing = ['interval' => '14', 'period' => 'month', 'product_id' => '128232', 'order_items' => 'product_id:128232|name:Print + Website - 12 issues - $98.50 every year|quantity:1|total:98.50|meta:subscription-options=12 issues - $98.50 every year|tax:0.00' ];
      }else if (in_array($subtype, $billing_annual)) {
        // billing_interval = 1, billing_period = year
        $billing = ['interval' => '1', 'period' => 'year', 'product_id' => '128232', 'order_items' => 'product_id:128232|name:Print + Website - 12 issues - $98.50 every year|quantity:1|total:98.50|meta:subscription-options=12 issues - $98.50 every year|tax:0.00'];
      }else if (in_array($subtype, $billing_biannual)) {
        // billing_interval = 6, billing_period = month
        $billing = ['interval' => '6', 'period' => 'month', 'product_id' => '128571', 'order_items' => 'product_id:128571|name:Print + Website - 6 issues - $54 every six months|quantity:1|total:54.00|meta:subscription-options=6 issues - $54 every six months|tax:0.00'];
      }else if (in_array($subtype, $billing_threemonths)) {
        // billing_interval = 3, billing_period = month
        $billing = ['interval' => '3', 'period' => 'month', 'product_id' => '128578', 'order_items' => 'product_id:128578|name:Print + Website - 3 issues - $28 every three months|quantity:1|total:28.00|meta:subscription-options=3 issues - $28 every three months|tax:0.00'];
      }else if (in_array($subtype, $billing_twomonths)) {
        // billing_interval = 2, billing_period = month
        $billing = ['interval' => '2', 'period' => 'month', 'product_id' => '128644', 'order_items' => 'product_id:128644|name:Print + Website - Bi-monthly $18.50 every two months|quantity:1|total:18.50|meta:subscription-options=Bi-monthly $18.50 every two months|tax:0.00'];
      }
    }  
    return $billing;
  }
  
  function createStartDate($start_date, $billing_settings){
  
    $current_date = new DateTime();
    $start_date_new = new DateTime($start_date);
  
    // if startdate is in the future, update it
    if ($start_date_new > $current_date){
      // format interval date
      $interval = $billing_settings['interval'] . " " . $billing_settings['period'];
      $interval = ($billing_settings['interval'] > 1) ? $interval . 's' : $interval;
      $date_interval = DateInterval::createFromDateString($interval);
  
      // subtract interval from start date
      $start_date_new = $start_date_new->sub($date_interval);
      $start_date = $start_date_new->format('Y-m-d') . " 10:00:00";
    }
    return $start_date;
  }
  
  function createNextPaymentDate($last_payment_date, $billing_settings, $is_recurring, $end_date){
    $next_payment_date = NULL;
    
    if($is_recurring != "FALSE"){
      if(isset( $last_payment_date ) && !empty($last_payment_date)){
  
        // format last_payment_date
        $last_payment_date = new DateTime($last_payment_date);
        $end_date = new DateTime($end_date);
  
        // format interval date so it can be added to last_payment_date
        $interval = $billing_settings['interval'] . " " . $billing_settings['period'];
        $interval = ($billing_settings['interval'] > 1) ? $interval . 's' : $interval;
        $date_interval = DateInterval::createFromDateString($interval);
  
        // add dates
        $next_payment_date = $last_payment_date->add($date_interval);
  
        // Subtract 1 month from next payment date, 
        // if it's less than or equal to end_date
        if($end_date <= $next_payment_date){
          $date_to_subtract = DateInterval::createFromDateString('1 month');
          $next_payment_date = $next_payment_date->sub($date_to_subtract);
        }
  
        // Format for return
        $next_payment_date = $next_payment_date->format('Y-m-d') . " 10:00:00";
      } 
    }
    return $next_payment_date;
  }
  
  function createPaymentMethod($is_recurring){
    $payment = ['method'=>'', 'title'=>''];
    if($is_recurring == "TRUE"){
      $payment = ['method'=>'stripe', 'title'=>'Credit card (Stripe)'];
    }else{
      $payment = ['method'=>'manual', 'title'=>'Manual'];
    }
    return $payment;
  }
  
  function createShippingMethod($country){
    
    if($country == "Australia"){
      $method = ['method'=>'method_id:flat_rate|method_title:Australia - Zone 1|total:70.50',
                  'cost'=>'70.50'];
    }elseif($country == "New Zealand"){
      $method = ['method'=>'method_id:flat_rate|method_title:Local Shipping|total:0.00',
                  'cost'=>'0.00'];
    }elseif($country == "Canada" || $country == "France" || $country == "Norway"
           || $country == "Germany" || $country == "Switzerland" || $country == " United Kingdom" || $country == "Netherlands"){
      $method = ['method'=>'method_id:flat_rate|method_title:Canada, UK, Europe - Zone 4|total:100.00',
                  'cost'=>'100.00'];
    }elseif($country == "Japan" || $country == "Malaysia" || $country == "Singapore"){
      $method = ['method'=>'method_id:|method_title:Zone 3 Shipping|total:95.00',
                  'cost'=>'95.00'];
    }elseif($country == "United States of America"){
      $method = ['method'=>'method_id:flat_rate|method_title:USA Zone 5 Shipping|total:160.00',
                  'cost'=>'160.00'];
    }else{
      $method = ['method'=>'method_id:flat_rate|method_title:Canada, UK, Europe - Zone 4|total:100.00',
                  'cost'=>'100.00'];
    }
    return $method;
  }
  
  // parse CSV
  function get_subscriptions_data_csv($file){
    if (($handle = fopen($file, "r")) !== FALSE) {
      $csvs = [];
      while(! feof($handle)) {
         $csvs[] = fgetcsv($handle);
      }
      $datas = [];
      $column_names = [];
      foreach ($csvs[0] as $single_csv) {
          $column_names[] = $single_csv;
      }
      foreach ($csvs as $key => $csv) {
          if ($key === 0) {
              continue;
          }
          foreach ($column_names as $column_key => $column_name) {
              $datas[$key-1][$column_name] = $csv[$column_key];
          }
      }
      $json = json_encode($datas);
      fclose($handle);
      return $json;
    }
  }

}

/*
 * wmSubscriptionExporter()
 *
 * The main function responsible for returning the one true WMSUBSCRIPTION Instance to functions everywhere.
 * Use this function like you would a global variable, except without needing to declare the global.
 * Example: <?php $wmSubExporter = wmSubscriptionExporter(); ?>
 * @param	void
 * @return	WMSUBSCRIPTION
 */
function wmSubscriptionExporter() {
	global $wmSubExporter;
	
	// Instantiate only once.
	if( !isset($wmSubscriptionExporter) ) {
		$wmSubExporter = new WMSUBSCRIPTION();
		$wmSubExporter->initialize();
	}
	return $wmSubExporter;
}

// Instantiate.
wmSubscriptionExporter();