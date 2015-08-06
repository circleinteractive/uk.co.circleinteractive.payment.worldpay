<?php

/**
 * Worldpay Extension for CiviCRM - Circle Interactive 2015
 * @author Drew Webber (mcdruid), extension-ized / maintained by andyw@circle
 */

define('CIVICRM_WORLDPAY_LOGGING_LEVEL', 1);

require_once 'CRM/Core/Payment.php';

class uk_co_circleinteractive_payment_worldpay extends CRM_Core_Payment {
    
    public $logging_level      = 0;
    protected $_mode           = null;
    static private $_singleton = null; 
    
    public static function &singleton($mode = 'test', &$paymentProcessor, &$paymentForm = null, $force = false) {
        
        $processorName = $paymentProcessor['name'];
        if (is_null(self::$_singleton[$processorName]))
            self::$_singleton[$processorName] = new uk_co_circleinteractive_payment_worldpay($mode, $paymentProcessor);
        return self::$_singleton[$processorName];
    
    }
    
    public function __construct($mode, &$paymentProcessor) {
        
        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Worldpay');
        $this->logging_level     = CIVICRM_WORLDPAY_LOGGING_LEVEL;
                    
    }
    
    public function checkConfig() {
        
        $error = array();
        
        if (!$this->_paymentProcessor['user_name']) 
            $errors[] = 'No username supplied for Worldpay payment processor';
        
        if (!empty($errors)) 
            return '<p>' . implode('</p><p>', $errors) . '</p>';
        
        return null;
    
    }
    
    // Not req'd for billingMode=notify
    public function doDirectPayment(&$params) {
        return null;    
    }
    
    // Initialize transaction
    public function doTransferCheckout(&$params, $component = 'contribute') {
       
        $config = &CRM_Core_Config::singleton();
        if ($component != 'contribute' && $component != 'event')
            CRM_Core_Error::fatal(ts('Component is invalid'));
     
     // 1. add standard WP fields
      if ( $this->_mode == 'test' ) {
        // TEST
        $postFields = $this->getWorldPayFields($params,true);
      }  
      else {
        // LIVE
        $postFields = $this->getWorldPayFields($params,false);
      }
      if ($postFields===false) {
        watchdog('aw@circle', __FILE__.":".__FUNCTION__." : Failed to process parameters for WorldPay payment processor. params=".print_r($params,true));
        error_log(__FILE__.":".__FUNCTION__." : Failed to process parameters for WorldPay payment processor. params=".print_r($params,true));
        
        return self::error(9002,'Failed to process parameters for WorldPay payment processor. params='.print_r($params,true));
      }
 
      // 2. add custom WP fields
      // add the callback url for handling payment responses
      // note that callback for futurepay IPN is hardcoded
      // in the MAI, but single payments and futurepay setup
      // use MC_callback if it is set (otherwise they use the
      // hardcoded value)
      $config =& CRM_Core_Config::singleton();
      /*
      $postFields["MC_callback"]=    
           $config->userFrameworkResourceURL .
           "/civicrm/payment/ipn?processor_id=" . $this->_paymentProcessor->id;
      */
      $notifyURL = CRM_Utils_System::url('civicrm/payment/ipn', 'processor_id=' . $this->_paymentProcessor->id, true, null, false, true, false);
      $postFields['MC_callback'] = $notifyURL;

      // add the civicrm specific details as WorldPay custom params
      $postFields["MC_contact_id"]=$params["contactID"];
      $postFields["MC_contribution_id"]=$params["contributionID"];
      $postFields["MC_module"]=$component;
      if ( $component == 'event' ) {
        $postFields["MC_event_id"]=$params["eventID"];
        $postFields["MC_participant_id"]=$params["participantID"];
      }
      else {
        $membershipID = CRM_Utils_Array::value( 'membershipID', $params );
        if ( $membershipID ) {
          $postFields["MC_membership_id"]=$membershipID;
        }
      }

      if ($params["is_recur"]==1) {
        if (is_numeric($params["contributionRecurID"])) {
          $postFields["MC_contribution_recur_id"]=$params["contributionRecurID"];
          $postFields["MC_contribution_page_id"]=$params["contributionPageID"];
        }
        else {
        
          CRM_Core_Error::fatal( ts( 'Recurring contribution, but no database id' ) );
        }
      }

      // add the civicrm return urls as WorldPay custom params
      $url    = ( $component == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';
      $cancel = ( $component == 'event' ) ? '_qf_Register_display'   : '_qf_Main_display';
      $returnURL = CRM_Utils_System::url( $url,
                                           "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
                                           true, null, false );
      $cancelURL = CRM_Utils_System::url( $url,
                                          "$cancel=1&cancel=1&qfKey={$params['qfKey']}",
                                          true, null, false );
      $postFields["MC_civi_thankyou_url"]=$returnURL;
      $postFields["MC_civi_cancel_url"]=$cancelURL;
        
        
      // Calling hook_civicrm_alterPaymentProcessorParams would be good if poss - aw@circle, 01/06/2011
      require_once('CRM/Utils/Hook.php');
      CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $postFields);

      // 3. build the URL for WorldPay
      $uri = '';
      foreach ( $postFields as $key => $value ) {
        if ( $value === null ) {
          error_log(__FILE__.":".__FUNCTION__." : null field value in WorldPay params for key=$key");
          continue;
        }
        $value = urlencode( $value );

        $uri .= "&{$key}={$value}";
      }

      $uri = substr( $uri, 1 );
      $url = $this->_paymentProcessor['url_site'];
      //$sub = empty( $params['is_recur'] ) ? 'xclick' : 'subscriptions';
      
      $worldPayURL = "{$url}?$uri";
      $this->log(__FILE__.":".__FUNCTION__." : WorldPay Junior Select URL=$worldPayURL");

      
      //header('Location: ' . $worldPayURL);
      CRM_Utils_System::redirect( $worldPayURL );
        
    }
  /**
     * build the billing name
     *
     * @param array $params parameters to check for billing name fields
     *
     * @return string name or false
     */
    protected function _getName(&$params) {
      $name = array();
      if (!empty($params["billing_first_name"])) {
        $name[]= $params["billing_first_name"];
      }
      if (!empty($params["billing_middle_name"])) {
        $name[]= $params["billing_middle_name"];
      }
      if (!empty($params["billing_last_name"])) {
        $name[]= $params["billing_last_name"];
      }
      if (sizeof($name)==0) {
        return false;
      }
      else {
        return implode(" ",$name);
      }
    }

