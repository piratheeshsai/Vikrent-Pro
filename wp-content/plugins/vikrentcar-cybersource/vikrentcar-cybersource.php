<?php
/**
 * Plugin Name: VikRentCar CyberSource Gateway
 * Plugin URI:  https://valuecarseychelles.com
 * Description: CyberSource Unified Checkout (Apple Pay, Google Pay & Cards) payment gateway for VikRentCar Pro.
 * Version:     3.0.0
 * Author:      Value Car Development Team
 * Text Domain: vikrentcar-cybersource
 */

defined('ABSPATH') || exit;

define('VIKRENTCAR_CYBERSOURCE_VERSION', '3.0.0');
define('VIKRENTCAR_CYBERSOURCE_DIR', plugin_dir_path(__FILE__));
define('VIKRENTCAR_CYBERSOURCE_URL', plugin_dir_url(__FILE__));

// Load core helper modules
require_once VIKRENTCAR_CYBERSOURCE_DIR . 'includes/class-cybersource-rest.php';
require_once VIKRENTCAR_CYBERSOURCE_DIR . 'includes/class-cybersource-signer.php';
require_once VIKRENTCAR_CYBERSOURCE_DIR . 'includes/class-cybersource-reasons.php';

// Register payment gateway driver with VikRentCar
add_filter('get_supported_payments_vikrentcar', function($drivers) {
    if (!is_array($drivers)) {
        $drivers = array();
    }
    $drivers[] = 'cybersource';
    return array_unique($drivers);
});

// Load the payment driver class when requested by VikRentCar dispatcher
add_action('load_payment_gateway_vikrentcar', function(&$drivers, $payment) {
    if ($payment === 'cybersource' || $payment === 'cybersource.php') {
        require_once VIKRENTCAR_CYBERSOURCE_DIR . 'driver/cybersource.php';
        $drivers[] = 'VikRentCarCyberSourcePayment';
    }
}, 10, 2);

// Synchronize gateway settings with VikRentCar's payment table
function vrc_cs_ensure_database_record() {
    global $wpdb;
    $table = $wpdb->prefix . 'vikrentcar_gpayments';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return;
    }

    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE `file` = %s", 'cybersource.php'));

    $title            = get_option('vrc_cs_title', 'Card / Apple Pay / Google Pay (Absa / CyberSource)');
    $mode             = get_option('vrc_cs_mode', 'simulator');
    $enabled          = get_option('vrc_cs_enabled', '1');
    $merchant_id      = get_option('vrc_cs_merchant_id', '');
    $rest_key_id      = get_option('vrc_cs_rest_key_id', '');
    $rest_secret_key  = get_option('vrc_cs_rest_secret_key', '');
    $enable_applepay  = get_option('vrc_cs_enable_applepay', '1');
    $enable_googlepay = get_option('vrc_cs_enable_googlepay', '1');
    $enable_amex      = get_option('vrc_cs_enable_amex', '0');
    $enable_3ds       = get_option('vrc_cs_enable_3ds', '1');

    $params = json_encode(array(
        'mode'             => $mode,
        'merchant_id'      => $merchant_id,
        'rest_key_id'      => $rest_key_id,
        'rest_secret_key'  => $rest_secret_key,
        'enable_applepay'  => $enable_applepay,
        'enable_googlepay' => $enable_googlepay,
        'enable_amex'      => $enable_amex,
        'enable_3ds'       => $enable_3ds,
    ));

    $note = '<p>Pay securely with Visa, Mastercard, Apple Pay, or Google Pay powered by Absa Bank & CyberSource Unified Checkout.</p>';

    if (!$existing) {
        $wpdb->insert($table, array(
            'name'         => $title,
            'file'         => 'cybersource.php',
            'published'    => ($enabled === '1' ? 1 : 0),
            'note'         => $note,
            'charge'       => 0.00,
            'setconfirmed' => 0,
            'shownotealw'  => 0,
            'val_pcent'    => 1,
            'ch_disc'      => 1,
            'params'       => $params,
            'ordering'     => 1,
            'outposition'  => 'top',
        ));
    } else {
        $wpdb->update($table, array(
            'name'         => $title,
            'published'    => ($enabled === '1' ? 1 : 0),
            'note'         => $note,
            'params'       => $params,
        ), array('id' => $existing->id));
    }
}
add_action('init', 'vrc_cs_ensure_database_record');

