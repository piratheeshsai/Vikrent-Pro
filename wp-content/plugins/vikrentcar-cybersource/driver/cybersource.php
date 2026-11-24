<?php
/**
 * CyberSource Unified Checkout payment driver for VikRentCar Pro.
 * Supports Apple Pay, Google Pay, and Credit/Debit Cards via Capture Context + REST Payments API.
 * Extends standard JPayment gateway adapter.
 */

defined('ABSPATH') || exit;

JLoader::import('adapter.payment.payment');

require_once dirname(__DIR__) . '/includes/class-cybersource-rest.php';
require_once dirname(__DIR__) . '/includes/class-cybersource-reasons.php';

class VikRentCarCyberSourcePayment extends JPayment
{
    private $lastTxnId = '';
    private $lastError = '';

    public function __construct($caller, $order, $params = array())
    {
        parent::__construct($caller, $order, $params);
    }

    /**
     * Define admin settings shown in VikRentCar Payments manager.
     */
    protected function buildAdminParameters()
    {
        return array(
            'mode' => array(
                'type'    => 'select',
                'label'   => 'Environment Mode',
                'options' => array(
                    'simulator' => 'Simulator Mode (Local Testing)',
                    'sandbox'   => 'CyberSource Sandbox',
                    'live'      => 'Live Production (Absa Bank)',
                ),
                'default' => 'simulator',
            ),
            'merchant_id' => array(
                'type'    => 'text',
                'label'   => 'Merchant ID',
                'default' => '',
            ),
            'rest_key_id' => array(
                'type'    => 'text',
                'label'   => 'REST Key ID (Serial Number)',
                'default' => '',
            ),
            'rest_secret_key' => array(
                'type'    => 'password',
                'label'   => 'REST Shared Secret Key',
                'default' => '',
            ),
        );
    }

    /**
     * Extract customer details cleanly from VikRentCar order payload.
     */
    private function extractCustomerData()
    {
        $order = $this->getOrder();

        // Extract customer email (VikRentCar uses custmail in orders table)
        $custEmail = '';
        if (!empty($order['order']['custmail'])) {
            $custEmail = $order['order']['custmail'];
        } elseif (!empty($order['order']['email'])) {
            $custEmail = $order['order']['email'];
        } elseif (!empty($order['custmail'])) {
            $custEmail = $order['custmail'];
        } elseif ($this->get('custmail')) {
            $custEmail = $this->get('custmail');
        } elseif ($this->get('email')) {
            $custEmail = $this->get('email');
        } else {
            $custEmail = 'customer@example.com';
        }

        // Extract customer name
        $custName = '';
        if (!empty($order['order']['nominative'])) {
            $custName = $order['order']['nominative'];
        } elseif (!empty($order['order']['nominativo'])) {
            $custName = $order['order']['nominativo'];
        } elseif (!empty($order['nominative'])) {
            $custName = $order['nominative'];
        } elseif ($this->get('nominative')) {
            $custName = $this->get('nominative');
        } else {
            $custName = 'Valued Customer';
        }

        $nameParts = explode(' ', trim($custName), 2);
        $firstName = !empty($nameParts[0]) ? $nameParts[0] : 'Customer';
        $lastName  = !empty($nameParts[1]) ? $nameParts[1] : 'Rental';

        // Extract billing details for Decision Manager and 3D Secure
        $phone      = !empty($order['order']['phone']) ? $order['order']['phone'] : (!empty($order['phone']) ? $order['phone'] : '');
        $country    = !empty($order['order']['country']) ? $order['order']['country'] : (!empty($order['country']) ? $order['country'] : '');
        $address    = '';
        $city       = '';
        $postalCode = '';

        $custdata = !empty($order['order']['custdata']) ? $order['order']['custdata'] : (!empty($order['custdata']) ? $order['custdata'] : '');
        if (!empty($custdata)) {
            $lines = explode("\n", $custdata);
            foreach ($lines as $line) {
                $parts = explode(':', trim($line), 2);
                if (count($parts) === 2) {
                    $k = strtolower(trim($parts[0]));
                    $v = trim($parts[1]);
                    if ($k === 'address') $address = $v;
                    if ($k === 'city') $city = $v;
                    if ($k === 'zip code' || $k === 'postal code') $postalCode = $v;
                    if ($k === 'phone' && empty($phone)) $phone = $v;
                }
            }
        }

        if (!empty($country)) {
            $c = strtoupper(trim($country));
            if (strlen($c) === 3) {
                $map = array(
                    'SYC' => 'SC', 'LKA' => 'LK', 'USA' => 'US', 'GBR' => 'GB',
                    'FRA' => 'FR', 'DEU' => 'DE', 'ITA' => 'IT', 'ZAF' => 'ZA',
                    'ARE' => 'AE', 'IND' => 'IN', 'CHE' => 'CH', 'AUT' => 'AT',
                );
                $c = isset($map[$c]) ? $map[$c] : substr($c, 0, 2);
            }
            $country = substr($c, 0, 2);
        }

        return array(
            'full_name'   => $custName,
            'first_name'  => $firstName,
            'last_name'   => $lastName,
            'email'       => $custEmail,
            'phone'       => $phone,
            'address'     => $address,
            'city'        => $city,
            'postal_code' => $postalCode,
            'country'     => $country ? $country : 'SC',
        );
    }

