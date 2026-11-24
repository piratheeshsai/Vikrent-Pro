<?php
/**
 * CyberSource HMAC-SHA256 Signer
 * Handles signature generation for outgoing form data and callback verification.
 */

defined('ABSPATH') || exit;

class VRC_CyberSource_Signer
{
    /**
     * Signs the parameter array using HMAC-SHA256.
     * CyberSource requires keys sorted alphabetically before hashing.
     */
    public static function signParams(array $params, $secretKey)
    {
        // Add signature field keys to the list
        $keysToSign   = array_keys($params);
        $keysToSign[] = 'signed_field_names';
        $keysToSign[] = 'unsigned_field_names';

        // Sort keys alphabetically (required by CyberSource)
        sort($keysToSign);

        $params['signed_field_names']   = implode(',', $keysToSign);
        $params['unsigned_field_names'] = '';

        // Build comma-separated key=value data string
        $dataToSign = array();
        foreach ($keysToSign as $key) {
            $dataToSign[] = $key . '=' . (isset($params[$key]) ? $params[$key] : '');
        }
        $dataToSignString = implode(',', $dataToSign);

        // Generate Base64 encoded HMAC-SHA256 signature
        $params['signature'] = base64_encode(
            hash_hmac('sha256', $dataToSignString, $secretKey, true)
        );

        return $params;
    }

    /**
     * Validates the signature returned in the CyberSource callback.
     * Recalculates expected hash and compares using timing-safe hash_equals.
     */
    public static function verifySignature(array $postData, $secretKey)
    {
        if (empty($postData['signature']) || empty($postData['signed_field_names'])) {
            return false;
        }

        $signedFieldNames = explode(',', $postData['signed_field_names']);
        $dataToSign       = array();

        foreach ($signedFieldNames as $field) {
            $val          = isset($postData[$field]) ? $postData[$field] : '';
            $dataToSign[] = $field . '=' . $val;
        }

        $expectedSig = base64_encode(
            hash_hmac('sha256', implode(',', $dataToSign), $secretKey, true)
        );

        // Timing-safe comparison to prevent timing attacks
        return hash_equals($expectedSig, $postData['signature']);
    }
}