<?php
/*
 Plugin Name: eBay Collectibles Plugin
 Description: Fetches eBay auctions and creates WordPress posts and widgets.
 Version: 1.0
 Author: Peter Giammarco
*/

function ebay_load_env_file($file_path) {
    $env_vars = [];
    if (file_exists($file_path)) {
        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || !strpos($line, '=')) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $env_vars[trim($key)] = trim($value);
        }
    } else {
        error_log("Environment file not found: $file_path");
    }
    return $env_vars;
}

// Load env variables
$env_file = plugin_dir_path(__FILE__) . 'ebay.env';
$env_ebay = ebay_load_env_file($env_file);

require_once plugin_dir_path(__FILE__) . 'includes/class-tcs-ebay-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/ebay-api-cache.php';

// Load the files
$files = [
    'ebay-auctions.php',
    'ebay-selling-fetcher.php',
    'ebay-check-item.php',
    'ebay-top-collectibles-widget.php'
];

foreach ($files as $file) {
    $path = plugin_dir_path(__FILE__) . $file;
    if (file_exists($path)) {
        require $path;
    } else {
        error_log("Failed to load $file");
    }
}

function ebay_add_admin_menu() {
    add_menu_page(
        'Ebay Inventory',
        'Ebay Inventory',
        'manage_options',
        'ebay-inventory',
        'ebay_admin_page',
        'dashicons-store',
        20
    );
}
add_action('admin_menu', 'ebay_add_admin_menu');

/**
 * Convert an eBay image URL to a higher-resolution version.
 */
function get_high_res_ebay_image(
    string $image_url,
    string $target_size = 's-l500'
): string {
    if (
        preg_match(
            '#^https://i\.ebayimg\.com/images/.*/s-l\d+\.jpg$#i',
            $image_url
        )
    ) {
        $high_res_url = preg_replace(
            '/s-l\d+/i',
            $target_size,
            $image_url
        );

        if (is_string($high_res_url)) {
            return $high_res_url;
        }
    }

    return $image_url;
}