    /**
     * Resolve active REST API credentials (supports wp-config.php constants or admin settings).
     */
    private function getRestCredentials()
    {
        $mode       = defined('VRC_CS_MODE') ? VRC_CS_MODE : $this->getParam('mode', get_option('vrc_cs_mode', 'simulator'));
        $merchantId = defined('VRC_CS_MERCHANT_ID') ? VRC_CS_MERCHANT_ID : trim($this->getParam('merchant_id', get_option('vrc_cs_merchant_id', '')));
        $keyId      = defined('VRC_CS_REST_KEY_ID') ? VRC_CS_REST_KEY_ID : trim($this->getParam('rest_key_id', get_option('vrc_cs_rest_key_id', '')));
        $secretKey  = defined('VRC_CS_REST_SECRET_KEY') ? VRC_CS_REST_SECRET_KEY : trim($this->getParam('rest_secret_key', get_option('vrc_cs_rest_secret_key', '')));

        return array(
            'mode'             => $mode,
            'merchant_id'      => $merchantId,
            'rest_key_id'      => $keyId,
            'rest_secret_key'  => $secretKey,
            'enable_applepay'  => (string) $this->getParam('enable_applepay', get_option('vrc_cs_enable_applepay', '1')) === '1',
            'enable_googlepay' => (string) $this->getParam('enable_googlepay', get_option('vrc_cs_enable_googlepay', '1')) === '1',
            'enable_amex'      => (string) $this->getParam('enable_amex', get_option('vrc_cs_enable_amex', '0')) === '1',
            'enable_3ds'       => (string) $this->getParam('enable_3ds', get_option('vrc_cs_enable_3ds', '1')) === '1',
        );
    }

