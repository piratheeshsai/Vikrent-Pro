<?php
/**
 * CyberSource Unified Checkout payment form.
 *
 * Available variables (passed from the driver):
 * @var float  $totalToPay              Order amount
 * @var string $currencySymb            Currency symbol (e.g. €, $)
 * @var string $currencyCode            Currency ISO code (e.g. EUR)
 * @var string $custName                Customer full name
 * @var string $orderSid                Booking reference / SID
 * @var string $orderTs                 Order timestamp
 * @var string $notifyUrl               VikRentCar notification URL
 * @var string $callbackUrl             VikRentCar notification URL with sid & ts
 * @var bool   $isSimulator             True if running in simulator mode
 * @var string $captureContext          Unified Checkout Capture Context JWT
 * @var string $clientLibrary           Dynamic CyberSource JS SDK URL from JWT
 * @var string $clientLibraryIntegrity  SRI integrity hash from JWT
 * @var string $ucError                 Error message if Capture Context failed
 * @var bool   $enableApplePay          Whether Apple Pay is enabled
 * @var bool   $enableGooglePay         Whether Google Pay is enabled
 *
 * @package VikRentCar_CyberSource
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="vrc-cs-container">
    <div class="vrc-cs-topbar">
        <div class="vrc-cs-topbar-left">
            <svg class="vrc-cs-topbar-lock" viewBox="0 0 20 20">
                <path d="M10 2a4 4 0 00-4 4v2H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1V6a4 4 0 00-4-4zm2 6H8V6a2 2 0 114 0v2z"/>
            </svg>
            <span class="vrc-cs-topbar-title">Secure Payment</span>
        </div>
        <div class="vrc-cs-topbar-amount">
            <?php echo esc_html($currencySymb . ' ' . number_format($totalToPay, 2)); ?>
        </div>
    </div>

    <div class="vrc-cs-logos">
        <span class="vrc-cs-logo-text visa">VISA</span>
        <span class="vrc-cs-logo-text mc">Mastercard</span>
        <?php if (!empty($enableApplePay)) : ?>
            <span class="vrc-cs-logo-text" style="color:#000; border:1px solid #d1d5db; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:3px;">
                <svg width="10" height="12" viewBox="0 0 14 17" fill="currentColor" aria-hidden="true"><path d="M11.6 9.0c0-1.9 1.5-2.8 1.6-2.9-.9-1.3-2.3-1.5-2.8-1.5-1.2-.1-2.3.7-2.9.7-.6 0-1.5-.7-2.5-.7-1.3 0-2.5.8-3.1 1.9-1.3 2.3-.3 5.7 1 7.6.6.9 1.4 2 2.4 1.9 1 0 1.3-.6 2.5-.6 1.2 0 1.5.6 2.5.6 1 0 1.7-.9 2.3-1.8.7-1.1 1-2.1 1-2.2 0 0-2-.8-2-3zM9.6 3.3c.5-.7.9-1.6.8-2.5-.8 0-1.7.5-2.3 1.2-.5.6-.9 1.5-.8 2.4.9.1 1.8-.4 2.3-1.1z"/></svg>
                Pay
            </span>
        <?php endif; ?>
        <?php if (!empty($enableGooglePay)) : ?>
            <span class="vrc-cs-logo-text" style="color:#3c4043; border:1px solid #d1d5db; padding:1px 7px; border-radius:4px; font-size:11px; font-weight:700;">G Pay</span>
        <?php endif; ?>
        <span style="font-size: 11px; color: #888; margin-left: auto;">CyberSource</span>
    </div>

    <div class="vrc-cs-body">
        <?php if ($isSimulator) : ?>
            <!-- Local Simulator Mode -->
            <div class="vrc-cs-sim-banner">
                <strong>TEST SIMULATOR MODE</strong>
                This is a local simulation. You can confirm this car rental booking without connecting to the bank.
            </div>

            <div style="background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 14px; margin-bottom: 20px; font-size: 13px; line-height: 1.8;">
                <div><strong>Customer:</strong> <?php echo esc_html($custName); ?></div>
                <div><strong>Booking Ref:</strong> <?php echo esc_html($orderSid); ?></div>
                <div><strong>Total Amount:</strong> <?php echo esc_html($currencySymb . ' ' . number_format($totalToPay, 2) . ' ' . $currencyCode); ?></div>
            </div>

            <form method="POST" action="<?php echo esc_url($notifyUrl); ?>">
                <input type="hidden" name="cs_mode" value="simulator" />
                <input type="hidden" name="sid" value="<?php echo esc_attr($orderSid); ?>" />
                <input type="hidden" name="ts" value="<?php echo esc_attr($orderTs); ?>" />

                <button type="submit" class="vrc-cs-btn">
                    Complete Test Booking (Simulator)
                </button>
            </form>

        <?php else : ?>
            <!-- Order summary -->
            <div style="background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 14px; margin-bottom: 18px; font-size: 13px; line-height: 1.8;">
                <div><strong>Customer:</strong> <?php echo esc_html($custName); ?></div>
                <div><strong>Booking Reference:</strong> <?php echo esc_html($orderSid); ?></div>
                <div><strong>Total Due:</strong> <?php echo esc_html($currencySymb . ' ' . number_format($totalToPay, 2) . ' ' . $currencyCode); ?></div>
            </div>

            <?php if (!empty($ucError)) : ?>
                <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px;">
                    <strong>Unable to initialize payment portal:</strong><br/>
                    <?php echo esc_html($ucError); ?>
                </div>
            <?php else : ?>
                <!-- Unified Checkout error banner (JS runtime) -->
                <div id="vrc-cs-uc-error" style="display:none; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px 14px; border-radius:6px; font-size:13px; margin-bottom:14px;"></div>

                <!-- Loading indicator while SDK mounts -->
                <div id="vrc-cs-uc-loading" style="text-align:center; padding:22px 10px; color:#475569; font-size:13px;">
                    Loading secure payment options (Cards, Apple Pay, Google Pay)...
                </div>

                <!-- Unified Checkout Embedded Containers -->
                <div id="vrc-cs-button-list" style="margin-bottom: 14px;"></div>
                <div id="vrc-cs-embedded-container"></div>

                <!-- Submitting overlay -->
                <div id="vrc-cs-uc-processing" style="display:none; text-align:center; padding:20px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; color:#0369a1; font-size:14px; font-weight:600; margin-top:12px;">
                    Authorizing your payment with Absa Bank &amp; CyberSource... Please wait.
                </div>

                <!-- Hidden form to post transient token or completeMandate JWT to VikRentCar notify_url -->
                <form id="vrc-cs-uc-form" method="POST" action="<?php echo esc_url($callbackUrl); ?>">
                    <input type="hidden" id="vrc-cs-transient-token" name="transient_token" value="" />
                    <input type="hidden" id="vrc-cs-complete-response" name="complete_response" value="" />
                    <input type="hidden" name="sid" value="<?php echo esc_attr($orderSid); ?>" />
                    <input type="hidden" name="ts" value="<?php echo esc_attr($orderTs); ?>" />
                </form>

                <script>
                (function () {
                    var captureContext = <?php echo wp_json_encode($captureContext); ?>;
                    var clientLibrary  = <?php echo wp_json_encode($clientLibrary); ?>;
                    var integrityHash  = <?php echo wp_json_encode($clientLibraryIntegrity); ?>;
                    var enable3ds      = <?php echo !empty($enable3ds) ? 'true' : 'false'; ?>;

                    var loadingEl        = document.getElementById('vrc-cs-uc-loading');
                    var errorEl          = document.getElementById('vrc-cs-uc-error');
                    var processingEl     = document.getElementById('vrc-cs-uc-processing');
                    var tokenInput       = document.getElementById('vrc-cs-transient-token');
                    var completeResInput = document.getElementById('vrc-cs-complete-response');
                    var ucForm           = document.getElementById('vrc-cs-uc-form');

                    function showError(msg) {
                        if (loadingEl) loadingEl.style.display = 'none';
                        if (processingEl) processingEl.style.display = 'none';
                        if (errorEl) {
                            errorEl.textContent = msg || 'Unable to load payment checkout. Please refresh and try again.';
                            errorEl.style.display = 'block';
                        }
                    }

                    function submitPaymentResult(token, completeResp) {
                        var jwt = (typeof token === 'string') ? token : (token && (token.transientToken || token.token) ? (token.transientToken || token.token) : '');
                        var compJwt = (typeof completeResp === 'string') ? completeResp : '';

                        if (compJwt && compJwt.split('.').length === 3) {
                            if (processingEl) processingEl.style.display = 'block';
                            completeResInput.value = compJwt;
                            tokenInput.value = '';
                            ucForm.submit();
                            return;
                        }

                        if (!jwt) {
                            showError('Payment token was not received. Please try again.');
                            return;
                        }
                        if (processingEl) processingEl.style.display = 'block';
                        tokenInput.value = jwt;
                        completeResInput.value = '';
                        ucForm.submit();
                    }

                    function loadSdkScript(url, integrity) {
                        return new Promise(function (resolve, reject) {
                            if (!url) {
                                reject(new Error('CyberSource clientLibrary URL is missing from Capture Context.'));
                                return;
                            }
                            var script = document.createElement('script');
                            script.src = url;
                            script.async = true;
                            if (integrity) {
                                script.integrity = integrity;
                                script.crossOrigin = 'anonymous';
                            }
                            script.onload = resolve;
                            script.onerror = function () {
                                reject(new Error('Failed to load CyberSource Unified Checkout library.'));
                            };
                            document.head.appendChild(script);
                        });
                    }

                    async function startUnifiedCheckout() {
                        try {
                            await loadSdkScript(clientLibrary, integrityHash);

                            if (window.VAS && typeof window.VAS.UnifiedCheckout === 'function') {
                                // Unified Checkout v1 (/uc/v1/sessions) with automatic 3DS + Fraud + Sale orchestration
                                var client   = await window.VAS.UnifiedCheckout(captureContext);
                                var checkout = await client.createCheckout({ autoProcessing: true });

                                if (loadingEl) loadingEl.style.display = 'none';

                                var v1Result = await checkout.mount({
                                    paymentSelection: '#vrc-cs-button-list',
                                    paymentScreen: '#vrc-cs-embedded-container'
                                });

                                if (typeof checkout.destroy === 'function') checkout.destroy();
                                if (typeof client.destroy === 'function') client.destroy();

                                submitPaymentResult('', v1Result);
                            } else if (typeof window.Accept === 'function') {
                                // Legacy Unified Checkout v0 fallback
                                var accept = await window.Accept(captureContext);
                                var up     = await accept.unifiedPayments(false);

                                if (loadingEl) loadingEl.style.display = 'none';

                                var showArgs = {
                                    containers: {
                                        paymentSelection: '#vrc-cs-button-list',
                                        paymentScreen: '#vrc-cs-embedded-container'
                                    }
                                };

                                var tt = await up.show(showArgs);
                                var completeResponse = (typeof up.complete === 'function') ? await up.complete(tt) : '';
                                submitPaymentResult(tt, completeResponse);
                            } else {
                                showError('CyberSource Unified Checkout SDK did not initialize properly.');
                            }
                        } catch (err) {
                            console.error('CyberSource Unified Checkout Error:', err);
                            var errMsg = (err && err.message) ? err.message : (typeof err === 'string' ? err : 'Payment checkout was cancelled or failed.');
                            showError(errMsg);
                        }
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', startUnifiedCheckout);
                    } else {
                        startUnifiedCheckout();
                    }
                })();
                </script>
            <?php endif; ?>

            <div class="vrc-cs-security-note">
                <svg viewBox="0 0 20 20">
                    <path d="M10 2a4 4 0 00-4 4v2H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1V6a4 4 0 00-4-4zm2 6H8V6a2 2 0 114 0v2z"/>
                </svg>
                <span>256-bit SSL &bull; Apple Pay &amp; Google Pay &bull; PCI DSS SAQ-A Certified</span>
            </div>
        <?php endif; ?>
    </div>
</div>
