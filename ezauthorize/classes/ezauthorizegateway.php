<?php
//
// Definition of eZAuthorizeGateway class
//
// eZ publish payment gateway for Authorize.net
// implementing transparent credit card payment
// transactions in eZ publish using cURL.
//
// Created on: <01-Dec-2005 7:50:00 Dylan McDiarmid>
// Last Updated: <11-Dec-2005 01:49:35 Graham Brookins>
// Version: 1.0.0
//
// Copyright (C) 2001-2005 Brookins Consulting. All rights reserved.
//
// This source file is part of an extension for the eZ publish (tm)
// Open Source Content Management System.
//
// This file may be distributed and/or modified under the terms of the
// "GNU General Public License" version 2 (or greater) as published by
// the Free Software Foundation and appearing in the file LICENSE
// included in the packaging of this file.
//
// This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
// THE WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR
// PURPOSE.
//
// The "GNU General Public License" (GPL) is available at
// http://www.gnu.org/copyleft/gpl.html
//
// Contact licence@brookinsconsulting.com if any conditions
// of this licencing isn't clear to you.
//

/*!
  \class eZAuthorizeGateway ezauthorizegateway.php
  \brief eZAuthorizeGateway extends eZCurlGateway to provide a transparent
  Payment system through Authorize.Net using cURL.
*/

include_once ( 'extension/ezauthorize/classes/ezcurlgateway.php' );
include_once ( 'extension/ezauthorize/classes/ezauthorizeaim.php' );
// include_once ( 'extension/ezauthorize/classes/obj_xml.phps' );

define( "EZ_PAYMENT_GATEWAY_TYPE_EZAUTHORIZE", "eZAuthorize" );

function ezauthorize_format_number( $str, $decimal_places='2', $decimal_padding="0" ) {
    /* firstly format number and shorten any extra decimal places */
    /* Note this will round off the number pre-format $str if you dont want this fucntionality */
    $str =  number_format( $str, $decimal_places, '.', '');    // will return 12345.67
    $number = explode( '.', $str );
    $number[1] = ( isset( $number[1] ) )?$number[1]:''; // to fix the PHP Notice error if str does not contain a decimal placing.
    $decimal = str_pad( $number[1], $decimal_places, $decimal_padding );
    return (float) $number[0].'.'.$decimal;
}

class eZAuthorizeGateway extends eZCurlGateway
{
    /*!
     Constructor
    */
    function eZAuthorizeGateway()
    {
    }

    function loadForm( &$process, $errors = 0 )
    {
        $http = eZHTTPTool::instance();

        // get parameters
        $processParams = $process->attribute( 'parameter_list' );

        // load ini
        $ini = &eZINI::instance( 'ezauthorize.ini' );

        // regen posted form values
        if ( $http->hasPostVariable( 'validate' ) and
             $ini->variable( 'eZAuthorizeSettings', 'RepostVariablesOnError' ) )
        {
            $tplVars['cardname'] = trim( $http->postVariable( 'CardName' ) );
            $tplVars['cardtype'] = strtolower( $http->postVariable( 'CardType' ) );
            $tplVars['cardnumber'] = $http->postVariable( 'CardNumber' );
            $tplVars['expirationmonth'] = $http->postVariable( 'ExpirationMonth' );
            $tplVars['expirationyear'] = $http->postVariable( 'ExpirationYear' );
            $tplVars['securitynumber'] = $http->postVariable( 'SecurityNumber' );
            $tplVars['amount'] = '';
        }
        else
        {
            // set form values to blank
            $tplVars['cardname'] = '';
            $tplVars['cardtype'] = '';
            $tplVars['cardnumber'] = '';
            $tplVars['expirationmonth'] = '';
            $tplVars['expirationyear'] = '';
            $tplVars['securitynumber'] = '';
            $tplVars['amount'] = '';
        }

        $tplVars['s_display_help'] = $ini->variable( 'eZAuthorizeSettings', 'DisplayHelp' );
        $tplVars['errors'] = $errors;
        $tplVars['order_id'] = $processParams['order_id'];

        $process->Template=array
        (
            'templateName' => 'design:workflow/eventtype/result/' . 'ezauthorize_form.tpl',
            'templateVars' => $tplVars,
            'path' => array( array( 'url' => false,
                                    'text' =>  'Payment Information') ) 

        );

        // ezDebug::writeDebug( $errors, 'eZAuthorizeGateway loadform'  );

        return EZ_WORKFLOW_TYPE_STATUS_FETCH_TEMPLATE_REPEAT;
    }