    /**
     * Prepare order parameters, create Capture Context, and display Unified Checkout widget.
     */
    protected function beginTransaction()
    {
        $order        = $this->getOrder();
        $totalToPay   = (float) $this->get('total_to_pay', $this->get('order_total', 0));
        $currencySymb = $this->get('currency_symb', '€');
        $currencyCode = strtoupper($this->get('transaction_currency', 'EUR'));
        $notifyUrl    = $this->get('notify_url');
        $creds        = $this->getRestCredentials();
        $mode         = $creds['mode'];
        $isSimulator  = ($mode === 'simulator');

        $orderSid = isset($order['order']['sid']) ? $order['order']['sid'] : $this->get('sid');
        $orderTs  = isset($order['order']['ts']) ? $order['order']['ts'] : $this->get('ts');
        $customer = $this->extractCustomerData();
        $custName = $customer['full_name'];

        // Enqueue payment card styling
        $cssUrl = plugins_url('assets/cybersource.css', dirname(__FILE__));
        echo '<link rel="stylesheet" href="' . esc_url($cssUrl) . '?v=' . VIKRENTCAR_CYBERSOURCE_VERSION . '" type="text/css" media="all" />';

        $captureContext         = '';
        $clientLibrary          = '';
        $clientLibraryIntegrity = '';
        $ucError                = '';
        $enableApplePay         = $creds['enable_applepay'];
        $enableGooglePay        = $creds['enable_googlepay'];
        $enable3ds              = $creds['enable_3ds'];

        // Build return/notify URL with order identifiers
        $callbackUrl = $notifyUrl;
        $sep         = (strpos($callbackUrl, '?') !== false) ? '&' : '?';
        $callbackUrl .= $sep . 'sid=' . urlencode($orderSid) . '&ts=' . urlencode($orderTs);

        if (!$isSimulator) {
            $rest = new VRC_CyberSource_REST(
                $creds['merchant_id'],
                $creds['rest_key_id'],
                $creds['rest_secret_key'],
                $mode
            );

            $ctxResult = $rest->createCaptureContext(array(
                'reference'        => $orderSid,
                'amount'           => $totalToPay,
                'currency'         => $currencyCode,
                'first_name'       => $customer['first_name'],
                'last_name'        => $customer['last_name'],
                'email'            => $customer['email'],
                'phone'            => $customer['phone'],
                'address'          => $customer['address'],
                'city'             => $customer['city'],
                'postal_code'      => $customer['postal_code'],
                'country'          => $customer['country'],
                'target_origin'    => VRC_CyberSource_REST::getSiteOrigin(),
                'enable_applepay'  => $enableApplePay,
                'enable_googlepay' => $enableGooglePay,
                'enable_amex'      => $creds['enable_amex'],
                'enable_3ds'       => $enable3ds,
            ));

            if ($ctxResult['success']) {
                $captureContext         = $ctxResult['capture_context'];
                $clientLibrary          = $ctxResult['client_library'];
                $clientLibraryIntegrity = $ctxResult['client_library_integrity'];
            } else {
                $ucError = $ctxResult['error'];
            }
        }

        // Render customer-facing UI
        $templatePath = dirname(__DIR__) . '/templates/payment-form.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }

    /**
     * Validate payment callback from CyberSource Unified Checkout or simulator.
     */
    protected function validateTransaction(JPaymentStatus &$status)
    {
        $order      = $this->getOrder();
        $totalToPay = (float) $this->get('total_to_pay', $this->get('order_total', 0));
        $currency   = strtoupper($this->get('transaction_currency', 'EUR'));
        $creds      = $this->getRestCredentials();
        $mode       = $creds['mode'];

        // Prevent duplicate processing if already marked confirmed and paid
        $existingStatus = isset($order['order']['status']) ? strtolower($order['order']['status']) : '';
        $existingPaid   = isset($order['order']['totpaid']) ? (float) $order['order']['totpaid'] : 0.0;
        if ($existingStatus === 'confirmed' && $existingPaid >= $totalToPay && $totalToPay > 0) {
            $status->verified(true);
            $status->paid($existingPaid);
            $status->setLog('Notice: Duplicate callback received for already confirmed booking.');
            return;
        }

        // Handle offline simulator mode
        if ($mode === 'simulator' || (isset($_POST['cs_mode']) && $_POST['cs_mode'] === 'simulator')) {
            $txnId = 'SIM-UC-' . strtoupper(bin2hex(random_bytes(5)));
            $this->lastTxnId = $txnId;

            $log = sprintf(
                "--- CyberSource Unified Checkout (Simulator) ---\nStatus: AUTHORIZED\nTransaction ID: %s\nAmount Paid: %s %s\nTimestamp: %s\nVerified: YES",
                $txnId,
                number_format($totalToPay, 2),
                $currency,
                current_time('mysql')
            );

            $status->verified(true);
            $status->paid($totalToPay);
            $status->setLog($log);
            $status->setData('transaction', array(
                'id'       => $txnId,
                'provider' => 'CyberSource Simulator',
                'amount'   => $totalToPay,
                'currency' => $currency,
            ));
            return;
        }

        // Process Unified Checkout completeMandate response (3DS orchestrated by CyberSource SDK) or transient token
        if (!empty($_POST['complete_response']) || !empty($_POST['transient_token'])) {
            if (!empty($_POST['complete_response'])) {
                $completeJwt = sanitize_text_field(wp_unslash($_POST['complete_response']));
                $result      = VRC_CyberSource_REST::parseCompleteResponseJwt($completeJwt, $totalToPay, $currency);
            } else {
                $transientToken = sanitize_text_field(wp_unslash($_POST['transient_token']));
                $orderSid       = isset($order['order']['sid']) ? $order['order']['sid'] : $this->get('sid');
                $customer       = $this->extractCustomerData();

                $rest = new VRC_CyberSource_REST(
                    $creds['merchant_id'],
                    $creds['rest_key_id'],
                    $creds['rest_secret_key'],
                    $mode
                );

                $result = $rest->processPayment($transientToken, array(
                    'reference'   => $orderSid,
                    'amount'      => $totalToPay,
                    'currency'    => $currency,
                    'first_name'  => $customer['first_name'],
                    'last_name'   => $customer['last_name'],
                    'email'       => $customer['email'],
                    'phone'       => $customer['phone'],
                    'address'     => $customer['address'],
                    'city'        => $customer['city'],
                    'postal_code' => $customer['postal_code'],
                    'country'     => $customer['country'],
                ));
            }

            $this->lastTxnId = !empty($result['txn_id']) ? $result['txn_id'] : '';

            if (!empty($result['success'])) {
                $authAmt  = isset($result['amount']) ? (float) $result['amount'] : $totalToPay;
                $respCurr = !empty($result['currency']) ? $result['currency'] : $currency;

                if ($authAmt < ($totalToPay - 0.05)) {
                    $this->lastError = 'Authorized amount mismatch.';
                    $status->verified(false);
                    $status->setLog(sprintf(
                        'Security Alert: Paid amount (%s %s) does not match required total (%s %s).',
                        $authAmt, $respCurr, $totalToPay, $currency
                    ));
                    return;
                }

                $log = sprintf(
                    "--- CyberSource Unified Checkout Approved ---\nStatus: %s\nTransaction ID: %s\nAmount Paid: %s %s\nTimestamp: %s\nVerified: YES",
                    $result['status'],
                    $this->lastTxnId,
                    number_format($authAmt, 2),
                    $respCurr,
                    current_time('mysql')
                );

                $status->verified(true);
                $status->paid($authAmt);
                $status->setLog($log);
                $status->setData('transaction', array(
                    'id'       => $this->lastTxnId,
                    'provider' => 'CyberSource Unified Checkout',
                    'amount'   => $authAmt,
                    'currency' => $respCurr,
                ));
                return;
            }

            $reasonCode      = !empty($result['reason_code']) ? $result['reason_code'] : '';
            $rawMsg          = !empty($result['message']) ? $result['message'] : '';
            $friendlyReason  = VRC_CyberSource_Reasons::translate($reasonCode, $rawMsg);
            $this->lastError = $friendlyReason;

            $log = sprintf(
                "--- CyberSource Unified Checkout Declined ---\nStatus: %s\nReason: %s\nDetails: %s\nTransaction ID: %s\nTimestamp: %s",
                !empty($result['status']) ? $result['status'] : 'DECLINED',
                $reasonCode,
                $friendlyReason,
                $this->lastTxnId,
                current_time('mysql')
            );

            $status->verified(false);
            $status->setLog($log);
            return;
        }

        $this->lastError = 'Missing payment token from Unified Checkout.';
        $status->verified(false);
        $status->setLog('Error: Missing transient_token parameter from CyberSource Unified Checkout.');
    }

    /**
     * Finalize checkout, enqueue flash notice, and redirect.
     */
    protected function complete($res)
    {
        $app = JFactory::getApplication();
        if ($res) {
            $msg = 'Payment Successful! Your booking is confirmed and approved by CyberSource.';
            if (!empty($this->lastTxnId)) {
                $msg .= ' (Transaction ID: ' . $this->lastTxnId . ')';
            }
            $app->enqueueMessage($msg, 'message');

            $returnUrl = $this->get('return_url');
            if (!empty($returnUrl)) {
                $app->redirect($returnUrl);
                exit;
            }
        } else {
            $userMsg = !empty($this->lastError) ? $this->lastError : 'Transaction was not approved.';
            $app->enqueueMessage('Payment was not completed: ' . $userMsg . ' Please try again or use another payment method.', 'error');

            $errorUrl = $this->get('error_url');
            if (!empty($errorUrl)) {
                $app->redirect($errorUrl);
                exit;
            }
        }
    }

    public function isRefundSupported()
    {
        return false;
    }
}
