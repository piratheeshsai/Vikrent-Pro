<?php
/**
 * CyberSource REST API Client for Unified Checkout
 * Handles HTTP Signature (HMAC-SHA256) authentication, Capture Context generation,
 * and Payment Authorization via transientTokenJwt.
 *
 * @package VikRentCar_CyberSource
 */

defined('ABSPATH') || exit;

class VRC_CyberSource_REST
{
    const HOST_SANDBOX = 'apitest.cybersource.com';
    const HOST_LIVE    = 'api.cybersource.com';

    const ENDPOINT_SESSIONS        = '/uc/v1/sessions';
    const ENDPOINT_CAPTURE_CONTEXT = '/up/v1/capture-contexts';
    const ENDPOINT_PAYMENTS        = '/pts/v2/payments';

    private $merchantId;
    private $keyId;
    private $secretKey;
    private $host;

    public function __construct($merchantId, $keyId, $secretKey, $mode = 'sandbox')
    {
        $this->merchantId = trim((string) $merchantId);
        $this->keyId      = trim((string) $keyId);
        $this->secretKey  = trim((string) $secretKey);
        $this->host       = ($mode === 'live') ? self::HOST_LIVE : self::HOST_SANDBOX;
    }

    /**
     * Generate a Unified Checkout Capture Context JWT from CyberSource.
     *
     * @param array $args Order & configuration details.
     * @return array ['success' => bool, 'capture_context' => string, 'client_library' => string, 'client_library_integrity' => string, 'error' => string]
     */
    public function createCaptureContext(array $args)
    {
        if (empty($this->merchantId) || empty($this->keyId) || empty($this->secretKey)) {
            return array(
                'success' => false,
                'error'   => 'CyberSource REST API credentials (Merchant ID, Key ID, or Shared Secret) are missing.',
            );
        }

        $targetOrigin = !empty($args['target_origin']) ? $args['target_origin'] : self::getSiteOrigin();
        $amount       = number_format((float) $args['amount'], 2, '.', '');
        $currency     = !empty($args['currency']) ? strtoupper($args['currency']) : 'EUR';
        $country      = !empty($args['country']) ? strtoupper(substr($args['country'], 0, 2)) : 'SC';

        $allowedPaymentTypes = array('PANENTRY');
        if (!empty($args['enable_applepay'])) {
            $allowedPaymentTypes[] = 'APPLEPAY';
        }
        if (!empty($args['enable_googlepay'])) {
            $allowedPaymentTypes[] = 'GOOGLEPAY';
        }

        $allowedCardNetworks = array('VISA', 'MASTERCARD');
        if (!empty($args['enable_amex'])) {
            $allowedCardNetworks[] = 'AMEX';
        }

        $billTo = array(
            'firstName' => !empty($args['first_name']) ? substr($args['first_name'], 0, 60) : 'Customer',
            'lastName'  => !empty($args['last_name']) ? substr($args['last_name'], 0, 60) : 'Rental',
            'email'     => !empty($args['email']) ? $args['email'] : 'customer@example.com',
            'country'   => $country,
        );
        if (!empty($args['phone'])) {
            $billTo['phoneNumber'] = substr($args['phone'], 0, 15);
        }
        if (!empty($args['address'])) {
            $billTo['address1'] = substr($args['address'], 0, 60);
        }
        if (!empty($args['city'])) {
            $billTo['locality'] = substr($args['city'], 0, 50);
        }
        if (!empty($args['postal_code'])) {
            $billTo['postalCode'] = substr($args['postal_code'], 0, 10);
        }

        $v1Data = array(
            'orderInformation' => array(
                'amountDetails' => array(
                    'totalAmount' => $amount,
                    'currency'    => $currency,
                ),
                'billTo' => $billTo,
            ),
        );

        if (!empty($args['reference'])) {
            $v1Data['clientReferenceInformation'] = array(
                'code' => (string) $args['reference'],
            );
        }

        // Clean Unified Checkout v1 payload — uses Business Center configuration (Sale, 3DS, Fraud, Apple Pay, Google Pay)
        $v1Payload = array(
            'targetOrigins' => array($targetOrigin),
            'country'       => $country,
            'locale'        => 'en_US',
            'data'          => $v1Data,
        );

        // Primary: Unified Checkout v1 (/uc/v1/sessions)
        $response = $this->post(self::ENDPOINT_SESSIONS, $v1Payload);

        // Fallback to /up/v1/capture-contexts (0.23) if v1 session is not yet configured/published on this MID
        if (!$response['success']) {
            $v0Payload = array(
                'targetOrigins'       => array($targetOrigin),
                'clientVersion'       => '0.23',
                'allowedCardNetworks' => $allowedCardNetworks,
                'allowedPaymentTypes' => $allowedPaymentTypes,
                'country'             => $country,
                'locale'              => 'en_US',
                'captureMandate'      => array(
                    'billingType'              => 'PARTIAL',
                    'requestEmail'             => false,
                    'requestPhone'             => false,
                    'requestShipping'          => false,
                    'showAcceptedNetworkIcons' => true,
                ),
                'orderInformation'    => array(
                    'amountDetails' => array(
                        'totalAmount' => $amount,
                        'currency'    => $currency,
                    ),
                ),
            );
            $response = $this->post(self::ENDPOINT_CAPTURE_CONTEXT, $v0Payload);
        }

        if (!$response['success']) {
            return array(
                'success' => false,
                'error'   => $response['error'],
            );
        }

        // CyberSource returns the raw JWT string in the body (occasionally quoted)
        $jwt = trim($response['body'], " \t\n\r\0\x0B\"'");

        if (substr_count($jwt, '.') !== 2) {
            return array(
                'success' => false,
                'error'   => 'Unexpected Capture Context format returned by CyberSource.',
            );
        }

        $meta = self::extractJwtClientLibrary($jwt);

        return array(
            'success'                  => true,
            'capture_context'          => $jwt,
            'client_library'           => $meta['clientLibrary'],
            'client_library_integrity' => $meta['clientLibraryIntegrity'],
            'error'                    => '',
        );
    }

