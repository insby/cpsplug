<?php
 /**
 * Plugin Name:       MySpotlight Integration for WooCommerce
 * Plugin URI:        https://github.com/insby/cpsplug
 * Description:       Connect your WooCommerce store with the MySpotlight platform. Automatically synchronize product data, pricing, availability, and orders between systems in real-time. Designed for fast, reliable, and seamless integration — so you can manage your ecommerce from one place
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://spotlight.rs/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cpsplug
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   8.3
 */

 if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('ELIXIR_API_URL', get_option('elixir_spotlight_url') ?: 'https://sl-api.spotlight.rs/api');
define('ELIXIR_API_KEY', get_option('elixir_api_key'));

add_action('profile_update', 'send_user_to_elixir', 10, 2);
add_action('password_reset', 'elixir_sync_password_to_backend', 10, 2);

add_filter('woocommerce_get_shop_coupon_data', 'elixir_dynamic_coupon', 10, 2);

// BENEFITS PART ------------------------------------------------------------------------------------------

function elixir_dynamic_coupon($data, $code) {

    // get customer email
    $email = '';

    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $email = $user->user_email;
    }

    if (!$email && WC()->customer) {
        $email = WC()->customer->get_billing_email();
    }

    if (!$email) return false;

    $token = elixir_ensure_token();
    if (!$token) return false;

    $endpoint = '/v2/int/user/benefits/available?email=' . urlencode($email);

    $response = elixir_request('GET', $endpoint, null, $token);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['data'])) return false;

    $coupon = null;

    foreach ($body['data'] as $c) {
        if (!empty($c['coupon_code']) && $c['coupon_code'] === $code) {
            $coupon = $c;
            break;
        }
    }

    if (!$coupon) return false;

    // expiration check
    if (!empty($coupon['expiration_date'])) {
        if (strtotime($coupon['expiration_date']) < time()) {
            return false;
        }
    }

    // determine discount type
    if ($coupon['coupon_type'] === 'PERCENT') {
        $discount_type = 'percent';
    } elseif ($coupon['coupon_type'] === 'AMOUNT') {
        $discount_type = 'fixed_cart';
    } else {
        return false;
    }

    return [
        'id' => $coupon['benefit_id'],
        'discount_type' => $discount_type,
        'amount' => $coupon['coupon_value'],
        'individual_use' => false,
        'usage_limit' => $coupon['coupon_unlimited'] ? '' : 1,
    ];
}

// --------------------------------------------------------------------------------------------------------

function send_user_to_elixir($user_id, $old_user_data = null) {
    $user = get_userdata($user_id);

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    $first_name =
        get_user_meta($user_id, 'first_name', true) ?:
        get_user_meta($user_id, 'billing_first_name', true) ?:
        'Customer';

    $last_name =
        get_user_meta($user_id, 'last_name', true) ?:
        get_user_meta($user_id, 'billing_last_name', true) ?:
        'Customer';


    $core = [
        'external_ref'  => (string) $user->ID,
        'email'   => $user->user_email,
        'username'   => $user->user_login,
        'first_name'   => $first_name,
        'last_name'    => $last_name
    ];

    $all_meta = get_user_meta($user_id);
    $meta = [];

    foreach ($all_meta as $key => $value) {
    // ❌ Skip unwanted prefixes
        if (
            str_starts_with($key, '_wc_') ||
            str_starts_with($key, 'wc_') ||
            str_starts_with($key, 'wp_') ||
            str_starts_with($key, '_wp_') ||
            str_starts_with($key, 'alg_wc_')
        ) {
            continue;
        }

    $meta[$key] = maybe_unserialize($value[0]);
    }

    unset($meta['wp_capabilities']);
    unset($meta['wp_user_level']); 
    unset($meta['first_name'], $meta['last_name']);
    $meta['phone_number'] = $meta['billing_phone'];
    $payload = ['user' => array_merge($core, $meta)];

    $token = elixir_ensure_token();
    if (!$token) return;             

    elixir_request('PUT', '/v2/int/user', $payload, $token);
}