    function validateForm( &$process )
    {
        $http = eZHTTPTool::instance();
        $errors = false;

        if ( trim( $http->postVariable( 'CardNumber' ) ) == '' )
        {
            $errors[] = 'You must enter a card number.';
        }
        elseif( strlen( trim( $http->postVariable( 'CardNumber' ) ) ) > 49 )
        {
            $errors[] = 'Your card number should be under 50 characters.';
        }

        if ( trim( $http->postVariable( 'CardName' ) ) == '' )
        {
            $errors[] = 'You must enter a card name.';
        }
        elseif( strlen( trim( $http->postVariable( 'CardName' ) ) ) > 79 )
        {
            $errors[] = 'Your card name should be under 80 characters.';
        }

        if ( trim( $http->postVariable( 'ExpirationMonth' ) ) == '' )
        {
            $errors[] = 'You must select an expiration month.';
        }

        if ( trim( $http->postVariable( 'ExpirationYear' ) ) == '' )
        {
            $errors[] = 'You must select an expiration year.';
        }

        return $errors;
    }

    /*
     * Builds URI and executes the Authorize.Net curl functions.
    */
    function doCURL( &$process )
    {
        include_once( 'kernel/classes/datatypes/ezuser/ezuser.php' );
        // debug output sent to server from eZ publish to authorize.net web service
        include_once( 'extension/ezdbug/autoloads/ezdbug.php' );
        $d = new eZDBugOperators();

        // load ini
        $ini = eZINI::instance( 'ezauthorize.ini' );

        // retrieve Status Codes
        $startStatusCode =  $ini->variable( 'eZAuthorizeSettings', 'StartStatusCode' );
        $successStatusCode =  $ini->variable( 'eZAuthorizeSettings', 'SuccessStatusCode' );
        $failStatusCode =  $ini->variable( 'eZAuthorizeSettings', 'FailStatusCode' );

        // fetch keyID
        $key_id = $ini->variable( 'eZGPGSettings', 'KeyID' );

        // debug status
        $debug = false;

        // load http
        $http = eZHTTPTool::instance();

        // make the order object
        $processParams = $process->attribute( 'parameter_list' );

        // get order id
        $order_id = $processParams['order_id'];

        // get order
        $order = &eZOrder::fetch( $processParams['order_id'] );

        if ( $debug and $debug_order_information ) {
        // the whole object
         $d->ezdbugDump($order);
        }

        // get total order amount, including tax
        $order_total_amount = $order->attribute( 'total_inc_vat' );

        $order_total_tax_amount = $order->attribute( 'total_inc_vat' ) - $order->attribute( 'total_ex_vat' );

        // get totals in number format
        $order_total_amount = ezauthorize_format_number( $order_total_amount );
        $order_total_tax_amount = ezauthorize_format_number( $order_total_tax_amount );

        // get user id
        $user_id = $processParams['user_id'];

        // note start of order transmission
        if( $startStatusCode )
        {
            // $order->modifyStatus( $startStatusCode );
            // $order->setStatus( $startStatusCode );
        }

        // assign variables to Authorize.Net class from post
        $aim = new eZAuthorizeAIM();

        // assign card name
        $aim->addField( 'x_card_name', trim( $http->postVariable( 'CardName' ) ) );

        // assign card expiration date
        $aim->addField( 'x_exp_date', $http->postVariable( 'ExpirationMonth' ) . $http->postVariable( 'ExpirationYear' ) );

        // assign card number
        $aim->addField( 'x_card_num', trim( $http->postVariable( 'CardNumber' ) ) );

        // check cvv2 code
        if ( $ini->variable( 'eZAuthorizeSettings', 'CustomerCVV2Check' ) == 'true' )
        {
            // assign card security number, cvv2 code
            $aim->addField( 'x_card_code', trim( $http->postVariable( 'SecurityNumber' ) ) );
        }

        // get order customer information
        if ( $ini->variable( 'eZAuthorizeSettings', 'GetOrderCustomerInformation' ) == 'true' )
        {
            if ( $this->getOrderInfo( $order ) ) {

                // Send customer billing address to authorize.net
                if ( $ini->variable( 'eZAuthorizeSettings', 'CustomerAddressVerification' ) == 'true' )
                {
                    $this->addAVS( $aim );

                    // debug step output, transaction data stucture
                    // $d->ezdbugDump($aim);
                }

                // Send customer shipping address to authorize.net
                if ( $ini->variable( 'eZAuthorizeSettings', 'SendCustomerShippingAddress' ) == 'true' )
                {
                    $this->addShipping( $aim );

                    // debug step output, transaction data stucture
                    // $d->ezdbugDump($aim);
                }

                // Send customer phone number (optional)
                $aim->addField( 'x_phone', $this->order_phone );

                // Send customer fax phone number (optional)
                $aim->addField( 'x_fax', $this->order_phone );
            }
        }


        // assign authorize.net invoice number

        // Provide authorize.net transaction with eZ publish order 'number'.
        // ps real order numbers do not exist in eZ publish until after payment
        // processing has been completed successfully so this is not possible by default.

        // if ( $ini->valriable('eZAuthorizeSetting', 'SetOrderID' ) ) {

        // or get actual order id (different number used in order view urls)
        $aim->addField( 'x_invoice_num', $order->attribute( 'id' ) );

        // assign authorize.net transaction description
        $aim->addField( 'x_description', 'Order URL ID #' . $order->attribute( 'id' ) );



        // } else { }

        // get actual order number
        // $aim->addField( 'x_invoice_num', $order->attribute( 'order_nr' ) );

        // assign authorize.net transaction description
        // $aim->addField( 'x_description', 'Order ID #' . $order->attribute( 'order_nr' ) );

        // }

        // assign customer IP
        $aim->addField( 'x_customer_ip', $_SERVER['REMOTE_ADDR'] );

        // assign customer id
        $aim->addField( 'x_cust_id', $user_id );

        // Send customer email address (default to true)
        $aim->addField( 'x_email', $this->order_email );

        // check send customer confirmation email
        if ( $ini->variable( 'eZAuthorizeSettings', 'CustomerConfirmationEmail' ) == 'true' )
        {
            // assign and send customer confirmation email
            $aim->addField( 'x_email_customer', 'TRUE' );

            $aim->addField( 'x_merchant_email', trim( $ini->variable( 'eZAuthorizeSettings', 'ShopAdminEmailAddress' ) ) );
        }

        // get currency code
        $currency_code =  $ini->variable( 'eZAuthorizeSettings', 'CurrencyCode' );

        // assign currency code
        if ( $currency_code != '' )
        {
            $aim->addField( 'x_currency_code', $currency_code );
        }

        // assign total variables from order
        $aim->addField( 'x_amount', $order_total_amount );
        $aim->addField( 'x_tax', $order_total_tax_amount );

        // assign merchant account information
        $aim->addField( 'x_login', $ini->variable( 'eZAuthorizeSettings', 'MerchantLogin' ) );
        $aim->addField( 'x_tran_key', $ini->variable( 'eZAuthorizeSettings', 'TransactionKey' ) );

        // set authorize.net mode
        $aim->setTestMode( $ini->variable( 'eZAuthorizeSettings', 'TestMode' ) == 'true' );

        // set examples of non-required and or not often used authorize.net data fields
        if( false )
        {
            $aim->addField( 'x_encap_char', '' );
            $aim->addField( 'x_duplicate_window', '0' );

            // itemized order information (currently not yet supported)
            // loop over items and add fields per item
            $aim->addField( 'x_line_item', 'item1<|>golf balls<|><|>2<|>24.99<|>N' );
            $aim->addField( 'x_line_item', 'item2<|>golf balls<|><|>3<|>42.00<|>Y' );

            // level2 fields (partialy supported by eZ publish with custom modifications)
            $aim->addField( 'x_po_num', '' );
            $aim->addField( 'x_freight', '' );
            $aim->addField( 'x_duty', '' );
            $aim->addField( 'x_tax_exempt', false );

            $aim->addField( 'x_customer_organization_type', '' );
            $aim->addField( 'x_customer_tax_id', '' );

            $aim->addField( 'x_drivers_license_num', '' );
            $aim->addField( 'x_drivers_license_state', '' );
            //$aim->addField( 'x_drivers_license_dob', '' );

            // Authorize.net supports recurring billing service transactions
            $aim->addField( 'x_recurring_billing', 'NO' );

            // echeck / payment processing of payment via check information (currently not yet supported)
            //
            // wells fargo / payment processing of payment via bank information (currently not yet supported)
        }

        // if( isset( $http->postVariable( 'eZAuthorizeDebugSkip' ) ) or $debug == true )
        // if( true or isset( $http->postVariable( 'eZAuthorizeDebugSkip' ) ) or $debug == true )
        //        if( true or isset( $http->postVariable( 'eZAuthorizeDebugSkip' ) ) or $debug == true )
        //        if( $http->postVariable( 'validate' ) == 'Submit' )
        // user toggled debug display ...

        if( $debug == true )
        {
            print_r("<hr />");
            print_r("<a href='' onclick='history.go(-1); false'><<</a> | reload, press keyboard key sequence, 'ctrl-r' | >> <br />");
            print_r('<hr />');
            /*
            print_r("<form name='ezauthorizeForm' action='checkout' method='post'>Skip Debug, Submit Payment Transaction - <input class='defaultbutton' type='submit' name='validate' value='Submit0' onSubmit='window.location.reload(); return false;' /></form>");
            print_r("<form><input type=submit name='eZAuthorizeDebugReload' value='ReEnter Payment Details' onSubmit='confirm(\"Are you certain?\")' /></form>");
            print_r("<form><input type=submit name='eZAuthorizeDebugReloaded' value='Reload Debug Display' /></form>");
            print_r('<hr />');
            */

            // the whole object
            $d->ezdbugDump($aim);

            // just the string
            $d->ezdbugDump($aim->getFieldString());

            print_r('<hr />');
            die();
        }

        // send payment information to authorize.net
        $aim->sendPayment();
        $response = $aim->getResponse();

        ezDebug::writeDebug( $response, 'eZAuthorizeGateway response'  );

        // Enable MD5Hash Verification
        if ( $ini->variable( 'eZAuthorizeSettings', 'MD5HashVerification' ) == 'true' )
        {
            $md5_hash_secret = $ini->variable( 'eZAuthorizeSettings', 'MD5HashSecretWord' );
            $aim->setMD5String ( $md5_hash_secret, $ini->variable( 'eZAuthorizeSettings', 'MerchantLogin' ), $response['Transaction ID'], $order_total_amount );

            // Enable Optional Debug Output | MD5Hash Compare
            if ( $ini->variable( 'eZAuthorizeSettings', 'Debug' ) == 'true' )
            {
                ezDebug::writeDebug( 'Server md5 hash is ' . $response["MD5 Hash"] . ' and client hash is ' . strtoupper( md5( $aim->getMD5String ) ) . ' from string' . $aim->getMD5String );
            }
            $md5pass = $aim->verifyMD5Hash();
        }
        else
        {
            $md5pass = true;
        }

        if ( $aim->hasError() or !$md5pass)
        {
            if ( !$md5pass )
            {
                $errors[] = 'This transaction has failed to
                verify that the use of a secure transaction (MD5 Hash Failed).
                Please contact the site administrator and inform them of
                this error. Please do not try to resubmit payment.';
            }
                $errors[] = $response['Response Reason Text'];

            // note payment failure
            if( $failStatusCode )
            {
              // $order->modifyStatus( $failStatusCode );
              // $order->setStatus( $failStatusCode );
            }

            return $this->loadForm( $process, $errors );
        }
        else
        {
            // note successful payment
            if( $successStatusCode )
            {
                // $order->modifyStatus( $successStatusCode );
                // $order->setStatus( $successStatusCode );
            }

            ////////////////////////////////////////////////////

            // get order id
            // $order_id = $processParams['order_id'];

            // get order
            // $order = &eZOrder::fetch( $processParams['order_id'] );


            ////////////////////////////////////////////////////
            // Original Authorize.net Payment Transaction Values

            // assign authorize.net transaction id from transaction responce array
            $transaction_id = $response['Transaction ID'];

            // assign card name
            $card_name = trim( $http->postVariable( 'CardName' ) );

            // assign card number
            $card_num = trim( $http->postVariable( 'CardNumber' ) );


            //////////////
            // stuff|encrypt()

            // If transaction storage is enabled
            if ( $ini->variable( 'eZAuthorizeSettings', 'StoreTransactionInformation', 'ezauthorize.ini') == true )
            {
                // if payment storage is enabled
                if ( $ini->variable( 'eZAuthorizeSettings', 'StoreTransactionInformation', 'ezauthorize.ini') == true ) {

                    $b_ini = &eZINI::instance( 'ezgpg.ini' );
                    $key = trim( $b_ini->variable( 'eZGPGSettings', 'KeyID' ) );

                    // load payment storage dependances
                    include_once( 'extension/ezgpg/autoloads/ezgpg_operators.php' );

                    // Create storage wrapper
                    $c = new eZGPGOperators;

                    $s_card_number_encoded = $c->gpgEncode( $card_num, $key, true );
                    $s_card_name_encoded = $c->gpgEncode( $card_name, $key, true );

                    if( $debug )
                    {
                       /*
                        $s_card_name_decoded = $c->gpgDecode( $s_card_name_encoded, $key );
                        $s_card_number_decoded = $c->gpgDecode( $s_card_number_encoded, $key );
                        $s_card_number_decoded = $c->gpgDecode( $s_card_number_encoded, $key, true);
                       */

                       print_r( 'plain text: '. $card_num .'<hr />' );
                       print_r( 'encoded: '.$s_card_number_encoded .'<hr />' );
                       print_r( 'decoded: '.$s_card_number_decoded .'<hr />' );
                       die( '<hr />' );
                    }

                    if ( $s_card_number_encoded )
                    {
                        $card_name = $s_card_name_encoded;
                        $card_num = $s_card_number_encoded;
                    }

                }
            }

            //////////////

            // assign card expiration date
            $card_date = trim( $http->postVariable( 'ExpirationMonth' ) ) . trim( $http->postVariable( 'ExpirationYear' ) );

            // assign card type
            $card_type = strtolower( $http->postVariable( 'CardType' ) );

            ////////////////////////////////////////////////////
            // get order information out of eZXML

            include_once( 'lib/ezxml/classes/ezxml.php' );

            $xmlDoc = $order->attribute( 'data_text_1' );

            if (!$dom = domxml_open_mem($xmlDoc)) {
                echo "Error while parsing the document\n";
                exit;
            }

            // assign shop account handeler payment values
            if( $xmlDoc != null )
            {

              // get dom document
              // $root = $dom->document_element();
              // $root_name = $root->tagname;

              // $root->name = '$shop_account';
              // print_r($root_name .'<br />');
              // x2
              // print_r('<br />');
              // echo $xmlDoc;
              // print_r('<hr />');

              // echo $dom->dump_mem( );

              /////////////////////////////////////////////////////////
              // print_r('<hr />');

              include_once ( 'extension/ezauthorize/classes/mcxml.php' );

              $xml_array = xml2array( $xmlDoc );
              // die( $xmlDoc );

              $xmlvars = array();

              RecursVars( $xml_array, $xmlvars );
              $xmlvars['ezauthorize-transaction-id'] = $transaction_id;

              $xmlvars['ezauthorize-card-name'] = $card_name;

              $xmlvars['ezauthorize-card-number'] = $card_num;

              $xmlvars['ezauthorize-card-date'] = $card_date;

              $xmlvars['ezauthorize-card-type'] = $card_type;

              $xmlDomAppended = array_to_xml( $xmlvars );
				
              print_r( $xmlDomAppended );
              // print_r( $xmlvars );
              // die( $c );

              /////////////////////////////////////////////////////////
              // End?

              /*
              die('<hr />end of line');
              die();
              $cs;
              */

              // create nodes for payment information storage
              /*
              $transaction_id_node = $doc->createElementNode( "ezauthorize-transaction-id" );
              $transaction_id_node->appendChild( $doc->createTextNode( $transaction_id ) );
              $root->appendChild( $transaction_id_node );
              $cs .= $doc->toString();

              $card_name_node = $doc->createElementNode( "ezauthorize-card-name" );
              $card_name_node->appendChild( $doc->createTextNode( $card_name ) );
              $root->appendChild( $card_name_node );
              $cs .= $doc->toString();

              $card_number_node = $doc->createElementNode( "ezauthorize-card-number" );
              $card_number_node->appendChild( $doc->createTextNode( $card_num ) );
              $root->appendChild( $card_number_node );
              $cs .= $doc->toString();

              $card_date_node = $doc->createElementNode( "ezauthorize-card-date" );
              $card_date_node->appendChild( $doc->createTextNode( $card_date ) );
              $root->appendChild( $card_date_node );
              $cs .= $doc->toString();

              // $s = $doc->toString();
              echo( $cs );
             */ 
              // die('<hr />');

              $order->setAttribute( 'data_text_1', $xmlDomAppended );
              $order->store();
              // die();

            }

            ////////////////////////////////////////////////////
            // payment information

            /*
            include_once ( 'extension/ezauthorize/classes/ezpayment.php' );
            $payment = new eZPayment( $response['Transaction ID'] );

            // set Payment Details
            $payment->setTransactionID = $response['Transaction ID'];

            // get the current timestamp
            $currdate = gmdate("Ymd");
            $currday = substr($currdate,6,2);
            $currmonth = substr($currdate,4,2);
            $curryear = substr($currdate,0,4);
            $currdate_stamp = ($curryear . "-" . $currmonth . "-" . $currday);
            $payment->setTransactionDate = $currdate_stamp;

            */

            /*
            $payment->setOrderID = $order_id; // set order id

            $payment->setNumeric = trim( $http->postVariable( 'CardNumber' ) );

            $payment->setDate = $http->postVariable( 'ExpirationMonth' ) . $http->postVariable( 'ExpirationYear' );

            // Legend: #1 = Credit Card, #2 = eCheck, #3 = Paypal, #4 = Other
            $payment->setType = 1;
            */

            // store
            // $payment->store();

            ////////////////////////////////////////////////////

            return EZ_WORKFLOW_TYPE_STATUS_ACCEPTED;
        }
    }