    /**
     * Authorize and capture a payment using the Unified Checkout transient token JWT.
     *
     * @param string $transientToken Transient token returned by Unified Checkout JS SDK.
     * @param array  $orderData      Order & customer billing details.
     * @return array
     */
    public function processPayment($transientToken, array $orderData)
    {
        if (empty($transientToken)) {
            return array(
                'success' => false,
                'status'  => 'ERROR',
                'message' => 'Missing transient payment token.',
            );
        }

        $amount   = number_format((float) $orderData['amount'], 2, '.', '');
        $currency = !empty($orderData['currency']) ? strtoupper($orderData['currency']) : 'EUR';
        $refCode  = !empty($orderData['reference']) ? (string) $orderData['reference'] : uniqid('vrc_');

        $billTo = array(
            'firstName' => !empty($orderData['first_name']) ? substr($orderData['first_name'], 0, 60) : 'Customer',
            'lastName'  => !empty($orderData['last_name']) ? substr($orderData['last_name'], 0, 60) : 'Rental',
            'email'     => !empty($orderData['email']) ? $orderData['email'] : 'customer@example.com',
        );

        if (!empty($orderData['phone'])) {
            $billTo['phoneNumber'] = substr($orderData['phone'], 0, 15);
        }
        if (!empty($orderData['address'])) {
            $billTo['address1'] = substr($orderData['address'], 0, 60);
        }
        if (!empty($orderData['city'])) {
            $billTo['locality'] = substr($orderData['city'], 0, 50);
        }
        if (!empty($orderData['postal_code'])) {
            $billTo['postalCode'] = substr($orderData['postal_code'], 0, 10);
        }
        if (!empty($orderData['country'])) {
            $billTo['country'] = strtoupper(substr($orderData['country'], 0, 2));
        }

        $payload = array(
            'clientReferenceInformation' => array(
                'code' => $refCode,
            ),
            'processingInformation' => array(
                'capture'           => true,
                'commerceIndicator' => 'internet',
            ),
            'orderInformation' => array(
                'amountDetails' => array(
                    'totalAmount' => $amount,
                    'currency'    => $currency,
                ),
                'billTo' => $billTo,
            ),
            'tokenInformation' => array(
                'transientTokenJwt' => $transientToken,
            ),
        );

        $response = $this->post(self::ENDPOINT_PAYMENTS, $payload);
        $decoded  = json_decode($response['body'], true);

        if (!$response['success']) {
            $errMsg = $response['error'];
            if (is_array($decoded)) {
                if (!empty($decoded['errorInformation']['message'])) {
                    $errMsg = $decoded['errorInformation']['message'];
                } elseif (!empty($decoded['message'])) {
                    $errMsg = $decoded['message'];
                }
            }

            return array(
                'success'     => false,
                'status'      => is_array($decoded) && !empty($decoded['status']) ? $decoded['status'] : 'ERROR',
                'reason_code' => is_array($decoded) && !empty($decoded['errorInformation']['reason']) ? $decoded['errorInformation']['reason'] : (string) $response['http_code'],
                'message'     => $errMsg,
                'txn_id'      => is_array($decoded) && !empty($decoded['id']) ? $decoded['id'] : '',
                'raw'         => $decoded,
            );
        }

        $status    = !empty($decoded['status']) ? strtoupper($decoded['status']) : 'UNKNOWN';
        $txnId     = !empty($decoded['id']) ? $decoded['id'] : '';
        $authAmt   = isset($decoded['orderInformation']['amountDetails']['authorizedAmount'])
            ? (float) $decoded['orderInformation']['amountDetails']['authorizedAmount']
            : (float) $amount;
        $respCurr  = !empty($decoded['orderInformation']['amountDetails']['currency'])
            ? strtoupper($decoded['orderInformation']['amountDetails']['currency'])
            : $currency;
        $reason    = !empty($decoded['errorInformation']['reason']) ? $decoded['errorInformation']['reason'] : '';
        $message   = !empty($decoded['errorInformation']['message']) ? $decoded['errorInformation']['message'] : '';

        $approvedStatuses = array('AUTHORIZED', 'PENDING', 'AUTHORIZED_PENDING_REVIEW');
        $isApproved       = in_array($status, $approvedStatuses, true);

        return array(
            'success'     => $isApproved,
            'status'      => $status,
            'txn_id'      => $txnId,
            'amount'      => $authAmt,
            'currency'    => $respCurr,
            'reason_code' => $reason,
            'message'     => $message,
            'raw'         => $decoded,
        );
    }