// USER PART -----------------------------------------------------------------------------  
function elixir_update_user(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $u    = $body['user'] ?? [];

    $email = sanitize_email($u['email'] ?? '');
    $ext   = sanitize_text_field($u['external_ref'] ?? '');
    $password = $u['password'] ?? null;

    if (!$email && !$ext) {
        return new WP_Error('bad_request', 'Missing email or external_ref', ['status' => 400]);
    }

    // 1) find user by external_ref (stored in user meta)
    $uid = 0;
    if ($ext) {
        $q = new WP_User_Query([
            'meta_key'   => 'elixir_external_ref',
            'meta_value' => $ext,
            'number'     => 1,
            'fields'     => 'ID'
        ]);
        $ids = $q->get_results();
        if (!empty($ids)) $uid = (int)$ids[0];
    }

    // 2) fallback: find by email
    if (!$uid && $email) {
        $uid = (int) email_exists($email);
    }

    // 3) create if missing
    if (!$uid) {
        $username = sanitize_user($u['username'] ?? strstr($email, '@', true), true);
        if (username_exists($username)) $username .= '_' . wp_generate_password(4, false, false);

        $uid = wp_insert_user([
            'user_login' => $username,
            'user_pass'  => wp_generate_password(24, true, true), // generated, not shared
            'user_email' => $email,
            'first_name' => sanitize_text_field($u['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($u['last_name'] ?? ''),
            'role'       => 'customer',
        ]);

        if (is_wp_error($uid)) return $uid;

        // Optional: invite user to set password
        // wp_new_user_notification($uid, null, 'user');
    } else {
        // update core fields
        wp_update_user([
            'ID'         => $uid,
            'user_email' => $email ?: get_userdata($uid)->user_email,
            'first_name' => sanitize_text_field($u['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($u['last_name'] ?? ''),
        ]);
    }

    // 4) persist external_ref link
    if ($ext) update_user_meta($uid, 'elixir_external_ref', $ext);

    // 5) Woo billing mapping (recommended)
    $map = [
        'phone_number' => 'billing_phone',
        'address'      => 'billing_address_1',
        'city'         => 'billing_city',
        'country'      => 'billing_country',
        'postcode'     => 'billing_postcode',
    ];
    foreach ($map as $from => $to) {
        if (isset($u[$from])) update_user_meta($uid, $to, sanitize_text_field($u[$from]));
    }

    // 6) your custom meta (optional)
    foreach (['barcode', 'gender', 'parent_name'] as $k) {
        if (isset($u[$k])) update_user_meta($uid, $k, sanitize_text_field($u[$k]));
    }

    // password set
    $password = $u['password'] ?? null;

    if (!empty($password)) {
        wp_set_password($password, $uid);
    }

    return ['success' => true, 'wp_user_id' => (int)$uid];
}

function elixir_sync_password_to_backend($user, $new_pass) {
    // Prevent loop if triggered via REST
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    $payload = [
        'user' => [
            'external_ref' => (string) $user->ID,
            'email'        => $user->user_email,
            'password'     => $new_pass,
        ]
    ];

    $token = elixir_ensure_token();
    if (!$token) return;

    elixir_request('PUT', '/v2/int/user', $payload, $token);
}
// USER PART -----------------------------------------------------------------------------  



// AUTH PART -----------------------------------------------------------------------------  

function elixir_request($method, $endpoint, $body = null, $token = null) {
    $headers = ['Content-Type' => 'application/json',
                'Accept'       => 'application/json'];

    if ($token)  $headers['Authorization'] = "Bearer {$token}";
    else {                       // very first call – use basic
        $apiKey = get_option('elixir_api_key');
        $headers['Authorization'] = 'Basic ' . base64_encode($apiKey);
    }

    $args = [
        'method'  => $method,
        'headers' => $headers,
        'timeout' => 10,
    ];
    if ($body) $args['body'] = wp_json_encode($body);

    return wp_remote_request(ELIXIR_API_URL . $endpoint, $args);
}

function elixir_ensure_token() {
    $token = get_option('elixir_bearer_token');
    $check = elixir_request('GET', '/v2/session/token/check', null, $token);

    if (!is_wp_error($check) && wp_remote_retrieve_response_code($check) === 200)
        return $token;                       // still valid

    // ----------  re-init  ----------
    $hostname = parse_url(get_site_url(), PHP_URL_HOST) ?: 'woo-local';
    $init     = elixir_request('POST', '/v2/init/app', [
        'uuid'   => $hostname,
        'uuidOS' => 'WordPress',
    ]);

    if (is_wp_error($init)) return false;
    $initBody = json_decode(wp_remote_retrieve_body($init), true);
    $initToken = $initBody['data']['token'] ?? false;
    if (!$initToken) return false;

    // ----------  login  ----------
    $loginCreds = [
        'login'      => get_option('elixir_login'),
        'password'   => get_option('elixir_password'),
        'rememberMe' => true,
        'adminLogin' => true,
    ];
    $login = elixir_request('POST', '/v2/session/sign-in', $loginCreds, $initToken);

    if (is_wp_error($login)) return false;
    $loginBody = json_decode(wp_remote_retrieve_body($login), true);
    $newToken  = $loginBody['data']['token'] ?? false;
    if ($newToken) {
        update_option('elixir_bearer_token', $newToken);
        return $newToken;
    }
    return false;
}

function elixir_permission_check(WP_REST_Request $req) {
    $secret = get_option('elixir_api_key');
    $sent   = $req->get_header('x-elixir-secret');
    return $secret && is_string($sent) && hash_equals($secret, $sent);
}

// AUTH PART -----------------------------------------------------------------------------  

add_action('admin_init', function () {
    register_setting('elixir_sync_group', 'elixir_api_key', 'sanitize_text_field');
    register_setting('elixir_sync_group', 'elixir_login', 'sanitize_text_field');
    register_setting('elixir_sync_group', 'elixir_password', 'sanitize_text_field'); 
    register_setting('elixir_sync_group', 'elixir_store_code', 'sanitize_text_field');
    register_setting('elixir_sync_group', 'elixir_spotlight_url', 'esc_url_raw'); 
});

add_action('rest_api_init', function () {
    register_rest_route('elixir/v1', '/user', [
        'methods'  => 'POST',
        'callback' => 'elixir_update_user',
        'permission_callback' => 'elixir_permission_check',
    ]);
});

add_action('rest_api_init', function () {
    register_rest_route('elixir/v1', '/auth', [
        'methods'  => 'POST',
        'callback' => 'elixir_auth_user',
        'permission_callback' => 'elixir_permission_check',
    ]);
});

// add_action('rest_api_init', function () {
//     register_rest_route('elixir/v1', '/user', [
//         'methods'  => 'POST',
//         'callback' => 'elixir_update_user',
//         'permission_callback' => '__return_true' // guard later
//     ]);
// });

add_action('admin_menu', function () {
    add_options_page(
        'Spotlight Settings',      // page title
        'Spotlight Settings',      // menu title
        'manage_options',   // cap
        'cardsprint-sync',      // slug
        'elixir_sync_page'  // render func
    );
});

add_action('woocommerce_update_product', 'send_product_to_elixir', 10, 1);
add_action('woocommerce_order_status_completed', 'send_order_to_elixir');
add_action('woocommerce_new_order', 'send_order_to_elixir'); 
add_action('woocommerce_checkout_order_processed', 'send_order_to_elixir', 10, 3);


// AUTH PART -----------------------------------------------------------------------------

function elixir_auth_user(WP_REST_Request $req) {
    $body = $req->get_json_params();

    $email    = sanitize_text_field($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        return new WP_Error(
            'missing_fields',
            'Email and password are required',
            ['status' => 400]
        );
    }

    // Allow login via email or username
    $user = wp_authenticate($email, $password);

    if (is_wp_error($user)) {
        return new WP_Error(
            'invalid_credentials',
            'Invalid credentials',
            ['status' => 401]
        );
    }

    return [
        'success' => true,
        'user' => [
            'wp_user_id'   => (int) $user->ID,
            'external_ref' => (string) $user->ID,
            'email'        => $user->user_email,
            'username'     => $user->user_login,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name
        ]
    ];
}

// AUTH PART -----------------------------------------------------------------------------

// PRODUCTS PART -----------------------------------------------------------------------------

function send_product_to_elixir($product_id) {
    $p = wc_get_product($product_id);
    if (!$p) return;

    /* ------- core product data ------- */
    $cats = [];                         // nested category path
    $term = get_the_terms($product_id, 'product_cat');
    if ($term && !is_wp_error($term)) {
        $ancestors = get_ancestors($term[0]->term_id, 'product_cat');
        $ancestors = array_reverse($ancestors);
        $ancestors[] = $term[0]->term_id;
        foreach ($ancestors as $idx => $cat_id) {
            $cat = get_term($cat_id, 'product_cat');
            $cats[] = [
                'product_attribute_external_ref' => 'kategorija',
                'product_attribute_name'         => 'Kategorija',
                'product_attribute_item_external_ref' => $cat->slug,
                'product_attribute_item_name'    => $cat->name,
                'parent_product_attribute_item_external_ref' =>
                    isset($ancestors[$idx-1]) ? get_term($ancestors[$idx-1],'product_cat')->slug : null,
            ];
        }
    }

    /* brand (first attribute called “brand”) */
    $brand = [];
    foreach ($p->get_attributes() as $tax => $attr) {
        if ($attr->is_taxonomy() && $attr->get_name() === 'pa_brand') {
            $vals = wc_get_product_terms($product_id, $tax);
            if ($vals) {
                $brand = [[
                    'product_attribute_external_ref' => 'brand',
                    'product_attribute_name'         => 'brand',
                    'product_attribute_item_external_ref' => $vals[0]->slug,
                    'product_attribute_item_name'    => $vals[0]->name,
                ]];
            }
            break;
        }
    }

    $vat_percent = 0;
    if (!empty($rates)) {
        $first_rate = array_shift($rates);   // pull the first rate off
        $vat_percent = (float) ($first_rate['rate'] ?? 0);
    }

    $body = [
        'products' => [[
            'code'            => (string)$product_id,
            'external_ref'    => (string)$product_id,
            'barcode'         => $p->get_sku() ?: '',
            'name'            => $p->get_name(),
            'price'           => (float)wc_get_price_to_display($p),
            'valid'           => $p->is_in_stock(),
            'vat_percent'     => $vat_percent,
            'attributes'      => array_merge($cats, $brand),
        ]]
    ];

    $token = elixir_ensure_token();
    if (!$token) return;

    elixir_request('PUT', '/v2/int/products', $body, $token);
}

// PRODUCTS PART -----------------------------------------------------------------------------

// ORDERS PART -----------------------------------------------------------------------------
function send_order_to_elixir($order_id) {
    $o = wc_get_order($order_id);
    if (!$o) return;

    // ---- header fields ----
   // $barcode   = $o->get_order_number();
    $client_store_code = get_option('elixir_store_code') ?: '454';
    $order_date= $o->get_date_created()->format('c');          // ISO-8601
    $user_id   = $o->get_user_id() ?: 0;                       // 0 for guests
    $uuid      = $o->get_order_key();                          // WC gives us one

    // ---- line items ----
   $items = [];

    foreach ($o->get_items() as $item) {
        $product = $item->get_product();

        $items[] = [
            'price'                => (float)$item->get_total() / $item->get_quantity(),
            'price_wo_vat'         => (float)$item->get_subtotal() / $item->get_quantity(),
            'code'                 => (string)$product->get_id(),
            'product_external_ref' => (string)$product->get_id(),
            'name'                 => $product->get_name(),
            'promotion'            => false,
            'qty'                  => $item->get_quantity(),
        ];
    }

    // ---- coupons as benefits ----
    $benefits = [];
    foreach ($o->get_coupon_codes() as $code) {
        $benefits[] = [
            'benefit_type' => 'COUPON',
            'coupon_code'  => $code,
        ];
    }

    $user_data = [
        'external_ref' => (string) $user_id, 
        'email'        => $o->get_billing_email(),
        'first_name'   => $o->get_billing_first_name(),
        'last_name'    => $o->get_billing_last_name(),
        'phone_number' => $o->get_billing_phone(),
        'address'      => $o->get_billing_address_1(),
        'city'         => $o->get_billing_city(),
        'country'      => $o->get_billing_country(),
        'postcode'     => $o->get_billing_postcode(),
    ];

    $benefits = [];

    foreach ($o->get_coupon_codes() as $code) {
        $benefits[] = [
            'benefit_type' => 'COUPON',
            'coupon_code'  => $code
        ];
    }

    $body = [
        'order' => [
            'calculation_only'   => false,
       //     'barcode'            => $barcode,
            'client_store_code'  => get_option('elixir_store_code'),
            'image_url'          => null,
            'items'              => $items,
            'order_comment'      => $o->get_customer_note(),
            'order_date'         => $order_date,
            'order_number'       => $barcode,
            'req_benefits'       => $benefits,
            'user_external_ref'  => (string) $user_id,
            'order_external_ref' => $uuid,
            'req_benefits'       => $benefits,
            'user'               => $user_data,
        ]
    ];

    $token = elixir_ensure_token();
    if (!$token) return;

    elixir_request('POST', '/v2/int/order', $body, $token);
}

// ORDERS PART -----------------------------------------------------------------------------

// INITIAL SETUP -----------------------------------------------------------------------------

function elixir_sync_page() { ?>
    <h1>Elixir Sync Settings</h1>
    <form action="options.php" method="post">
        <?php settings_fields('elixir_sync_group'); ?>
        <table>
            <tr><th><label for="elixir_api_key">API Key</label></th>
                <td><input name="elixir_api_key" id="elixir_api_key" type="text"
                           value="<?php echo esc_attr(get_option('elixir_api_key')); ?>" /></td></tr>

            <tr><th><label for="elixir_login">Login</label></th>
                <td><input name="elixir_login" id="elixir_login" type="text"
                           value="<?php echo esc_attr(get_option('elixir_login')); ?>" /></td></tr>

            <tr><th><label for="elixir_password">Password</label></th>
                <td><input name="elixir_password" id="elixir_password" type="password"
                           value="<?php echo esc_attr(get_option('elixir_password')); ?>" /></td></tr>

            <tr><th><label for="elixir_store_code">Store / Client code</label></th>
                <td><input name="elixir_store_code" type="text"
                            value="<?php echo esc_attr(get_option('elixir_store_code', '454')); ?>" /></td></tr>

            <tr>
                <th><label for="elixir_spotlight_url">Spotlight URL</label></th>
                <td><input name="elixir_spotlight_url" id="elixir_spotlight_url" type="url"
                            value="<?php echo esc_url(get_option('elixir_spotlight_url')); ?>" class="regular-text" /> </td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
<?php }
// INITIAL SETUP -----------------------------------------------------------------------------