function ebay_admin_page() {
    $message = '';
    if (isset($_POST['ebay_refresh'])) {
        check_admin_referer('ebay_refresh_action');
        ob_start();
        $result = create_auction_posts(true);
        $message = ob_get_clean();
        if (!is_wp_error($result) && $message === '') {
            $message = '<div class="notice notice-success"><p>eBay Auctions refreshed.</p></div>';
        }
    }
    if (isset($_POST['ebay_buy_it_now_refresh'])) {
        check_admin_referer('ebay_buy_it_now_refresh_action');
        ob_start();
        $result = create_buy_it_now_posts(true);
        $message = ob_get_clean();
        if (!is_wp_error($result) && $message === '') {
            $message = '<div class="notice notice-success"><p>eBay Buy It Now Items refreshed.</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>Ebay Inventory</h1>
        <p>Click the buttons below to refresh the eBay Auctions or Buy It Now items.</p>
        <?php if ($message) echo $message; ?>
        
        <form method="post">
            <?php wp_nonce_field('ebay_refresh_action'); ?>
            <input type="hidden" name="ebay_refresh" value="1">
            <input type="submit" class="button button-primary" value="Refresh Auctions">
        </form>
        <div class="my-1">
        <form method="post">
            <?php wp_nonce_field('ebay_buy_it_now_refresh_action'); ?>
            <input type="hidden" name="ebay_buy_it_now_refresh" value="1">
            <input type="submit" class="button button-primary" value="Refresh Buy It Now Items">
        </form>
        </div>
        <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=refresh_ebay_auctions'), 'refresh_ebay_auctions_nonce')); ?>" class="button button-secondary">Refresh Auctions (URL Trigger)</a></p>
    </div>
    <?php
}

function register_ebay_refresh_action() {
    if (isset($_GET['action']) && $_GET['action'] === 'refresh_ebay_auctions' && current_user_can('manage_options')) {
        if (!check_admin_referer('refresh_ebay_auctions_nonce')) {
            wp_die(
                'Security check failed. Please try again from the eBay Inventory page.',
                'Nonce Verification Failed',
                ['back_link' => true]
            );
        }
        ob_start();
        $result = create_auction_posts(true);
        ob_end_clean();
        $refresh_status = is_wp_error($result) ? 'auction_failed' : 'auction_refreshed';
        wp_redirect(admin_url('admin.php?page=ebay-inventory&message=' . $refresh_status));
        exit;
    }
}
add_action('admin_init', 'register_ebay_refresh_action');

function ebay_add_query_vars($vars) {
    $vars[] = 'message';
    return $vars;
}
add_filter('query_vars', 'ebay_add_query_vars');

function ebay_admin_notices() {
    if (get_current_screen()->id === 'toplevel_page_ebay-inventory' && isset($_GET['message'])) {
        if ($_GET['message'] === 'auction_failed') {
            echo '<div class="notice notice-error"><p>eBay refresh could not complete. Existing posts were preserved. Try again later.</p></div>';
        }
        if ($_GET['message'] === 'auction_refreshed') {
            echo '<div class="notice notice-success is-dismissible"><p>eBay Auctions refreshed successfully via URL trigger.</p></div>';
        }
    }
}
add_action('admin_notices', 'ebay_admin_notices');
function get_ebay_oauth_token() {
    global $env_ebay;
    $cached = get_transient('ebay_oauth_token');
    if (is_string($cached) && $cached !== '') return $cached;
    if (get_transient('tcs_ebay_oauth_failure')) return false;
    $client_id = $env_ebay['EBAY_PRODUCTION_APPID'] ?? '';
    $secret = $env_ebay['EBAY_CLIENT_SECRET'] ?? '';
    if ($client_id === '' || $secret === '') return false;
    $lock = 'tcs_ebay_oauth_fetch_v1';
    $owner = TCS_Ebay_API_Client::acquire_lock($lock);
    if (!$owner) return false;
    try {
        $cached = get_transient('ebay_oauth_token');
        if (is_string($cached) && $cached !== '') return $cached;
        $response = TCS_Ebay_API_Client::request('oauth', 'https://api.ebay.com/identity/v1/oauth2/token', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => ['grant_type' => 'client_credentials', 'scope' => 'https://api.ebay.com/oauth/api_scope'],
            'timeout' => 10,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient('tcs_ebay_oauth_failure', true, MINUTE_IN_SECONDS);
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $lifetime = (int) ($body['expires_in'] ?? 0);
        if (empty($body['access_token']) || $lifetime <= 1) {
            set_transient('tcs_ebay_oauth_failure', true, MINUTE_IN_SECONDS);
            return false;
        }
        $margin = min(300, max(1, (int) floor($lifetime / 10)));
        set_transient('ebay_oauth_token', $body['access_token'], max(1, $lifetime - $margin));
        return $body['access_token'];
    } finally {
        TCS_Ebay_API_Client::release_lock($lock, $owner);
    }
}

// Add basic styling
function ebay_styles() {
    $general_style_path = plugin_dir_path(__FILE__) . 'css/ebay-styles.css';
    $item_style_path    = plugin_dir_path(__FILE__) . 'css/ebay-item.css';
    $page_style_path    = plugin_dir_path(__FILE__) . 'css/ebay-page-items.css';

    wp_enqueue_style(
        'ebay-styles',
        plugin_dir_url(__FILE__) . 'css/ebay-styles.css',
        [],
        file_exists($general_style_path)
            ? filemtime($general_style_path)
            : '1.0'
    );

    wp_enqueue_style(
        'ebay-item-style',
        plugin_dir_url(__FILE__) . 'css/ebay-item.css',
        ['ebay-styles'],
        file_exists($item_style_path)
            ? filemtime($item_style_path)
            : '1.0'
    );

    wp_enqueue_style(
        'ebay-page-items-style',
        plugin_dir_url(__FILE__) . 'css/ebay-page-items.css',
        ['ebay-styles'],
        file_exists($page_style_path)
            ? filemtime($page_style_path)
            : '1.0'
    );
}
add_action('wp_enqueue_scripts', 'ebay_styles');

function ebay_enqueue_scripts() {
    $script_path = plugin_dir_path(__FILE__) . 'js/ebay-item.js';

    wp_enqueue_script(
        'ebay-item-js',
        plugin_dir_url(__FILE__) . 'js/ebay-item.js',
        ['jquery'],
        file_exists($script_path) ? filemtime($script_path) : '1.1',
        true
    );

    wp_localize_script(
        'ebay-item-js',
        'ebay_ajax_obj',
        [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ebay_nonce'),
        ]
    );
}
add_action('wp_enqueue_scripts', 'ebay_enqueue_scripts');

// Add admin styling
function ebay_admin_styles() {
    wp_enqueue_style('ebay-admin-styles', plugin_dir_url(__FILE__) . 'css/ebay-admin-styles.css');
}
add_action('admin_enqueue_scripts', 'ebay_admin_styles');