    /**
     * Send an HTTP Signature authenticated POST request to CyberSource REST API.
     *
     * @param string $resourcePath e.g. /up/v1/capture-contexts
     * @param array  $payloadData
     * @return array
     */
    private function post($resourcePath, array $payloadData)
    {
        $jsonPayload = wp_json_encode($payloadData, JSON_UNESCAPED_SLASHES);
        $digestHash  = base64_encode(hash('sha256', $jsonPayload, true));
        $digest      = 'SHA-256=' . $digestHash;
        $dateHeader  = gmdate('D, d M Y H:i:s') . ' GMT';

        $signingString = "host: {$this->host}\n"
            . "date: {$dateHeader}\n"
            . "request-target: post {$resourcePath}\n"
            . "digest: {$digest}\n"
            . "v-c-merchant-id: {$this->merchantId}";

        $decodedSecret = base64_decode($this->secretKey);
        $signatureHash = base64_encode(hash_hmac('sha256', $signingString, $decodedSecret, true));

        $signatureHeader = sprintf(
            'keyid="%s", algorithm="HmacSHA256", headers="host date request-target digest v-c-merchant-id", signature="%s"',
            $this->keyId,
            $signatureHash
        );

        $url     = 'https://' . $this->host . $resourcePath;
        $headers = array(
            'Content-Type'    => 'application/json',
            'Host'            => $this->host,
            'Date'            => $dateHeader,
            'Digest'          => $digest,
            'v-c-merchant-id' => $this->merchantId,
            'Signature'       => $signatureHeader,
        );

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $jsonPayload,
        ));

        if (is_wp_error($response)) {
            return array(
                'success'   => false,
                'http_code' => 0,
                'body'      => '',
                'error'     => 'Connection error to CyberSource: ' . $response->get_error_message(),
            );
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $body     = wp_remote_retrieve_body($response);

        if ($httpCode >= 200 && $httpCode < 300) {
            return array(
                'success'   => true,
                'http_code' => $httpCode,
                'body'      => $body,
                'error'     => '',
            );
        }

        $decoded = json_decode($body, true);
        $errText = "CyberSource API error (HTTP {$httpCode})";
        if (is_array($decoded)) {
            if (!empty($decoded['message'])) {
                $errText .= ': ' . $decoded['message'];
            } elseif (!empty($decoded['response']['rmsg'])) {
                $errText .= ': ' . $decoded['response']['rmsg'];
            }
            if (!empty($decoded['details']) && is_array($decoded['details'])) {
                $detailParts = array();
                foreach ($decoded['details'] as $d) {
                    $field  = !empty($d['field']) ? $d['field'] : '';
                    $reason = !empty($d['reason']) ? $d['reason'] : (!empty($d['message']) ? $d['message'] : '');
                    if ($field || $reason) {
                        $detailParts[] = trim($field . ' ' . $reason);
                    }
                }
                if (!empty($detailParts)) {
                    $errText .= ' [' . implode('; ', $detailParts) . ']';
                }
            } elseif (!empty($body)) {
                $errText .= ' — ' . substr(strip_tags($body), 0, 250);
            }
        }

        return array(
            'success'   => false,
            'http_code' => $httpCode,
            'body'      => $body,
            'error'     => $errText,
        );
    }

    /**
     * Extract clientLibrary and clientLibraryIntegrity from the Capture Context JWT payload.
     */
    public static function extractJwtClientLibrary($jwt)
    {
        $result = array(
            'clientLibrary'          => '',
            'clientLibraryIntegrity' => '',
        );

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return $result;
        }

        $payloadB64 = strtr($parts[1], '-_', '+/');
        $padLen     = strlen($payloadB64) % 4;
        if ($padLen > 0) {
            $payloadB64 .= str_repeat('=', 4 - $padLen);
        }

        $decoded = json_decode(base64_decode($payloadB64), true);
        if (!is_array($decoded)) {
            return $result;
        }

        if (!empty($decoded['ctx'][0]['data']['clientLibrary'])) {
            $result['clientLibrary'] = $decoded['ctx'][0]['data']['clientLibrary'];
        } elseif (!empty($decoded['clientLibrary'])) {
            $result['clientLibrary'] = $decoded['clientLibrary'];
        }

        if (!empty($decoded['ctx'][0]['data']['clientLibraryIntegrity'])) {
            $result['clientLibraryIntegrity'] = $decoded['ctx'][0]['data']['clientLibraryIntegrity'];
        } elseif (!empty($decoded['clientLibraryIntegrity'])) {
            $result['clientLibraryIntegrity'] = $decoded['clientLibraryIntegrity'];
        }

        return $result;
    }

    /**
     * Decode Unified Checkout completeMandate response JWT (used when 3DS is orchestrated by SDK).
     */
    public static function parseCompleteResponseJwt($jwt, $fallbackAmount = 0.0, $fallbackCurrency = 'EUR')
    {
        $parts = explode('.', trim((string) $jwt));
        if (count($parts) !== 3) {
            return array('success' => false, 'status' => 'ERROR', 'message' => 'Invalid Unified Checkout completion token.');
        }

        $payloadB64 = strtr($parts[1], '-_', '+/');
        $padLen     = strlen($payloadB64) % 4;
        if ($padLen > 0) {
            $payloadB64 .= str_repeat('=', 4 - $padLen);
        }

        $decoded = json_decode(base64_decode($payloadB64), true);
        if (!is_array($decoded)) {
            return array('success' => false, 'status' => 'ERROR', 'message' => 'Unable to parse Unified Checkout completion response.');
        }

        $details  = isset($decoded['details']) && is_array($decoded['details']) ? $decoded['details'] : $decoded;
        $status   = !empty($details['status']) ? strtoupper($details['status']) : (!empty($decoded['status']) ? strtoupper($decoded['status']) : 'UNKNOWN');
        $txnId    = !empty($details['id']) ? $details['id'] : (!empty($decoded['id']) ? $decoded['id'] : '');
        $authAmt  = isset($details['orderInformation']['amountDetails']['authorizedAmount'])
            ? (float) $details['orderInformation']['amountDetails']['authorizedAmount']
            : (float) $fallbackAmount;
        $respCurr = !empty($details['orderInformation']['amountDetails']['currency'])
            ? strtoupper($details['orderInformation']['amountDetails']['currency'])
            : strtoupper($fallbackCurrency);
        $reason   = !empty($details['errorInformation']['reason']) ? $details['errorInformation']['reason'] : '';
        $message  = !empty($details['errorInformation']['message']) ? $details['errorInformation']['message'] : '';

        $approvedStatuses = array('AUTHORIZED', 'PENDING', 'AUTHORIZED_PENDING_REVIEW');

        return array(
            'success'     => in_array($status, $approvedStatuses, true),
            'status'      => $status,
            'txn_id'      => $txnId,
            'amount'      => $authAmt,
            'currency'    => $respCurr,
            'reason_code' => $reason,
            'message'     => $message,
        );
    }

    /**
     * Build the exact site origin (scheme://host[:port]) for targetOrigins.
     */
    public static function getSiteOrigin()
    {
        $parsed = wp_parse_url(home_url());
        $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] : (is_ssl() ? 'https' : 'http');
        $host   = !empty($parsed['host']) ? $parsed['host'] : '';
        $port   = !empty($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