    /*
    TODO:
    This function need fixes it uses hardcoded values from a shop account handler

    Workaround:
    set INI value eZAuthorizeSettings->GetOrderCustomerInformation = false
    */
    function getOrderInfo( $order )
    {
        include_once( 'lib/ezxml/classes/ezxml.php' );

        // get order information out of eZXML
        $xml = new eZXML();
        $xmlDoc = $order->attribute( 'data_text_1' );

        if( $xmlDoc != null )
        {
            $dom = $xml->domTree( $xmlDoc );

            // get shop account handeler map settings
            $ini = eZINI::instance( 'ezauthorize.ini' );

            // check for custom shop handeler settings
            if( $ini->variable( 'eZAuthorizeSettings', 'CustomShopAccountHandeler' ) )
            {
              // set shop account handeler values (dynamicaly)
                // add support for custom values supported like phone and email ...

              $handeler_name_first_name = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerFirstName' );
              $handeler_name_last_name = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerLastName' );
              $handeler_name_email = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerEmail' );
              $handeler_name_street1 = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerStreet1' );
              $handeler_name_street2 = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerStreet2' );
              $handeler_name_zip = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerZip' );
              $handeler_name_place = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerFirstPlace' );
              $handeler_name_state = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerFirstState' );
              $handeler_name_country = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerCountry' );

              $handeler_name_comment = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerComment' );
              $handeler_name_phone = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerAddressPhone' );
              $handeler_name_fax = $ini->variable( 'eZAuthorizeSettings', 'ShopAccountHandelerAddressFax' );

            } else {
              $handeler_name_first_name = 'first-name';
              $handeler_name_last_name = 'last-name';
              $handeler_name_email = 'email';
              $handeler_name_street1 = 'street1';
              $handeler_name_street2 = 'street2';
              $handeler_name_zip = 'zip';
              $handeler_name_place = 'place';
              $handeler_name_state = 'state';
              $handeler_name_country = 'country';
              $handeler_name_comment = 'comment';
            }


            // assign shop account handeler values (now staticly)

            $order_first_name = $dom->elementsByName( $handeler_name_first_name );
            $this->order_first_name = $order_first_name[0]->textContent();

            $order_last_name = $dom->elementsByName( $handeler_name_last_name );
            $this->order_last_name = $order_last_name[0]->textContent();

            $order_email = $dom->elementsByName( $handeler_name_email );
            $this->order_email = $order_email[0]->textContent();

            $order_street1 = $dom->elementsByName( $handeler_name_street1 );
            $this->order_street1 = $order_street1[0]->textContent();

            $this->order_company = '';

            $order_phone = $dom->elementsByName( $handeler_name_phone );
            $this->order_phone = '';
            $this->order_phone = $order_phone[0]->textContent();

            // $order_fax = $dom->elementsByName( $handeler_name_fax );
            // $this->order_fax = $order_fax[0]->textContent();
            $this->order_fax = '';

            $order_street2 = $dom->elementsByName( $handeler_name_street2 );
            $this->order_street2 = $order_street2[0]->textContent();

            $order_zip = $dom->elementsByName( $handeler_name_zip );
            $this->order_zip = $order_zip[0]->textContent();

            $order_place = $dom->elementsByName( $handeler_name_place );
            if( $order_place[0] ) 
		$this->order_place = $order_place[0]->textContent();
		else
		$this->order_place = '';
            $order_state = $dom->elementsByName( $handeler_name_state );
	    if( $order_state[0] )
              $this->order_state = $order_state[0]->textContent();
            else
 	      $this->order_state = '';

	    $order_country = $dom->elementsByName( $handeler_name_country );
            if( $order_country[0] )
	$this->order_country = $order_country[0]->textContent();
	else
	$this->order_country = '';


            /* $order_country = 'United States of America';
            $this->order_country = $order_country;
            */
            /*
            Missing in current custom shop handler

            $order_country = $dom->elementsByName( $handeler_name_country );
            $this->order_country = $order_country[0]->textContent();
            */

            /*
             Note: Seemingly not provided by default???
            $order_comment = $dom->elementsByName( $handeler_name_comment );
            $this->order_comment = $order_comment[0]->textContent();
            */

            /*
            // debug output sent to server from eZ publish to authorize.net web service
            include_once('extension/ezdbug/autoloads/ezdbug.php' );

            $b = new eZDBugOperators();
            $b->ezdbugDump($dom);

            print_r('<hr />');

            die();
            */

            return true;
        }
        return false;
    }