    /**
     * get fields for constructing a WorldPay Payment Token
     *
     * assumes currency code is the same:
     *   WorldPay uses ISO 4217 (from http://www.id3.org/iso4217.html)
     *   CiviCRM uses ???
     */
    protected function getWorldPayFields(&$params, $testing=true) {
      
      $fields = array();
      if ($testing) {
          $fields["testMode"]=100;
      }

      $fields["instId"] = $this->_paymentProcessor["user_name"];
      $fields["authMode"] = "A";

      $fields["currency"] = $params["currencyID"];// may need translation
      $fields["hideCurrency"] = 'false';
      $fields["cartId"] = $params["invoiceID"];// correct?
      $fields["desc"] = $params["description"];
      $name = $this->_getName($params);
      $fields["email"] = $params["email"];
      if (!isset($fields["email"]) && isset($params["email-5"])) {
        $fields["email"]=$params["email-5"];
      }
      
      if ( $name===false || 
        // always going to be here with billing_id=4
        //
           empty($params["street_address"]) ||
           empty($params["country"]) ||
           empty($params["postal_code"]) ) {

        // insufficient details available let them fill it in themselves
        // on the WorldPay pages
        $fields["fixContact"] = 'N';// prob default behaviour anyway
        $fields["hideContact"] = 'N';
      }
      else {
        // never going to get here with billing_id=4
        //
        $fields["address"] = $params["street_address"];
        if (!empty($params["city"])) {
          $fields["address"].= "\n".$params["city"];
        }
        $fields["postcode"] = $params["postal_code"];
        $fields["country"] = $params["country"];
        //$fields["tel"]= '';  // could collect phone num...
        if (!empty($params["email-5"])) { // dubious...
          $fields["email"]= $params["email-5"];
        }
        $fields["fixContact"] = 'Y';
        $fields["hideContact"] = 'N';
      }

      if ($params["is_recur"]==1) {
        // repeat payment for WorldPay:
        //
        $repeatUnit = $this->getWorldPayUnitCode($params["frequency_unit"]);
        if ($repeatUnit===false) {
          CRM_Core_Error::fatal( ts('Error with WorldPay payment processor for repeat payment : bad frequency-unit from params='.print_r($params,true))); 
        }
        $noPayments = $params["installments"];
        if ($noPayments<1) {
          CRM_Core_Error::fatal( ts('Error with WorldPay payment processor for repeat payment : bad installments count from params='.print_r($params,true)));
        }
        $tomorrow = mktime(0, 0, 0, date("m"), date("d")+1, date("y"));

        // future pay types are:
        // regular - regular payments
        // limited - limited but indeterminate payments
        $fields["futurePayType"]='regular';// regular pay type

        // repeat options are:
        // 0 - fixed amount, can't adjust after start
        // 1 - can adjust payment after start
        // 2 - use Merchant Administration Interface        
        $fields["option"]='0';// fixed amount, can't adjust
        
        // unused fields:
        //$fields["startDelayMult"]='0';// no start delay  
        //$fields["startDelayUnit"]='0';// no start delay 
        //$$fields["initialAmount"]='0';// just use normal_amount

        // fixed repeat payment details
        $fields["intervalUnit"]=$repeatUnit;
        $fields["intervalMult"]=$params["frequency_interval"];
        $fields["normalAmount"]=$params["amount_other"];// same as 1st
        $fields["noOfPayments"]=$noPayments;// -1 to remove 1st ?
        $fields["amount"]=$params["amount_other"];//
        $fields["startDate"]=date("Y-m-d",$tomorrow);
      }
      else {
        // single one off payment
        $fields["amount"]=$params["amount"];
      }
      return $fields;
    }