// Register and configure dedicated "Online Booking Admin" role for client
function vrc_cs_register_booking_admin_role() {
    $cap = 'manage_booking_gateway';

    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap($cap)) {
        $admin->add_cap($cap);
    }

    if (!get_role('online_booking_admin')) {
        add_role(
            'online_booking_admin',
            'Online Booking Admin',
            array(
                'read'                   => true,
                'upload_files'           => true,
                'manage_booking_gateway' => true,
                'com_vikrentcar_manage'  => true,
            )
        );
    }

    $role = get_role('online_booking_admin');
    if ($role) {
        $role->add_cap('read');
        $role->add_cap('upload_files');
        $role->add_cap($cap);
        $role->add_cap('com_vikrentcar_manage');
    }
}
add_action('init', 'vrc_cs_register_booking_admin_role');

// Real-time capability check (guarantees WordPress never shows "not allowed")
add_filter('user_has_cap', function($allcaps, $caps, $args, $user) {
    if ($user && in_array('online_booking_admin', (array) $user->roles)) {
        $allcaps['read']                   = true;
        $allcaps['upload_files']            = true;
        $allcaps['manage_booking_gateway'] = true;
        $allcaps['com_vikrentcar_manage']  = true;
    }
    return $allcaps;
}, 10, 4);


// Add settings page under VikRentCar admin menu
add_action('admin_menu', function() {
    // Allow users with VikRentCar management permission or administrators
    $cap = current_user_can('com_vikrentcar_manage') ? 'com_vikrentcar_manage' : (current_user_can('manage_booking_gateway') ? 'manage_booking_gateway' : 'manage_options');

    if (class_exists('VikRentCarBody')) {
        add_submenu_page(
            'vikrentcar',
            'Dashboard',
            'Dashboard',
            $cap,
            'vikrentcar',
            array('VikRentCarBody', 'getHtml')
        );
    }

    add_submenu_page(
        'vikrentcar',
        'CyberSource Gateway',
        'CyberSource Gateway',
        $cap,
        'vikrentcar-cybersource',
        'vrc_cs_render_admin_page'
    );

    add_options_page(
        'VikRentCar CyberSource',
        'CyberSource VikRentCar',
        'manage_booking_gateway',
        'vikrentcar-cybersource',
        'vrc_cs_render_admin_page'
    );
}, 99);

