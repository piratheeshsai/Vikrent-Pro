<?php
if (!defined('ABSPATH')) {
    exit;
}

class VRC_CyberSource_Reasons
{
    // Keep the payment provider's codes in one place so they are easy to update.
    private static $reasons = array(
        '100' => 'Successful transaction.',
        '101' => 'The request was missing required fields or contained invalid data.',
        '102' => 'One or more fields contained invalid data.',
        '104' => 'Duplicate transaction detected.',
        '110' => 'Partial amount was approved.',
        '200' => 'The authorization request was approved by the bank but declined by CyberSource.',
        '201' => 'The issuing bank has requested that you contact them.',
        '202' => 'Expired card or invalid expiration date.',
        '203' => 'Card was declined by issuing bank.',
        '204' => 'Insufficient funds available on card.',
        '205' => 'Stolen or lost card reported.',
        '207' => 'Issuing bank unavailable.',
        '208' => 'Inactive card or card not authorized for online transactions.',
        '210' => 'The maximum credit limit for the card has been reached.',
        '211' => 'Invalid CVV / Card Verification Number.',
        '221' => 'The customer matched a negative list.',
        '230' => 'Card Verification Number (CVV) check failed.',
        '231' => 'Invalid account number.',
        '232' => 'Card type is not accepted by the payment processor.',
        '233' => 'General decline by the processor.',
        '234' => 'There is a problem with your CyberSource merchant configuration.',
        '236' => 'Processor failure.',
        '240' => 'The card type is not accepted by the payment processor.',
        '475' => 'Cardholder failed 3D Secure / OTP authentication.',
        '476' => '3D Secure / OTP authentication was cancelled by customer.',
        '520' => 'Authorization request was approved, but could not be captured.',
    );

    public static function translate($code, $defaultMsg = '')
    {
        $code = (string) $code;

        if (!empty($code) && isset(self::$reasons[$code])) {
            return self::$reasons[$code];
        }

        // Use the provider message when available, otherwise show a safe generic message.
        return !empty($defaultMsg) ? $defaultMsg : 'Transaction was not authorized by the issuing bank.';
    }
}