    function addAVS( &$aim ) {
        // customer billing address
        $aim->addField( 'x_first_name', $this->order_first_name );
        $aim->addField( 'x_last_name', $this->order_last_name );
        $aim->addField( 'x_company', $this->order_company );

        // does this match the default?? cause it is wrong with shop account handeler usage !
        // $aim->addField( 'x_address', $this->order_street2 );
        //
        $aim->addField( 'x_address', $this->order_street1 .' '. $this->order_street2 );

        $aim->addField( 'x_city', $this->order_place );
        $aim->addField( 'x_state', $this->order_state );
        $aim->addField( 'x_zip', $this->order_zip );
        $aim->addField( 'x_country', str_replace( " ", "%20", $this->order_country ) );

    }

    function addShipping( &$aim ) {
        // customer shipping address
        $aim->addField( 'x_ship_to_first_name', $this->order_first_name );
        $aim->addField( 'x_ship_to_last_name', $this->order_last_name );
        $aim->addField( 'x_ship_to_company', $this->order_company );

        // does this match the default?? cause it is wrong with shop account handeler usage !
        // $aim->addField( 'x_ship_to_address', $this->order_street2 );
        //
        $aim->addField( 'x_ship_to_address', $this->order_street1 .' '. $this->order_street2 );
        $aim->addField( 'x_ship_to_city', $this->order_place );
        $aim->addField( 'x_ship_to_state', $this->order_state );
        $aim->addField( 'x_ship_to_zip', $this->order_zip );
        $aim->addField( 'x_ship_to_country', str_replace( " ", "%20", $this->order_country ) );
     }
}

eZPaymentGatewayType::registerGateway( EZ_PAYMENT_GATEWAY_TYPE_EZAUTHORIZE, "ezauthorizegateway", "Authorize.Net" );

?>