// Render the CyberSource configuration page
function vrc_cs_render_admin_page() {
    if (!current_user_can('manage_options') && !current_user_can('com_vikrentcar_manage') && !current_user_can('manage_booking_gateway')) {
        return;
    }

    $saved = false;
    if (isset($_POST['vrc_cs_save_settings']) && check_admin_referer('vrc_cs_save_action', 'vrc_cs_nonce')) {
        update_option('vrc_cs_enabled', isset($_POST['vrc_cs_enabled']) ? '1' : '0');
        update_option('vrc_cs_title', sanitize_text_field($_POST['vrc_cs_title']));
        update_option('vrc_cs_mode', sanitize_text_field($_POST['vrc_cs_mode']));
        update_option('vrc_cs_merchant_id', sanitize_text_field($_POST['vrc_cs_merchant_id']));
        update_option('vrc_cs_rest_key_id', sanitize_text_field($_POST['vrc_cs_rest_key_id']));
        update_option('vrc_cs_rest_secret_key', sanitize_text_field($_POST['vrc_cs_rest_secret_key']));
        update_option('vrc_cs_enable_applepay', isset($_POST['vrc_cs_enable_applepay']) ? '1' : '0');
        update_option('vrc_cs_enable_googlepay', isset($_POST['vrc_cs_enable_googlepay']) ? '1' : '0');
        update_option('vrc_cs_enable_amex', isset($_POST['vrc_cs_enable_amex']) ? '1' : '0');
        update_option('vrc_cs_enable_3ds', isset($_POST['vrc_cs_enable_3ds']) ? '1' : '0');

        vrc_cs_ensure_database_record();
        $saved = true;
    }

    $enabled          = get_option('vrc_cs_enabled', '1');
    $title            = get_option('vrc_cs_title', 'Card / Apple Pay / Google Pay (Absa / CyberSource)');
    $mode             = get_option('vrc_cs_mode', 'simulator');
    $merchant_id      = get_option('vrc_cs_merchant_id', '');
    $rest_key_id      = get_option('vrc_cs_rest_key_id', '');
    $rest_secret_key  = get_option('vrc_cs_rest_secret_key', '');
    $enable_applepay  = get_option('vrc_cs_enable_applepay', '1');
    $enable_googlepay = get_option('vrc_cs_enable_googlepay', '1');
    $enable_amex      = get_option('vrc_cs_enable_amex', '0');
    $enable_3ds       = get_option('vrc_cs_enable_3ds', '1');
    ?>
    <div class="wrap" style="max-width: 900px; margin-top: 25px;">
        <h1 style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <span style="background: #1e3a8a; color: white; padding: 6px 14px; border-radius: 6px; font-size: 16px; font-weight: 700;">CyberSource</span>
            Absa Bank Unified Checkout Gateway
        </h1>
        <p style="color: #64748b; font-size: 14px; margin-top: 0;">
            Accept Visa, Mastercard, Apple Pay, and Google Pay directly inside your checkout via CyberSource Unified Checkout (PCI DSS SAQ-A).
        </p>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #10b981; padding: 12px 18px;">
                <p style="margin: 0; font-size: 14px;"><strong>Settings Saved!</strong> Gateway configuration updated and synced with VikRentCar Pro.</p>
            </div>
        <?php endif; ?>

        <!-- Quick Gateway Status Indicator -->
        <div style="background: #ffffff; padding: 16px 22px; border-radius: 8px; border: 1px solid #e2e8f0; margin: 20px 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong style="color: #1e293b;">Gateway Status:</strong>
                <?php if ($enabled === '1') : ?>
                    <span style="background: #dcfce7; color: #15803d; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 13px; margin-left: 8px;">Active &amp; Published</span>
                <?php else : ?>
                    <span style="background: #fee2e2; color: #b91c1c; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 13px; margin-left: 8px;">Disabled</span>
                <?php endif; ?>
            </div>
            <div style="font-size: 13px;">
                <span style="color: #64748b;">Method:</span>
                <strong style="color: #1e3a8a; margin-right: 15px;">Unified Checkout (Embedded)</strong>
                <span style="color: #64748b;">Mode:</span>
                <strong style="color: #0284c7; text-transform: uppercase;"><?php echo esc_html($mode); ?></strong>
            </div>
        </div>

        <form method="POST" action="">
            <?php wp_nonce_field('vrc_cs_save_action', 'vrc_cs_nonce'); ?>

            <div style="background: #ffffff; padding: 26px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <h3 style="margin-top: 0; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">1. General Settings</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="vrc_cs_enabled">Enable Gateway</label></th>
                        <td>
                            <label style="font-weight: 600; color: #1e293b;">
                                <input type="checkbox" id="vrc_cs_enabled" name="vrc_cs_enabled" value="1" <?php checked($enabled, '1'); ?> />
                                Enable CyberSource at checkout in VikRentCar
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="vrc_cs_title">Title at Checkout</label></th>
                        <td>
                            <input type="text" id="vrc_cs_title" name="vrc_cs_title" value="<?php echo esc_attr($title); ?>" class="regular-text" style="width: 100%; max-width: 450px;" required />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="vrc_cs_mode">Environment Mode</label></th>
                        <td>
                            <select id="vrc_cs_mode" name="vrc_cs_mode" style="width: 100%; max-width: 450px;">
                                <option value="simulator" <?php selected($mode, 'simulator'); ?>>Simulator Mode (Local Testing)</option>
                                <option value="sandbox" <?php selected($mode, 'sandbox'); ?>>CyberSource Sandbox</option>
                                <option value="live" <?php selected($mode, 'live'); ?>>Live Production (Absa Bank)</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 25px; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">2. CyberSource REST API Credentials (Unified Checkout)</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="vrc_cs_merchant_id">Merchant ID</label></th>
                        <td>
                            <input type="text" id="vrc_cs_merchant_id" name="vrc_cs_merchant_id" value="<?php echo esc_attr($merchant_id); ?>" class="regular-text" style="width: 100%; max-width: 450px;" placeholder="e.g. absa_valuecarhire_1309897_eur" />
                            <p class="description">Your CyberSource Organization / Merchant ID (EUR account for Live).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="vrc_cs_rest_key_id">REST Key ID (Serial Number)</label></th>
                        <td>
                            <input type="text" id="vrc_cs_rest_key_id" name="vrc_cs_rest_key_id" value="<?php echo esc_attr($rest_key_id); ?>" class="regular-text" style="width: 100%; max-width: 450px;" placeholder="e.g. b29c3c0a-ee7b-42ac-98a7-6870f7824103" />
                            <p class="description">Generated under Payment Configuration &rarr; Key Management &rarr; REST - Shared Secret.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="vrc_cs_rest_secret_key">REST Shared Secret Key</label></th>
                        <td>
                            <input type="password" id="vrc_cs_rest_secret_key" name="vrc_cs_rest_secret_key" value="<?php echo esc_attr($rest_secret_key); ?>" class="regular-text" style="width: 100%; max-width: 450px;" />
                            <p class="description">The Base64 Shared Secret Key used to sign REST API requests.</p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 25px; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">3. Enabled Payment Methods</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Digital Wallets &amp; Cards</th>
                        <td>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                                <input type="checkbox" name="vrc_cs_enable_applepay" value="1" <?php checked($enable_applepay, '1'); ?> />
                                Enable <strong>Apple Pay</strong> (requires domain verification file in <code>/.well-known/</code>)
                            </label>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                                <input type="checkbox" name="vrc_cs_enable_googlepay" value="1" <?php checked($enable_googlepay, '1'); ?> />
                                Enable <strong>Google Pay</strong>
                            </label>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500;">
                                <input type="checkbox" name="vrc_cs_enable_amex" value="1" <?php checked($enable_amex, '1'); ?> />
                                Enable <strong>American Express</strong> (Visa &amp; Mastercard are always enabled)
                            </label>
                            <label style="display: block; font-weight: 500; color: #0f766e;">
                                <input type="checkbox" name="vrc_cs_enable_3ds" value="1" <?php checked($enable_3ds, '1'); ?> />
                                Enable <strong>3D Secure 2.0 (Payer Authentication / Bank OTP)</strong> — recommended for Live European/International cards
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #f1f5f9;">
                    <button type="submit" name="vrc_cs_save_settings" class="button button-primary" style="padding: 6px 22px; height: auto; font-size: 14px; font-weight: 600;">
                        Save CyberSource Settings
                    </button>
                </p>
            </div>
        </form>
    </div>
    <?php
}

// Clean up the sidebar ONLY for online_booking_admin
add_action('admin_menu', function() {
    $user = wp_get_current_user();

    // If user is NOT a booking admin (i.e. Super Admin), show everything
    if (!$user || !in_array('online_booking_admin', (array) $user->roles)) {
        return;
    }

    // Keep Dashboard, VikRentCar, CyberSource Gateway, and Profile
    $allowed = array('index.php', 'vikrentcar', 'vikrentcar-cybersource', 'profile.php');

    global $menu;
    if (!empty($menu)) {
        foreach ($menu as $item) {
            if (!empty($item[2]) && !in_array($item[2], $allowed)) {
                remove_menu_page($item[2]);
            }
        }
    }
}, 999);

