<?php
/**
 * Webhook Handler for eLogist status updates - OPRAVENÉ
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Webhook_Handler
{
    private static $logger;

    public static function init()
    {
        self::$logger = WSE_Logger::get_instance();
    }

    /**
     * Registrace REST API routes pro webhooky
     */
    public static function register_routes()
    {
        if (!self::$logger) {
            self::init();
        }

        // eLogist webhook endpoint
        register_rest_route('wse/v1', '/elogist-webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_elogist_webhook'],
            'permission_callback' => [__CLASS__, 'verify_webhook_permission'],
            'args' => [
                'orderId' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'status' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'trackingNo' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // Health check endpoint
        register_rest_route('wse/v1', '/health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'health_check'],
            'permission_callback' => '__return_true'
        ]);

        // Status endpoint pro monitoring
        register_rest_route('wse/v1', '/status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_plugin_status'],
            'permission_callback' => [__CLASS__, 'verify_status_permission']
        ]);
    }

    /**
     * Verifikace oprávnění pro webhook
     */
    public static function verify_webhook_permission($request)
    {
        // Jednoduchá autentizace pomocí API klíče (volitelné)
        $api_key = get_option('wse_webhook_api_key');
        
        if (!empty($api_key)) {
            $provided_key = $request->get_header('X-API-Key') ?: $request->get_param('api_key');
            
            if ($provided_key !== $api_key) {
                self::$logger->warning('Webhook unauthorized access attempt', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ], 'webhook');
                return false;
            }
        }
        
        return true;
    }

    /**
     * Verifikace oprávnění pro status endpoint
     */
    public static function verify_status_permission($request)
    {
        // Status endpoint je veřejný, ale může být chráněný API klíčem
        $api_key = get_option('wse_status_api_key');
        
        if (!empty($api_key)) {
            $provided_key = $request->get_header('X-API-Key') ?: $request->get_param('api_key');
            return $provided_key === $api_key;
        }
        
        return true;
    }

    /**
     * Handler pro eLogist webhook
     */
    public static function handle_elogist_webhook($request)
    {
        $start_time = microtime(true);
        
        try {
            $data = $request->get_json_params();
            
            if (empty($data)) {
                $data = $request->get_params();
            }
            
            self::$logger->info('eLogist webhook received', [
                'data' => $data,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ], 'webhook');
            
            // Validace dat
            if (!isset($data['orderId']) || !isset($data['status'])) {
                self::$logger->error('Invalid webhook data - missing required fields', [
                    'data' => $data
                ], 'webhook');
                
                return new WP_Error(
                    'invalid_data',
                    __('Chybí povinná pole orderId nebo status', 'wc-shoptet-elogist'),
                    ['status' => 400]
                );
            }

            // Validace order ID
            $order_id = sanitize_text_field($data['orderId']);
            if (!is_numeric($order_id) || $order_id <= 0) {
                return new WP_Error(
                    'invalid_order_id',
                    __('Neplatné ID objednávky', 'wc-shoptet-elogist'),
                    ['status' => 400]
                );
            }

            // Validace status
            $allowed_statuses = ['NEW', 'SUSPENDED', 'CANCELLED', 'SHIPPED', 'DELIVERED', 'ABANDONED'];
            if (!in_array($data['status'], $allowed_statuses)) {
                return new WP_Error(
                    'invalid_status',
                    __('Neplatný status objednávky', 'wc-shoptet-elogist'),
                    ['status' => 400]
                );
            }

            // Zpracování webhook dat
            $result = WSE_Order_Sync::update_order_status_from_webhook($data);
            
            $processing_time = (microtime(true) - $start_time) * 1000;
            
            if ($result) {
                self::$logger->info('eLogist webhook processed successfully', [
                    'order_id' => $data['orderId'],
                    'status' => $data['status'],
                    'tracking' => $data['trackingNo'] ?? null,
                    'processing_time_ms' => round($processing_time, 2)
                ], 'webhook');
                
                return new WP_REST_Response([
                    'success' => true,
                    'message' => __('Webhook zpracován úspěšně', 'wc-shoptet-elogist'),
                    'order_id' => $data['orderId'],
                    'processing_time_ms' => round($processing_time, 2)
                ], 200);
                
            } else {
                self::$logger->warning('eLogist webhook processing failed', [
                    'order_id' => $data['orderId'],
                    'status' => $data['status'],
                    'processing_time_ms' => round($processing_time, 2)
                ], 'webhook');
                
                return new WP_REST_Response([
                    'success' => false,
                    'message' => __('Webhook zpracován, ale nedošlo ke změně', 'wc-shoptet-elogist'),
                    'order_id' => $data['orderId']
                ], 200); // Stále 200, aby eLogist neposílal znovu
            }
            
        } catch (Exception $e) {
            $processing_time = (microtime(true) - $start_time) * 1000;
            
            self::$logger->error('eLogist webhook processing error', [
                'error' => $e->getMessage(),
                'data' => $data ?? null,
                'processing_time_ms' => round($processing_time, 2)
            ], 'webhook');
            
            return new WP_Error(
                'processing_error',
                __('Chyba při zpracování webhook: ', 'wc-shoptet-elogist') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Health check endpoint
     */
    public static function health_check($request)
    {
        $health_data = [
            'status' => 'healthy',
            'timestamp' => current_time('c'),
            'version' => WSE_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'not_installed',
            'php_version' => PHP_VERSION,
            'checks' => []
        ];

        // Test databázového připojení
        try {
            global $wpdb;
            $wpdb->get_var("SELECT 1");
            $health_data['checks']['database'] = ['status' => 'ok', 'message' => 'Database connection OK'];
        } catch (Exception $e) {
            $health_data['status'] = 'unhealthy';
            $health_data['checks']['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Test XML Feed
        try {
            $xml_feed_url = get_option('wse_xml_feed_url');
            if (!empty($xml_feed_url)) {
                $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
                $result = $xml_sync->test_xml_feed();
                
                if ($result['success']) {
                    $health_data['checks']['xml_feed'] = ['status' => 'ok', 'message' => 'XML Feed accessible'];
                } else {
                    $health_data['status'] = 'degraded';
                    $health_data['checks']['xml_feed'] = ['status' => 'warning', 'message' => $result['message']];
                }
            } else {
                $health_data['checks']['xml_feed'] = ['status' => 'warning', 'message' => 'XML Feed URL not configured'];
            }
        } catch (Exception $e) {
            $health_data['status'] = 'degraded';
            $health_data['checks']['xml_feed'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Test eLogist API
        try {
            $elogist_api = new WSE_ELogist_API();
            $result = $elogist_api->test_connection();
            
            if ($result) {
                $health_data['checks']['elogist_api'] = ['status' => 'ok', 'message' => 'eLogist API accessible'];
            } else {
                $health_data['status'] = 'degraded';
                $health_data['checks']['elogist_api'] = ['status' => 'warning', 'message' => 'eLogist API not accessible'];
            }
        } catch (Exception $e) {
            $health_data['status'] = 'degraded';
            $health_data['checks']['elogist_api'] = ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Test cron jobs
        $xml_cron = wp_next_scheduled('wse_xml_sync_cron');
        $order_cron = wp_next_scheduled('wse_check_order_status');
        
        if ($xml_cron && $order_cron) {
            $health_data['checks']['cron_jobs'] = ['status' => 'ok', 'message' => 'Cron jobs scheduled'];
        } else {
            $health_data['status'] = 'degraded';
            $health_data['checks']['cron_jobs'] = ['status' => 'warning', 'message' => 'Some cron jobs not scheduled'];
        }

        // Test write permissions
        $upload_dir = wp_upload_dir();
        $test_file = $upload_dir['basedir'] . '/wse-test-' . uniqid() . '.txt';
        $write_test = @file_put_contents($test_file, 'test');
        if ($write_test !== false) {
            @unlink($test_file);
            $health_data['checks']['write_permissions'] = ['status' => 'ok', 'message' => 'Write permissions OK'];
        } else {
            $health_data['status'] = 'degraded';
            $health_data['checks']['write_permissions'] = ['status' => 'warning', 'message' => 'Write permissions issue'];
        }

        $status_code = 200;
        if ($health_data['status'] === 'unhealthy') {
            $status_code = 503;
        } elseif ($health_data['status'] === 'degraded') {
            $status_code = 200; // Stále OK, ale s warningy
        }

        return new WP_REST_Response($health_data, $status_code);
    }

    /**
     * Plugin status endpoint
     */
    public static function get_plugin_status($request)
    {
        global $wpdb;
        
        // Základní statistiky
        $xml_products_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_xml_guid'"
        );
        
        $xml_variants_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_xml_variant_id'"
        );
        
        $synced_orders_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wse_order_sync"
        );

        // Poslední synchronizace
        $last_xml_sync = get_option('wse_last_xml_sync');
        $last_status_check = get_option('wse_last_status_check');

        // Cron jobs
        $next_xml_sync = wp_next_scheduled('wse_xml_sync_cron');
        $next_order_check = wp_next_scheduled('wse_check_order_status');

        // Log statistiky (posledních 24h)
        $logger = WSE_Logger::get_instance();
        $log_stats = $logger->get_log_stats();

        $status_data = [
            'plugin_version' => WSE_VERSION,
            'status' => 'active',
            'timestamp' => current_time('c'),
            'statistics' => [
                'xml_products' => (int)$xml_products_count,
                'xml_variants' => (int)$xml_variants_count,
                'synced_orders' => (int)$synced_orders_count
            ],
            'last_activities' => [
                'xml_sync' => $last_xml_sync,
                'order_status_check' => $last_status_check
            ],
            'scheduled_jobs' => [
                'next_xml_sync' => $next_xml_sync ? date('c', $next_xml_sync) : null,
                'next_order_check' => $next_order_check ? date('c', $next_order_check) : null
            ],
            'logs_24h' => $log_stats,
            'configuration' => [
                'xml_feed_configured' => !empty(get_option('wse_xml_feed_url')),
                'elogist_configured' => !empty(get_option('wse_elogist_username')) && !empty(get_option('wse_elogist_password')),
                'auto_import_enabled' => get_option('wse_auto_publish_products', true),
                'image_import_enabled' => get_option('wse_import_images', true)
            ]
        ];

        return new WP_REST_Response($status_data, 200);
    }

    /**
     * Webhook URL generator
     */
    public static function get_webhook_url()
    {
        return rest_url('wse/v1/elogist-webhook');
    }

    /**
     * Registrace webhook URL v eLogist (pokud API podporuje)
     */
    public static function register_webhook_in_elogist()
    {
        try {
            $webhook_url = self::get_webhook_url();
            
            // Přidat API klíč pokud je nastaven
            $api_key = get_option('wse_webhook_api_key');
            if (!empty($api_key)) {
                $webhook_url = add_query_arg('api_key', $api_key, $webhook_url);
            }
            
            self::$logger->info('Webhook URL ready for eLogist registration', [
                'webhook_url' => $webhook_url
            ], 'webhook');
            
            return $webhook_url;
            
        } catch (Exception $e) {
            self::$logger->error('Failed to generate webhook URL', [
                'error' => $e->getMessage()
            ], 'webhook');
            
            return false;
        }
    }

    /**
     * Test webhook functionality
     */
    public static function test_webhook($order_id = null, $status = 'SHIPPED')
    {
        if (!$order_id) {
            // Najít nějakou existující objednávku pro test
            global $wpdb;
            $order_id = $wpdb->get_var(
                "SELECT wc_order_id FROM {$wpdb->prefix}wse_order_sync ORDER BY last_status_check DESC LIMIT 1"
            );
        }
        
        if (!$order_id) {
            return new WP_Error('no_order', 'Žádná objednávka pro test');
        }
        
        $test_data = [
            'orderId' => $order_id,
            'status' => $status,
            'trackingNo' => 'TEST-' . time()
        ];
        
        // Simulovat webhook request
        $request = new WP_REST_Request('POST', '/wse/v1/elogist-webhook');
        $request->set_body_params($test_data);
        
        return self::handle_elogist_webhook($request);
    }

    /**
     * Webhook statistiky
     */
    public static function get_webhook_stats()
    {
        global $wpdb;
        
        $logger = WSE_Logger::get_instance();
        
        // Počet webhook volání za posledních 24 hodin
        $webhook_calls = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wse_logs 
             WHERE source = 'webhook' 
             AND timestamp >= %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        // Úspěšné vs neúspěšné
        $successful_calls = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wse_logs 
             WHERE source = 'webhook' 
             AND level = 'info'
             AND message LIKE '%processed successfully%'
             AND timestamp >= %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        return [
            'total_calls_24h' => (int)$webhook_calls,
            'successful_calls_24h' => (int)$successful_calls,
            'error_rate' => $webhook_calls > 0 ? round((($webhook_calls - $successful_calls) / $webhook_calls) * 100, 2) : 0,
            'last_call' => $wpdb->get_var(
                "SELECT timestamp FROM {$wpdb->prefix}wse_logs 
                 WHERE source = 'webhook' 
                 ORDER BY timestamp DESC LIMIT 1"
            )
        ];
    }
}