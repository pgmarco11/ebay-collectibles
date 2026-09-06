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

function ebay_admin_page() {
    $message = '';
    if (isset($_POST['ebay_refresh'])) {
        check_admin_referer('ebay_refresh_action');
        ob_start();
        create_auction_posts();
        $message = '<div class="notice notice-success"><p>eBay Auctions refreshed successfully.</p></div>';
        ob_end_clean();
    }
    if (isset($_POST['ebay_buy_it_now_refresh'])) {
        check_admin_referer('ebay_buy_it_now_refresh_action');
        ob_start();
        create_buy_it_now_posts();
        $message = '<div class="notice notice-success"><p>eBay Buy It Now Items refreshed successfully.</p></div>';
        ob_end_clean();
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
        create_auction_posts();
        ob_end_clean();
        wp_redirect(admin_url('admin.php?page=ebay-inventory&message=auction_refreshed'));
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
        if ($_GET['message'] === 'auction_refreshed') {
            echo '<div class="notice notice-success is-dismissible"><p>eBay Auctions refreshed successfully via URL trigger.</p></div>';
        }
    }
}
add_action('admin_notices', 'ebay_admin_notices');
function get_ebay_oauth_token() {
    global $env_ebay;

    $client_id = $env_ebay['EBAY_PRODUCTION_APPID'] ?? '';
    $client_secret = $env_ebay['EBAY_CLIENT_SECRET'] ?? '';

    if (empty($client_id) || empty($client_secret)) {
        error_log('eBay OAuth: Missing client ID or secret.');
        return false;
    }

    $cached_token = get_transient('ebay_oauth_token');
    if ($cached_token !== false) {
        return $cached_token;
    }

    $encoded_credentials = base64_encode("$client_id:$client_secret");

    $response = wp_remote_post('https://api.ebay.com/identity/v1/oauth2/token', [
        'headers' => [
            'Authorization' => 'Basic ' . $encoded_credentials,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'grant_type'   => 'client_credentials',
            'scope'        => 'https://api.ebay.com/oauth/api_scope',
        ],
        'timeout' => 10, // Add timeout to prevent hanging
    ]);

    if (is_wp_error($response)) {
        error_log('eBay OAuth API error: ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) {
        error_log('eBay OAuth: No access token received.');
        return false;
    }

    set_transient('ebay_oauth_token', $body['access_token'], max(300, $body['expires_in'] - 300));
    return $body['access_token'];
}

// Add basic styling
function ebay_styles() {
    $general_style_path = plugin_dir_path(__FILE__) . 'css/ebay-styles.css';
    $item_style_path    = plugin_dir_path(__FILE__) . 'css/ebay-item.css';

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