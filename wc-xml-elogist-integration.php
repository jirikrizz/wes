<?php
/**
 * Plugin Name: WooCommerce XML eLogist Integration
 * Description: Komplexní integrace WooCommerce s XML feedem a eLogist systémem - s podporou variant podle velikosti
 * Version: 2.0.1
 * Author: Jiri Kriz
 * Author URI: https://krasnevune.cz
 * Text Domain: wc-shoptet-elogist
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WSE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WSE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WSE_VERSION', '2.0.1');
define('WSE_PLUGIN_FILE', __FILE__);

// Main plugin class
class WC_XML_ELogist_Integration
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array(__CLASS__, 'uninstall'));
    }

    public function init()
    {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->includes();
        $this->init_hooks();
        
        // Initialize logger
        global $wse_logger;
        $wse_logger = WSE_Logger::get_instance();
        
        // Log plugin initialization
        $wse_logger->info('XML eLogist Plugin initialized', [
            'version' => WSE_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown'
        ], 'plugin');
    }

    private function includes()
    {
        require_once WSE_PLUGIN_PATH . 'includes/class-logger.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-xml-feed-sync.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-elogist-api.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-order-sync.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-shipping-method.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-admin-settings-xml-updated.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-product-meta-display.php';
        require_once WSE_PLUGIN_PATH . 'includes/class-webhook-handler.php';
        require_once WSE_PLUGIN_PATH . 'includes/ajax-handlers-xml-complete.php';
		//require_once WSE_PLUGIN_PATH . 'includes/class-branch-selector.php';
		require_once WSE_PLUGIN_PATH . 'includes/class-checkout-widgets.php';
    }

    private function init_hooks()
    {
        add_action('init', array($this, 'init_plugin'));
        add_action('woocommerce_init', array($this, 'init_woocommerce_features'));
		
		add_action('init', function() {
		  ini_set('log_errors', 1);
		  ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
		  error_log( "[ELOGIST DEBUG] Plugin init() spuštěno, čas: " . current_time('mysql') );
		}, 1);
        
        // Admin hooks
        add_action('admin_menu', array('WSE_Admin_Settings', 'add_admin_menu'));
        add_action('admin_init', array('WSE_Admin_Settings', 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Cron hooks
        add_action('wse_xml_sync_cron', 'wse_run_xml_sync_cron');
        add_action('wse_check_order_status', array('WSE_Order_Sync', 'check_elogist_order_status'));
        add_action('wse_cleanup_logs', array($this, 'cleanup_logs_cron'));
        
        // Order hooks - OPRAVENO: odstraněn duplikovaný hook
        // Hook je už v WSE_Order_Sync::init()
        
        // REST API
        add_action('rest_api_init', array('WSE_Webhook_Handler', 'register_routes'));
        
        // Custom order status
        add_action('init', array($this, 'register_custom_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_custom_order_statuses'));
        add_filter('woocommerce_order_is_paid_statuses', array($this, 'add_paid_statuses'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // XML product synchronization hook
        add_action('wse_resync_single_product', array($this, 'resync_single_product'), 10, 2);
    }

    public function init_plugin()
    {
        // Load textdomain
        load_plugin_textdomain('wc-shoptet-elogist', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Schedule cron jobs
        $this->schedule_cron_jobs();
        
        // Register size taxonomy
        wse_register_size_taxonomy();
        
        // Initialize Order Sync with proper timing
        if (class_exists('WSE_Order_Sync')) {
            WSE_Order_Sync::init();
        }
    }

    private function schedule_cron_jobs()
    {
        // XML sync cron
        if (!wp_next_scheduled('wse_xml_sync_cron')) {
            $interval = get_option('wse_xml_sync_interval', 'every_6_hours');
            wp_schedule_event(time() + 300, $interval, 'wse_xml_sync_cron'); // +5 minut delay
        }
        
        // Order status check cron
        if (!wp_next_scheduled('wse_check_order_status')) {
            wp_schedule_event(time() + 600, 'every_10_minutes', 'wse_check_order_status'); // +10 minut delay
        }
        
        // Clean old logs weekly
        if (!wp_next_scheduled('wse_cleanup_logs')) {
            wp_schedule_event(time() + 3600, 'weekly', 'wse_cleanup_logs'); // +1 hodina delay
        }
    }

    public function init_woocommerce_features()
    {
        // Register shipping methods
        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_methods'));
        
        // Initialize product meta display
        if (class_exists('WSE_Product_Meta_Display')) {
            WSE_Product_Meta_Display::init();
        }
    }

    public function add_shipping_methods($methods)
    {
        $methods['wse_elogist_shipping'] = 'WSE_ELogist_Shipping';
        return $methods;
    }

    public function admin_scripts($hook)
    {
        if (strpos($hook, 'wse-settings') !== false) {
            wp_enqueue_script('jquery');
            wp_enqueue_style('wse-admin', WSE_PLUGIN_URL . 'assets/admin.css', [], WSE_VERSION);
        }
    }

    public function register_custom_order_statuses()
    {
        register_post_status('wc-shipped', [
            'label' => __('Odesláno', 'wc-shoptet-elogist'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Odesláno <span class="count">(%s)</span>', 'Odesláno <span class="count">(%s)</span>', 'wc-shoptet-elogist')
        ]);
        
        register_post_status('wc-awaiting-shipment', [
            'label' => __('Čeká na odeslání', 'wc-shoptet-elogist'),
            'public' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Čeká na odeslání <span class="count">(%s)</span>', 'Čeká na odeslání <span class="count">(%s)</span>', 'wc-shoptet-elogist')
        ]);
    }

    public function add_custom_order_statuses($order_statuses)
    {
        // Add custom statuses in the right position
        $new_statuses = [];
        
        foreach ($order_statuses as $key => $status) {
            $new_statuses[$key] = $status;
            
            if ($key === 'wc-processing') {
                $new_statuses['wc-awaiting-shipment'] = __('Čeká na odeslání', 'wc-shoptet-elogist');
                $new_statuses['wc-shipped'] = __('Odesláno', 'wc-shoptet-elogist');
            }
        }
        
        return $new_statuses;
    }

    public function add_paid_statuses($statuses)
    {
        $statuses[] = 'shipped';
        $statuses[] = 'awaiting-shipment';
        return $statuses;
    }

    public function resync_single_product($product_id, $xml_guid)
    {
        global $wse_logger;
        
        try {
            $xml_feed_url = get_option('wse_xml_feed_url');
            
            if (empty($xml_feed_url)) {
                throw new Exception('XML Feed URL not configured');
            }
            
            $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
            $xml_sync->sync_single_product_by_guid($xml_guid);
            
            $wse_logger->info('Single product resync completed', [
                'product_id' => $product_id,
                'xml_guid' => $xml_guid
            ], 'xml_sync');
            
        } catch (Exception $e) {
            $wse_logger->error('Single product resync failed', [
                'product_id' => $product_id,
                'xml_guid' => $xml_guid,
                'error' => $e->getMessage()
            ], 'xml_sync');
        }
    }

    public function admin_notices()
    {
        // Check if plugin is properly configured
        if (!$this->is_plugin_configured()) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                __('WooCommerce XML eLogist Integration vyžaduje konfiguraci. <a href="%s">Přejděte do nastavení</a> pro dokončení setup.', 'wc-shoptet-elogist'),
                admin_url('options-general.php?page=wse-settings')
            );
            echo '</p></div>';
        }
        
        // Check system requirements
        $missing_requirements = $this->check_requirements();
        if (!empty($missing_requirements)) {
            echo '<div class="notice notice-error"><p>';
            printf(
                __('WooCommerce XML eLogist Integration: Chybí systémové požadavky: %s. <a href="%s">Více informací</a>', 'wc-shoptet-elogist'),
                implode(', ', $missing_requirements),
                admin_url('options-general.php?page=wse-settings&tab=diagnostics')
            );
            echo '</p></div>';
        }
        
        // Show XML sync status in admin
        $xml_sync_running = get_transient('wse_xml_sync_running');
        if ($xml_sync_running) {
            echo '<div class="notice notice-info"><p>';
            printf(
                __('XML synchronizace probíhá. <a href="%s">Zobrazit detail</a>', 'wc-shoptet-elogist'),
                admin_url('options-general.php?page=wse-settings&tab=xml')
            );
            echo '</p></div>';
        }
    }

    private function is_plugin_configured()
    {
        $xml_feed_url = get_option('wse_xml_feed_url');
        $elogist_username = get_option('wse_elogist_username');
        $elogist_password = get_option('wse_elogist_password');
        $elogist_project_id = get_option('wse_elogist_project_id');
        
        return !empty($xml_feed_url) && !empty($elogist_username) && !empty($elogist_password) && !empty($elogist_project_id);
    }

    private function check_requirements()
    {
        $missing = [];
        
        if (!extension_loaded('simplexml')) {
            $missing[] = 'SimpleXML extension';
        }
        
        if (!extension_loaded('libxml')) {
            $missing[] = 'LibXML extension';
        }
        
        if (!extension_loaded('soap')) {
            $missing[] = 'SOAP extension';
        }
        
        if (!extension_loaded('curl')) {
            $missing[] = 'cURL extension';
        }
        
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $missing[] = 'PHP 7.4+';
        }
        
        return $missing;
    }

    public function activate()
    {
        global $wse_logger;
        
        // Check requirements before activation
        $missing_requirements = $this->check_requirements();
        if (!empty($missing_requirements)) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(sprintf(
                __('Plugin nemůže být aktivován. Chybí systémové požadavky: %s', 'wc-shoptet-elogist'),
                implode(', ', $missing_requirements)
            ));
        }
        
        $this->create_tables();
        $this->create_directories();
        
        // Set default options
        add_option('wse_elogist_wsdl_url', 'https://elogist-demo.shipmall.cz/api/soap?wsdl');
        add_option('wse_plugin_version', WSE_VERSION);
        add_option('wse_xml_sync_interval', 'every_6_hours');
        add_option('wse_auto_publish_products', true);
        add_option('wse_import_images', true);
        add_option('wse_update_existing_products', true);
        
        // Set default XML feed URL
        $default_xml_url = 'https://www.krasnevune.cz/export/productsSupplier.xml?patternId=-4&partnerId=18&hash=2df6184d275f04d885a63e3926ffdad192b2f549ddbcb43ce0780e06c46cf213&manufacturerId=33';
        add_option('wse_xml_feed_url', $default_xml_url);
        
        // Schedule cron jobs s delayem
        $this->schedule_cron_jobs();
        
        flush_rewrite_rules();
        
        if ($wse_logger) {
            $wse_logger->info('Plugin activated', ['version' => WSE_VERSION], 'plugin');
        }
        
        // Trigger activation hook
        do_action('wse_plugin_activated');
    }

    public function deactivate()
    {
        global $wse_logger;
        
        // Clear scheduled events
        wp_clear_scheduled_hook('wse_xml_sync_cron');
        wp_clear_scheduled_hook('wse_check_order_status');
        wp_clear_scheduled_hook('wse_cleanup_logs');
        
        // Clear transients
        delete_transient('wse_xml_sync_running');
        
        flush_rewrite_rules();
        
        if ($wse_logger) {
            $wse_logger->info('Plugin deactivated', ['version' => WSE_VERSION], 'plugin');
        }
    }

    public static function uninstall()
    {
        // Remove all plugin data if user chooses to
        if (get_option('wse_remove_data_on_uninstall', false)) {
            global $wpdb;
            
            // Drop custom tables
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wse_product_mapping");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wse_order_sync");
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wse_logs");
            
            // Remove options
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wse_%'");
            
            // Remove transients
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wse_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wse_%'");
            
            // Remove product meta
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_xml_%'");
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_inspirovano'");
        }
        
        // Clear scheduled events
        wp_clear_scheduled_hook('wse_xml_sync_cron');
        wp_clear_scheduled_hook('wse_check_order_status');
        wp_clear_scheduled_hook('wse_cleanup_logs');
    }

    public function woocommerce_missing_notice()
    {
        echo '<div class="error"><p><strong>' . __('WooCommerce XML eLogist Integration', 'wc-shoptet-elogist') . '</strong> ' . __('vyžaduje aktivní WooCommerce plugin.', 'wc-shoptet-elogist') . '</p></div>';
    }

    public function cleanup_logs_cron()
    {
        $logger = WSE_Logger::get_instance();
        $deleted = $logger->cleanup_old_logs(30); // Keep logs for 30 days
        
        $logger->info('Log cleanup completed', [
            'deleted_count' => $deleted
        ], 'cleanup');
    }

    private function create_tables()
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Product mapping table
        $table_name = $wpdb->prefix . 'wse_product_mapping';
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            wc_product_id bigint(20) NOT NULL,
            wc_variation_id bigint(20) NULL,
            xml_guid varchar(255) NOT NULL,
            xml_id varchar(100),
            xml_code varchar(100),
            product_type varchar(20) DEFAULT 'simple',
            last_sync datetime DEFAULT CURRENT_TIMESTAMP,
            sync_status varchar(20) DEFAULT 'synced',
            PRIMARY KEY (id),
            UNIQUE KEY xml_guid (xml_guid),
            KEY wc_product_id (wc_product_id),
            KEY wc_variation_id (wc_variation_id),
            KEY sync_status (sync_status),
            KEY product_type (product_type),
            KEY xml_id (xml_id),
            KEY xml_code (xml_code)
        ) $charset_collate;";
        
        // Order sync table
        $table_name2 = $wpdb->prefix . 'wse_order_sync';
        $sql2 = "CREATE TABLE $table_name2 (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            wc_order_id bigint(20) NOT NULL,
            elogist_order_id varchar(100),
            elogist_sys_order_id varchar(100),
            last_status_check datetime DEFAULT CURRENT_TIMESTAMP,
            current_status varchar(50),
            tracking_number varchar(255),
            shipping_cost decimal(10,2),
            processing_cost decimal(10,2),
            PRIMARY KEY (id),
            UNIQUE KEY wc_order_id (wc_order_id),
            KEY elogist_order_id (elogist_order_id),
            KEY current_status (current_status)
        ) $charset_collate;";
        
        // Logs table
        $table_name3 = $wpdb->prefix . 'wse_logs';
        $sql3 = "CREATE TABLE $table_name3 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext,
            source varchar(50),
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY level (level),
            KEY source (source)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    private function create_directories()
    {
        $upload_dir = wp_upload_dir();
        $plugin_dir = $upload_dir['basedir'] . '/wse-logs';
        
        if (!file_exists($plugin_dir)) {
            wp_mkdir_p($plugin_dir);
            
            // Create .htaccess to protect log files
            $htaccess_content = "Order Deny,Allow\nDeny from all\n";
            file_put_contents($plugin_dir . '/.htaccess', $htaccess_content);
        }
    }
}

// Custom cron intervals
add_filter('cron_schedules', function($schedules) {
    $schedules['every_2_hours'] = [
        'interval' => 2 * HOUR_IN_SECONDS,
        'display' => __('Every 2 Hours', 'wc-shoptet-elogist')
    ];
    
    $schedules['every_6_hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display' => __('Every 6 Hours', 'wc-shoptet-elogist')
    ];
    
    $schedules['every_10_minutes'] = [
        'interval' => 10 * MINUTE_IN_SECONDS,
        'display' => __('Every 10 Minutes', 'wc-shoptet-elogist')
    ];
    
    return $schedules;
});

// XML sync cron function - OPRAVENO
function wse_run_xml_sync_cron() {
    $logger = WSE_Logger::get_instance();
    
    // Kontrola, zda již neběží synchronizace
    if (get_transient('wse_xml_sync_running')) {
        $logger->warning('XML sync cron: Synchronization already running, skipping', [], 'xml_sync');
        return;
    }
    
    // Nastavit flag, že synchronizace běží
    set_transient('wse_xml_sync_running', true, 600); // 10 minut timeout
    
    try {
        $xml_feed_url = get_option('wse_xml_feed_url');
        
        if (empty($xml_feed_url)) {
            $logger->error('XML sync cron: XML Feed URL not configured', [], 'xml_sync');
            return;
        }
        
        $logger->info('Starting scheduled XML sync', ['feed_url' => $xml_feed_url], 'xml_sync');
        
        $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
        $result = $xml_sync->sync_products_from_xml();
        
        // Uložit statistiky poslední synchronizace
        update_option('wse_last_xml_sync_stats', [
            'total_processed' => $result['total_processed'],
            'total_imported' => $result['total_imported'],
            'total_updated' => $result['total_updated'],
            'total_variants' => $result['total_variants'],
            'total_errors' => $result['total_errors'],
            'duration' => $result['duration'],
            'timestamp' => current_time('mysql')
        ]);
        
        $logger->info('Scheduled XML sync completed successfully', $result, 'xml_sync');
        
    } catch (Exception $e) {
        $logger->error('Scheduled XML sync failed', [
            'error' => $e->getMessage()
        ], 'xml_sync');
    } finally {
        // Vždy vymazat flag
        delete_transient('wse_xml_sync_running');
    }
}

// Helper function pro registraci taxonomie velikosti - OPRAVENO
function wse_register_size_taxonomy() {
    if (!taxonomy_exists('pa_velikost')) {
        register_taxonomy('pa_velikost', 'product', [
            'hierarchical' => false,
            'labels' => [
                'name' => 'Velikost',
                'singular_name' => 'Velikost',
                'menu_name' => 'Velikost',
                'add_new_item' => 'Přidat novou velikost',
                'new_item_name' => 'Název nové velikosti',
                'edit_item' => 'Upravit velikost',
                'update_item' => 'Aktualizovat velikost',
                'view_item' => 'Zobrazit velikost',
                'separate_items_with_commas' => 'Oddělte velikosti čárkami',
                'add_or_remove_items' => 'Přidat nebo odebrat velikosti',
                'choose_from_most_used' => 'Vyberte z nejpoužívanějších velikostí',
                'popular_items' => 'Oblíbené velikosti',
                'search_items' => 'Hledat velikosti',
                'not_found' => 'Žádné velikosti nenalezeny',
                'no_terms' => 'Žádné velikosti',
                'items_list' => 'Seznam velikostí',
                'items_list_navigation' => 'Navigace seznamu velikostí'
            ],
            'show_ui' => true,
            'show_in_menu' => false,
            'show_tagcloud' => false,
            'query_var' => true,
            'rewrite' => ['slug' => 'velikost'],
            'show_admin_column' => true,
            'show_in_quick_edit' => false,
            'meta_box_cb' => false // Nepovolíme ruční úpravu v produktech
        ]);
    }
}

// Hook pro kontrolu WooCommerce atributu velikosti - OPRAVENO
add_action('init', function() {
    if (class_exists('WooCommerce')) {
        // Ujistit se, že atribut velikosti existuje ve WooCommerce
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        $size_attribute_exists = false;
        
        foreach ($attribute_taxonomies as $attribute) {
            if ($attribute->attribute_name === 'velikost') {
                $size_attribute_exists = true;
                break;
            }
        }
        
        if (!$size_attribute_exists) {
            // Vytvoření WooCommerce atributu velikosti
            $attribute_data = [
                'attribute_label' => 'Velikost',
                'attribute_name' => 'velikost',
                'attribute_type' => 'select',
                'attribute_orderby' => 'menu_order',
                'attribute_public' => 1
            ];
            
            $result = wc_create_attribute($attribute_data);
            
            if (!is_wp_error($result)) {
                // Flush rewrite rules after creating attribute
                flush_rewrite_rules();
                
                if (class_exists('WSE_Logger')) {
                    $logger = WSE_Logger::get_instance();
                    $logger->info('Created WooCommerce size attribute', [], 'plugin');
                }
            }
        }
    }
}, 20);

// Add action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=wse-settings') . '">' . __('Nastavení', 'wc-shoptet-elogist') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add meta links
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="' . admin_url('options-general.php?page=wse-settings&tab=diagnostics') . '">' . __('Diagnostika', 'wc-shoptet-elogist') . '</a>';
        $links[] = '<a href="' . admin_url('options-general.php?page=wse-settings&tab=logs') . '">' . __('Logy', 'wc-shoptet-elogist') . '</a>';
        $links[] = '<a href="' . admin_url('options-general.php?page=wse-settings&tab=xml') . '">' . __('XML Synchronizace', 'wc-shoptet-elogist') . '</a>';
    }
    return $links;
}, 10, 2);

// Debug hook pro vývojáře
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('wp_footer', function() {
        if (current_user_can('manage_options') && isset($_GET['wse_debug'])) {
            echo '<!-- WSE Debug Info -->';
            echo '<!-- XML Feed URL: ' . get_option('wse_xml_feed_url') . ' -->';
            echo '<!-- Last XML Sync: ' . get_option('wse_last_xml_sync', 'Never') . ' -->';
            echo '<!-- XML Sync Running: ' . (get_transient('wse_xml_sync_running') ? 'Yes' : 'No') . ' -->';
            echo '<!-- Plugin Version: ' . WSE_VERSION . ' -->';
        }
    });
}

// Hook pro update pluginu
add_action('upgrader_process_complete', function($upgrader_object, $options) {
    if ($options['action'] == 'update' && $options['type'] == 'plugin') {
        foreach ($options['plugins'] as $plugin) {
            if ($plugin == plugin_basename(__FILE__)) {
                // Plugin byl aktualizován
                if (class_exists('WSE_Logger')) {
                    $logger = WSE_Logger::get_instance();
                    $logger->info('Plugin updated', [
                        'old_version' => get_option('wse_plugin_version', '1.0.0'),
                        'new_version' => WSE_VERSION
                    ], 'plugin');
                }
                
                // Aktualizovat verzi v databázi
                update_option('wse_plugin_version', WSE_VERSION);
                
                // Trigger update hook
                do_action('wse_plugin_updated', WSE_VERSION);
                break;
            }
        }
    }
}, 10, 2);

// Cleanup na konci každého dne
add_action('wp_scheduled_delete', function() {
    // Vyčistit staré transients
    delete_expired_transients();
    
    // Vyčistit staré log záznamy
    if (class_exists('WSE_Logger')) {
        $logger = WSE_Logger::get_instance();
        $logger->cleanup_old_logs(30);
    }
});

// Initialize plugin
WC_XML_ELogist_Integration::get_instance();