    protected function getWorldPayUnitCode($frequencyUnit) {
      switch ($frequencyUnit) {
        case "day":   return 1;
        case "week":  return 2;
        case "month": return 3;
        case "year":  return 4;
        default:
          $this->error(__FILE__.":".__FUNCTION__." : unrecognized interval=$frequencyUnit");
          return false;
      }
    }
        
    public function enable() {   
        // .. code to run when extension enabled ..
    }
    
    public function error($message, $function=null, $lineno=null) {
        
        if (!$this->logging_level)
            return;

        if ($function)
            $message .= ' in ' . $function;
        if ($lineno)
            $message .= ' at line ' . $lineno;
        
        CRM_Core_Error::debug_log_message("CiviCRM Worldpay: " . $message);
        
        // Also post to Drupal db log, if available ...
        if (function_exists('watchdog'))
            watchdog('civicrm_worldpay', $message, array(), WATCHDOG_ERROR);
        
    }
    
  
 
    // New callback function for payment notifications as of Civi 4.2
    public function handlePaymentNotification() {

        $this->log('Payment notification received. Request = ' . print_r($_REQUEST, true));

        require_once dirname(__FILE__) . '/ipn.php';
        $ipn = new uk_co_circleinteractive_payment_worldpay_notify($this);
        $ipn->main(false); 
   
    }

    public function log($message) {
        if ($this->logging_level) {
            CRM_Core_Error::debug_log_message("CiviCRM Worldpay: " . $message);
            if (function_exists('watchdog')) 
                watchdog('civicrm_worldpay', $message, array(), WATCHDOG_INFO);
        }
    }
                
    // Get Civi version as float
    public function getCRMVersion() {
        $crmversion = explode('.', ereg_replace('[^0-9\.]','', CRM_Utils_System::version()));
        return floatval($crmversion[0] . '.' . $crmversion[1]);
    }
    
    protected function getIDs() {
        $ids = array();
        $dao = CRM_Core_DAO::executeQuery("
            SELECT id FROM civicrm_payment_processor 
             WHERE class_name = 'uk.co.circleinteractive.payment.worldpay'
        ");
        while ($dao->fetch())
            $ids[] = $dao->id;
        return $ids;
    }
        
    // Send POST request using cURL 
    protected function requestPost($url, $data){
        
        if (!function_exists('curl_init'))
            CRM_Core_Error::fatal(ts('CiviCRM Worldpay extension requires the component \'php5-curl\'.'));
        
        set_time_limit(60);
        
        $output  = array();
        $session = curl_init();
        
        // Set curl options
        curl_setopt_array($session, array(
            CURLOPT_URL            => $url,
            CURLOPT_HEADER         => 0,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        
        // Send request and split response into name/value pairs
        $response = split(chr(10), curl_exec($session));
        
        // Check that a connection was made
        if (curl_error($session)){
            // If it wasn't...
            $output['Status'] = "FAIL";
            $output['StatusDetail'] = curl_error($session);
        }
    
        curl_close($session);
    
        // Tokenise the response
        for ($i=0; $i<count($response); $i++){
            $splitAt = strpos($response[$i], "=");
            $output[trim(substr($response[$i], 0, $splitAt))] = trim(substr($response[$i], ($splitAt+1)));
        }
        return $output;
    }
        
};

