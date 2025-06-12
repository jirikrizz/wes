<?php
/**
 * AJAX Handlers pro XML Feed synchronizaci - kompletní verze
 */

if (!defined('ABSPATH')) {
    exit;
}

// AJAX handler pro test XML feedu
add_action('wp_ajax_wse_test_xml_feed', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        $xml_feed_url = get_option('wse_xml_feed_url');
        
        if (empty($xml_feed_url)) {
            wp_send_json_error(__('XML Feed URL není nakonfigurována', 'wc-shoptet-elogist'));
        }
        
        $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
        $result = $xml_sync->test_xml_feed();
        
        if ($result['success']) {
            $message = $result['message'];
            if (isset($result['products_count'])) {
                $message .= sprintf(' Nalezeno %d produktů', $result['products_count']);
                if (isset($result['variants_count'])) {
                    $message .= sprintf(' s celkem %d variantami', $result['variants_count']);
                }
                $message .= '.';
            }
            wp_send_json_success($message);
        } else {
            wp_send_json_error($result['message']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba při testování XML feedu: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// AJAX handler pro XML feed synchronizaci
add_action('wp_ajax_wse_sync_xml_feed', function() {
    check_ajax_referer('wse_sync_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        $xml_feed_url = get_option('wse_xml_feed_url');
        
        if (empty($xml_feed_url)) {
            wp_send_json_error(__('XML Feed URL není nakonfigurována', 'wc-shoptet-elogist'));
        }
        
        // Nastavit timeout pro dlouhé synchronizace
        set_time_limit(600); // 5 minut
        ignore_user_abort(true);
        
        $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
        $result = $xml_sync->sync_products_from_xml();
        
        $message = sprintf(
            __('✅ XML synchronizace dokončena úspěšně!<br><br><strong>Zpracováno:</strong> %d produktů<br><strong>Nově importováno:</strong> %d<br><strong>Aktualizováno:</strong> %d<br><strong>Variant zpracováno:</strong> %d<br><strong>Chyby:</strong> %d<br><strong>Doba zpracování:</strong> %d sekund', 'wc-shoptet-elogist'),
            $result['total_processed'],
            $result['total_imported'],
            $result['total_updated'],
            $result['total_variants'],
            $result['total_errors'],
            $result['duration']
        );
        
        wp_send_json_success($message);
        
    } catch (Exception $e) {
        wp_send_json_error(__('❌ Chyba při XML synchronizaci: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// AJAX handler pro kontrolu osiřelých produktů
add_action('wp_ajax_wse_check_orphaned_products', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        $xml_feed_url = get_option('wse_xml_feed_url');
        
        if (empty($xml_feed_url)) {
            wp_send_json_error(__('XML Feed URL není nakonfigurována', 'wc-shoptet-elogist'));
        }
        
        $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
        $result = $xml_sync->clean_orphaned_products(true); // Dry run
        
        if ($result['found'] > 0) {
            $message = sprintf(
                __('🔍 Nalezeno %d osiřelých produktů, které již nejsou v XML feedu a lze je smazat.', 'wc-shoptet-elogist'),
                $result['found']
            );
        } else {
            $message = __('✅ Žádné osiřelé produkty nebyly nalezeny. Všechny produkty v databázi existují v XML feedu.', 'wc-shoptet-elogist');
        }
        
        wp_send_json_success($message);
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba při kontrole osiřelých produktů: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// AJAX handler pro smazání osiřelých produktů
add_action('wp_ajax_wse_delete_orphaned_products', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        $xml_feed_url = get_option('wse_xml_feed_url');
        
        if (empty($xml_feed_url)) {
            wp_send_json_error(__('XML Feed URL není nakonfigurována', 'wc-shoptet-elogist'));
        }
        
        $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
        $result = $xml_sync->clean_orphaned_products(false); // Skutečné smazání
        
        $message = sprintf(
            __('🗑️ Smazáno %d z %d osiřelých produktů.', 'wc-shoptet-elogist'),
            $result['deleted'],
            $result['found']
        );
        
        wp_send_json_success($message);
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba při mazání osiřelých produktů: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// AJAX handler pro synchronizaci konkrétního produktu
add_action('wp_ajax_wse_sync_single_product', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    $guid = sanitize_text_field($_POST['guid'] ?? '');
    
    if (empty($guid)) {
        wp_send_json_error(__('XML GUID není zadán', 'wc-shoptet-elogist'));
    }
    
    try {
        $xml_feed_url = get_option('wse_xml_feed_url');
        
        if (empty($xml_feed_url)) {
            wp_send_json_error(__('XML Feed URL není nakonfigurována', 'wc-shoptet-elogist'));
        }
        
        $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
        $xml_sync->sync_single_product_by_guid($guid);
        
        wp_send_json_success(__('✅ Produkt byl úspěšně synchronizován z XML feedu.', 'wc-shoptet-elogist'));
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba při synchronizaci produktu: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// AJAX handler pro přeplánování cron jobů
add_action('wp_ajax_wse_reschedule_cron', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        // Zrušit existující cron
        wp_clear_scheduled_hook('wse_xml_sync_cron');
        
        // Naplánovat nový podle nastavení
        $interval = get_option('wse_xml_sync_interval', 'every_6_hours');
        wp_schedule_event(time(), $interval, 'wse_xml_sync_cron');
        
        wp_send_json_success(__('Automatická synchronizace byla přeplánována', 'wc-shoptet-elogist'));
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// AJAX handler pro test eLogist připojení
add_action('wp_ajax_wse_test_connection', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    $type = $_POST['type'] ?? '';
    
    if ($type === 'elogist') {
        try {
            $elogist_api = new WSE_ELogist_API();
            $result = $elogist_api->test_connection();
            
            if ($result) {
                wp_send_json_success(__('✅ eLogist API připojení úspěšné. Systém je připraven k odesílání objednávek.', 'wc-shoptet-elogist'));
            } else {
                wp_send_json_error(__('❌ eLogist API připojení selhalo. Zkontrolujte přihlašovací údaje.', 'wc-shoptet-elogist'));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Chyba při testování eLogist API: ', 'wc-shoptet-elogist') . $e->getMessage());
        }
    } else {
        wp_send_json_error(__('Neznámý typ testu', 'wc-shoptet-elogist'));
    }
});

// AJAX handler pro diagnostiku
add_action('wp_ajax_wse_run_diagnostics', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        $diagnostics = [];
        
        // Test XML Feed
        $xml_feed_url = get_option('wse_xml_feed_url');
        if (!empty($xml_feed_url)) {
            try {
                $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
                $xml_test = $xml_sync->test_xml_feed();
                $diagnostics['xml_feed'] = [
                    'name' => 'XML Feed Test',
                    'status' => $xml_test['success'] ? 'ok' : 'error',
                    'message' => $xml_test['message'] . (isset($xml_test['products_count']) ? " ({$xml_test['products_count']} produktů)" : '')
                ];
            } catch (Exception $e) {
                $diagnostics['xml_feed'] = [
                    'name' => 'XML Feed Test',
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        } else {
            $diagnostics['xml_feed'] = [
                'name' => 'XML Feed Test',
                'status' => 'warning',
                'message' => 'XML Feed URL není nakonfigurována'
            ];
        }
        
        // Test eLogist API
        try {
            $elogist_api = new WSE_ELogist_API();
            $elogist_test = $elogist_api->test_connection();
            $diagnostics['elogist_api'] = [
                'name' => 'eLogist API Test',
                'status' => $elogist_test ? 'ok' : 'error',
                'message' => $elogist_test ? 'Připojení úspěšné' : 'Připojení selhalo - zkontrolujte přihlašovací údaje'
            ];
        } catch (Exception $e) {
            $diagnostics['elogist_api'] = [
                'name' => 'eLogist API Test',
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
        
        // Test databázových tabulek
        global $wpdb;
        $required_tables = [
            $wpdb->prefix . 'wse_product_mapping',
            $wpdb->prefix . 'wse_order_sync',
            $wpdb->prefix . 'wse_logs'
        ];
        
        $missing_tables = [];
        foreach ($required_tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                $missing_tables[] = $table;
            }
        }
        
        $diagnostics['database_tables'] = [
            'name' => 'Databázové tabulky',
            'status' => empty($missing_tables) ? 'ok' : 'error',
            'message' => empty($missing_tables) ? 'Všechny tabulky existují' : 'Chybí tabulky: ' . implode(', ', $missing_tables)
        ];
        
        // Test WooCommerce
        $diagnostics['woocommerce'] = [
            'name' => 'WooCommerce',
            'status' => class_exists('WooCommerce') ? 'ok' : 'error',
            'message' => class_exists('WooCommerce') ? 'WooCommerce je aktivní (' . (defined('WC_VERSION') ? WC_VERSION : 'verze neznámá') . ')' : 'WooCommerce není nainstalováno'
        ];
        
        // Test write permissions
        $upload_dir = wp_upload_dir();
        $test_file = $upload_dir['basedir'] . '/wse-test-' . uniqid() . '.txt';
        $write_test = @file_put_contents($test_file, 'test');
        if ($write_test !== false) {
            @unlink($test_file);
        }
        
        $diagnostics['write_permissions'] = [
            'name' => 'Oprávnění k zápisu',
            'status' => $write_test !== false ? 'ok' : 'error',
            'message' => $write_test !== false ? 'Oprávnění k zápisu jsou v pořádku' : 'Chybí oprávnění k zápisu do uploads adresáře'
        ];
        
        // Test cron jobs
        $xml_cron = wp_next_scheduled('wse_xml_sync_cron');
        $order_cron = wp_next_scheduled('wse_check_order_status');
        
        $diagnostics['cron_jobs'] = [
            'name' => 'Cron Jobs',
            'status' => ($xml_cron || $order_cron) ? 'ok' : 'warning',
            'message' => ($xml_cron && $order_cron) ? 'Všechny cron jobs jsou naplánovány' : 'Některé cron jobs nejsou naplánovány'
        ];
        
        // Test systémových požadavků
        $missing_extensions = [];
        $required_extensions = ['simplexml', 'libxml', 'curl', 'soap'];
        
        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) {
                $missing_extensions[] = $ext;
            }
        }
        
        $diagnostics['php_extensions'] = [
            'name' => 'PHP rozšíření',
            'status' => empty($missing_extensions) ? 'ok' : 'error',
            'message' => empty($missing_extensions) ? 'Všechna požadovaná rozšíření jsou dostupná' : 'Chybí rozšíření: ' . implode(', ', $missing_extensions)
        ];
        
        // Test memory limit
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $recommended_memory = 134217728; // 128MB
        
        $diagnostics['memory_limit'] = [
            'name' => 'PHP Memory Limit',
            'status' => $memory_limit >= $recommended_memory ? 'ok' : 'warning',
            'message' => ini_get('memory_limit') . ' (doporučeno 128MB+)'
        ];
        
        // Test taxonomie velikosti
        $size_taxonomy_exists = taxonomy_exists('pa_velikost');
        $diagnostics['size_taxonomy'] = [
            'name' => 'Taxonomie velikosti',
            'status' => $size_taxonomy_exists ? 'ok' : 'warning',
            'message' => $size_taxonomy_exists ? 'Taxonomie pa_velikost existuje' : 'Taxonomie pa_velikost není registrována'
        ];
        
        // Statistiky databáze
        $xml_products_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_xml_guid'");
        $xml_variants_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_xml_variant_id'");
        $order_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wse_order_sync");
        
        $diagnostics['database_stats'] = [
            'name' => 'Databázové statistiky',
            'status' => 'ok',
            'message' => sprintf('Synchronizováno %d produktů, %d variant a %d objednávek', $xml_products_count, $xml_variants_count, $order_count)
        ];
        
        // Render results
        $html = '<div class="wse-diagnostics-results">';
        $html .= '<h4>Výsledky diagnostiky (' . date('j.n.Y H:i:s') . '):</h4>';
        
        $total_tests = count($diagnostics);
        $passed_tests = 0;
        $warning_tests = 0;
        $failed_tests = 0;
        
        foreach ($diagnostics as $test) {
            $status_class = 'wse-status-' . $test['status'];
            $status_icon = $test['status'] === 'ok' ? '✅' : ($test['status'] === 'warning' ? '⚠️' : '❌');
            
            if ($test['status'] === 'ok') $passed_tests++;
            elseif ($test['status'] === 'warning') $warning_tests++;
            else $failed_tests++;
            
            $html .= '<div class="wse-diagnostic-item">';
            $html .= '<span class="status-icon ' . $status_class . '">' . $status_icon . '</span>';
            $html .= '<div>';
            $html .= '<strong>' . esc_html($test['name']) . '</strong><br>';
            $html .= '<span style="color: #666; font-size: 13px;">' . esc_html($test['message']) . '</span>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // Přidat shrnutí
        $summary = '<div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
        $summary .= '<strong>Shrnutí:</strong> ';
        $summary .= sprintf('%d testů prošlo', $passed_tests);
        if ($warning_tests > 0) {
            $summary .= sprintf(', %d varování', $warning_tests);
        }
        if ($failed_tests > 0) {
            $summary .= sprintf(', %d selhalo', $failed_tests);
        }
        $summary .= sprintf(' (celkem %d testů)', $total_tests);
        $summary .= '</div>';
        
        wp_send_json_success($summary . $html);
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba při spouštění diagnostiky: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// AJAX handler pro test objednávku
add_action('wp_ajax_wse_send_test_order', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        // Kontrola eLogist konfigurace
        $username = get_option('wse_elogist_username');
        $password = get_option('wse_elogist_password');
        $project_id = get_option('wse_elogist_project_id');
        
        if (empty($username) || empty($password) || empty($project_id)) {
            wp_send_json_error(__('eLogist není kompletně nakonfigurován. Zkontrolujte uživatelské jméno, heslo a Project ID.', 'wc-shoptet-elogist'));
        }
        
        // Vytvoření test objednávky
        $test_order_id = 'TEST-XML-' . time() . '-' . wp_rand(1000, 9999);
        
        $test_order_data = [
            'projectId' => $project_id,
            'orderId' => $test_order_id,
            'customerOrderId' => 'WP-XML-TEST-' . time(),
            'orderDateTime' => date('c'),
            'suspended' => false,
            'packingInstruction' => 'Testovací objednávka z WordPress XML synchronizace - WSE Integration v' . WSE_VERSION,
            'sender' => [
                'label' => get_bloginfo('name') ?: 'XML E-shop'
            ],
            'recipient' => [
                'name' => 'Test XML Zákazník',
                'address' => [
                    'company' => 'XML Test Company s.r.o.',
                    'street' => 'XML Testovací 123/4',
                    'city' => 'Praha',
                    'postcode' => '110 00',
                    'country' => 'CZ'
                ],
                'phone' => '+420123456789',
                'email' => 'xml-test@example.com'
            ],
            'shipping' => [
                'carrierId' => 'PPL',
                'service' => 'PPL Parcel CZ Private',
                'comment' => 'Testovací objednávka z XML synchronizace - ignorovat',
                'insurance' => [
                    'currency' => 'CZK',
                    'value' => 299.0
                ]
            ],
            'orderItems' => [
                [
                    'productSheet' => [
                        'productId' => 'XML-TEST-PRODUCT-001',
                        'name' => 'XML Testovací produkt',
                        'description' => 'Produkt pro test integrace WordPress XML-eLogist',
                        'quantityUnit' => 'PC'
                    ],
                    'quantity' => 1
                ]
            ]
        ];
        
        $elogist_api = new WSE_ELogist_API();
        $response = $elogist_api->send_delivery_order($test_order_data);
        
        if ($response && $response->result->code == 1000) {
            $sys_order_id = $response->deliveryOrderStatus->sysOrderId ?? 'N/A';
            $status = $response->deliveryOrderStatus->status ?? 'NEW';
            
            $message = sprintf(
                __('✅ Test objednávka úspěšně odeslána do eLogist!<br><strong>Order ID:</strong> %s<br><strong>System Order ID:</strong> %s<br><strong>Status:</strong> %s<br><br>eLogist systém je připraven přijímat objednávky z vašeho WooCommerce s XML produkty.', 'wc-shoptet-elogist'),
                $test_order_id,
                $sys_order_id,
                $status
            );
            
            wp_send_json_success($message);
        } else {
            $error_code = $response->result->code ?? 'unknown';
            $error_description = $response->result->description ?? 'Neznámá chyba';
            
            // Získat lidsky čitelnou chybu z eLogist API
            $error_message = method_exists($elogist_api, 'get_error_message') ? 
                           $elogist_api->get_error_message($error_code) : 
                           $error_description;
            
            wp_send_json_error(sprintf(
                __('❌ Chyba při odesílání test objednávky:<br><strong>Kód:</strong> %s<br><strong>Popis:</strong> %s<br><strong>Řešení:</strong> %s', 'wc-shoptet-elogist'),
                $error_code,
                $error_description,
                $error_message
            ));
        }
        
    } catch (Exception $e) {
        wp_send_json_error(__('❌ Kritická chyba při odesílání test objednávky: ', 'wc-shoptet-elogist') . $e->getMessage() . '<br><br>Zkontrolujte prosím nastavení eLogist API.');
    }
});

// Cron job pro XML synchronizaci
add_action('wse_xml_sync_cron', function() {
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
});

// CSS styly pro admin (pouze pokud už nejsou definovány)
add_action('admin_head', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'wse-settings') {
        ?>
        <style>
        /* Základní admin styly pokud nejsou v CSS souboru */
        .wse-admin-page .nav-tab-wrapper {
            margin-bottom: 20px;
        }
        
        .wse-diagnostic-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .wse-diagnostic-box h3 {
            margin-top: 0;
            color: #1d2327;
        }
        
        .wse-test-result {
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        
        .wse-test-result.success {
            background: #d1edff;
            border: 1px solid #2271b1;
            color: #2271b1;
        }
        
        .wse-test-result.error {
            background: #fcf0f1;
            border: 1px solid #d63638;
            color: #d63638;
        }
        
        .wse-diagnostics-results .wse-diagnostic-item {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: flex-start;
        }
        
        .wse-diagnostics-results .wse-diagnostic-item:last-child {
            border-bottom: none;
        }
        
        .wse-diagnostics-results .status-icon {
            margin-right: 10px;
            font-size: 16px;
            width: 20px;
        }
        
        .wse-status-ok { color: #00a32a; }
        .wse-status-warning { color: #dba617; }
        .wse-status-error { color: #d63638; }
        </style>
        <?php
    }
});

// Přidání custom cron intervals
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['every_hour'])) {
        $schedules['every_hour'] = [
            'interval' => HOUR_IN_SECONDS,
            'display' => __('Every Hour', 'wc-shoptet-elogist')
        ];
    }
    
    if (!isset($schedules['every_6_hours'])) {
        $schedules['every_6_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'wc-shoptet-elogist')
        ];
    }
    
    if (!isset($schedules['every_30_minutes'])) {
        $schedules['every_30_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'wc-shoptet-elogist')
        ];
    }
    
    return $schedules;
});

// Hook pro automatické naplánování XML synchronizace při změně intervalu
add_action('update_option_wse_xml_sync_interval', function($old_value, $new_value) {
    // Zrušit existující cron
    wp_clear_scheduled_hook('wse_xml_sync_cron');
    
    // Naplánovat nový s novým intervalem
    if (!empty($new_value)) {
        wp_schedule_event(time(), $new_value, 'wse_xml_sync_cron');
    }
}, 10, 2);

// Hook pro inicializaci XML sync cronu při aktivaci
add_action('wse_plugin_activated', function() {
    $interval = get_option('wse_xml_sync_interval', 'every_6_hours');
    
    if (!wp_next_scheduled('wse_xml_sync_cron')) {
        wp_schedule_event(time(), $interval, 'wse_xml_sync_cron');
    }
});

// Hook pro čištění při deaktivaci pluginu
register_deactivation_hook(WSE_PLUGIN_FILE, function() {
    // Vymazat všechny transients
    delete_transient('wse_xml_sync_running');
    
    // Zrušit naplánované úkoly
    wp_clear_scheduled_hook('wse_xml_sync_cron');
    wp_clear_scheduled_hook('wse_check_order_status');
    wp_clear_scheduled_hook('wse_cleanup_logs');
});

// Debug pomocník pro vývojáře
if (defined('WP_DEBUG') && WP_DEBUG) {
    // AJAX handler pro debug XML struktury
    add_action('wp_ajax_wse_debug_xml_structure', function() {
        check_ajax_referer('wse_test_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
        }
        
        try {
            $xml_feed_url = get_option('wse_xml_feed_url');
            
            if (empty($xml_feed_url)) {
                wp_send_json_error(__('XML Feed URL není nakonfigurována', 'wc-shoptet-elogist'));
            }
            
            // Stáhnout jen část XML pro analýzu
            $response = wp_remote_get($xml_feed_url, [
                'timeout' => 30,
                'headers' => [
                    'Range' => 'bytes=0-10240' // První 10KB
                ]
            ]);
            
            if (is_wp_error($response)) {
                wp_send_json_error('Chyba při stahování: ' . $response->get_error_message());
            }
            
            $xml_content = wp_remote_retrieve_body($response);
            
            // Pokusit se zparsovat
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml_content . '</SHOP>'); // Uzavřít tag pro parsování
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $error_messages = [];
                foreach ($errors as $error) {
                    $error_messages[] = trim($error->message);
                }
                wp_send_json_error('XML parsing errors: ' . implode('; ', $error_messages));
            }
            
            // Analyzovat strukturu
            $structure = [];
            if (isset($xml->SHOPITEM[0])) {
                $item = $xml->SHOPITEM[0];
                $structure['basic_fields'] = array_keys((array)$item);
                
                if (isset($item->VARIANTS->VARIANT[0])) {
                    $variant = $item->VARIANTS->VARIANT[0];
                    $structure['variant_fields'] = array_keys((array)$variant);
                    
                    if (isset($variant->PARAMETERS->PARAMETER)) {
                        $parameters = [];
                        foreach ($variant->PARAMETERS->PARAMETER as $param) {
                            $parameters[] = (string)$param->NAME . ': ' . (string)$param->VALUE;
                        }
                        $structure['variant_parameters'] = $parameters;
                    }
                }
                
                if (isset($item->CATEGORIES->CATEGORY)) {
                    $categories = [];
                    foreach ($item->CATEGORIES->CATEGORY as $cat) {
                        $categories[] = (string)$cat;
                    }
                    $structure['categories'] = array_slice($categories, 0, 5); // První 5
                }
            }
            
            wp_send_json_success([
                'message' => 'XML struktura analyzována',
                'structure' => $structure
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Chyba při analýze XML: ' . $e->getMessage());
        }
    });
}

// AJAX handler pro detailní test eLogist objednávky
add_action('wp_ajax_wse_test_elogist_order_detailed', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        // Kontrola eLogist konfigurace
        $username = get_option('wse_elogist_username');
        $password = get_option('wse_elogist_password');
        $project_id = get_option('wse_elogist_project_id');
        
        if (empty($username) || empty($password) || empty($project_id)) {
            wp_send_json_error(__('eLogist není kompletně nakonfigurován. Zkontrolujte uživatelské jméno, heslo a Project ID.', 'wc-shoptet-elogist'));
        }
        
        // Vytvoření test objednávky podle demo kódu
        $test_order_id = 'TEST-' . time();
        
        $param = new StdClass();
        $param->projectId = $project_id;
        $param->orderId = $test_order_id;
        $param->orderDateTime = date('c');
        $param->customerOrderId = 'WP-TEST-' . time();
        $param->packingInstruction = 'Testovací objednávka z WordPress - můžete ignorovat';
        
        // Sender
        $param->sender = new StdClass();
        $param->sender->label = get_bloginfo('name') ?: 'Test E-shop';
        
        // Recipient
        $address = new StdClass();
        $address->company = 'Test Company s.r.o.';
        $address->street = 'Testovací 123';
        $address->city = 'Praha';
        $address->postcode = '110 00';
        $address->country = 'CZ';
        
        $param->recipient = new StdClass();
        $param->recipient->name = 'Test Zákazník';
        $param->recipient->address = $address;
        $param->recipient->phone = '+420608077273';
        $param->recipient->email = 'test@example.com';
        
        // Shipping
        $param->shipping = new StdClass();
        $param->shipping->carrierId = 'PPL';
        $param->shipping->service = 'PPL Parcel CZ Private';
        $param->shipping->comment = 'Test zásilka - neexpedovat';
        
        // COD test
        $param->shipping->cod = new StdClass();
        $param->shipping->cod->_ = 299.0;
        $param->shipping->cod->currency = 'CZK';
        
        // Insurance
        $param->shipping->insurance = new StdClass();
        $param->shipping->insurance->_ = 299.0;
        $param->shipping->insurance->currency = 'CZK';
        
        // Order items - přesně podle demo kódu
        $param->orderItems = new StdClass();
        $param->orderItems->orderItem = [];
        
        $item = new StdClass();
        $item->productSheet = new StdClass();
        $item->productSheet->productId = 'TEST-001';
        $item->productSheet->productNumber = 'TST001';
        $item->productSheet->name = 'Testovací produkt';
        $item->productSheet->description = 'Produkt pro test integrace';
        $item->productSheet->vendor = 'Test Vendor';
        $item->productSheet->quantityUnit = 'PC';
        $item->quantity = 1;
        
        $param->orderItems->orderItem[0] = $item;
        
        // Debug - vypsat strukturu dat
        $debug_data = json_encode($param, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Odeslat do eLogist
        $elogist_api = new WSE_ELogist_API();
        
        try {
            $response = $elogist_api->send_delivery_order($param);
            
            if ($response && isset($response->result) && $response->result->code == 1000) {
                $sys_order_id = $response->deliveryOrderStatus->sysOrderId ?? 'N/A';
                $status = $response->deliveryOrderStatus->status ?? 'NEW';
                
                $message = sprintf(
                    __('✅ Test objednávka úspěšně odeslána!<br><br><strong>Order ID:</strong> %s<br><strong>System Order ID:</strong> %s<br><strong>Status:</strong> %s<br><br><details><summary>Odeslaná data (klikněte pro zobrazení)</summary><pre>%s</pre></details>', 'wc-shoptet-elogist'),
                    $test_order_id,
                    $sys_order_id,
                    $status,
                    esc_html($debug_data)
                );
                
                wp_send_json_success($message);
            } else {
                $error_code = $response->result->code ?? 'unknown';
                $error_description = $response->result->description ?? 'Neznámá chyba';
                $error_message = $elogist_api->get_error_message($error_code);
                
                // Získat SOAP request/response pro debug
                $last_request = $elogist_api->get_last_request();
                $last_response = $elogist_api->get_last_response();
                
                $debug_info = '';
                if ($last_request) {
                    $debug_info .= '<br><br><details><summary>SOAP Request</summary><pre>' . esc_html(substr($last_request, 0, 5000)) . '</pre></details>';
                }
                if ($last_response) {
                    $debug_info .= '<details><summary>SOAP Response</summary><pre>' . esc_html(substr($last_response, 0, 5000)) . '</pre></details>';
                }
                
                wp_send_json_error(sprintf(
                    __('❌ Chyba při odesílání:<br><strong>Kód:</strong> %s<br><strong>Popis:</strong> %s<br><strong>Řešení:</strong> %s%s', 'wc-shoptet-elogist'),
                    $error_code,
                    $error_description,
                    $error_message,
                    $debug_info
                ));
            }
        } catch (SoapFault $e) {
            $last_request = $elogist_api->get_last_request();
            $last_response = $elogist_api->get_last_response();
            
            $debug_info = '<br><br><strong>SOAP Fault:</strong> ' . esc_html($e->faultcode) . ' - ' . esc_html($e->faultstring);
            if ($last_request) {
                $debug_info .= '<br><br><details><summary>SOAP Request</summary><pre>' . esc_html(substr($last_request, 0, 5000)) . '</pre></details>';
            }
            if ($last_response) {
                $debug_info .= '<details><summary>SOAP Response</summary><pre>' . esc_html(substr($last_response, 0, 5000)) . '</pre></details>';
            }
            
            wp_send_json_error(__('❌ SOAP chyba: ', 'wc-shoptet-elogist') . esc_html($e->getMessage()) . $debug_info);
        }
        
    } catch (Exception $e) {
        wp_send_json_error(__('❌ Kritická chyba: ', 'wc-shoptet-elogist') . esc_html($e->getMessage()));
    }
});

// AJAX handler pro test konkrétní objednávky
add_action('wp_ajax_wse_test_specific_order', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if (!$order_id) {
        wp_send_json_error(__('Chybí ID objednávky', 'wc-shoptet-elogist'));
    }
    
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Objednávka nenalezena', 'wc-shoptet-elogist'));
        }
        
        // Resetovat eLogist meta data pro možnost znovu odeslat
        $order->delete_meta_data('_elogist_order_id');
        $order->delete_meta_data('_elogist_sys_order_id');
        $order->save();
        
        // Odeslat objednávku
        $result = WSE_Order_Sync::send_order_to_elogist($order_id);
        
        if ($result) {
            wp_send_json_success(__('✅ Objednávka byla úspěšně odeslána do eLogist systému.', 'wc-shoptet-elogist'));
        } else {
            // Získat poslední poznámku objednávky pro zobrazení chyby
            $notes = wc_get_order_notes([
                'order_id' => $order_id,
                'limit' => 1,
                'orderby' => 'date_created',
                'order' => 'DESC'
            ]);
            
            $error_message = '';
            if (!empty($notes)) {
                $error_message = '<br><br><strong>Detail:</strong> ' . esc_html($notes[0]->content);
            }
            
            wp_send_json_error(__('❌ Nepodařilo se odeslat objednávku do eLogist.', 'wc-shoptet-elogist') . $error_message);
        }
        
    } catch (Exception $e) {
        wp_send_json_error(__('❌ Chyba: ', 'wc-shoptet-elogist') . esc_html($e->getMessage()));
    }
});

// AJAX handler pro získání dopravců z eLogist
add_action('wp_ajax_wse_get_elogist_carriers', function() {
    check_ajax_referer('wse_test_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    try {
        $elogist_api = new WSE_ELogist_API();
        $response = $elogist_api->get_carriers();
        
        if ($response && isset($response->carrier)) {
            $carriers = is_array($response->carrier) ? $response->carrier : [$response->carrier];
            
            $html = '<h4>Dostupní dopravci v eLogist:</h4>';
            $html .= '<table class="widefat">';
            $html .= '<thead><tr><th>Carrier ID</th><th>Název</th><th>Služby</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($carriers as $carrier) {
                $services = [];
                if (isset($carrier->service)) {
                    $services_list = is_array($carrier->service) ? $carrier->service : [$carrier->service];
                    foreach ($services_list as $service) {
                        $services[] = $service->name ?? $service;
                    }
                }
                
                $html .= '<tr>';
                $html .= '<td><code>' . esc_html($carrier->carrierId ?? '') . '</code></td>';
                $html .= '<td>' . esc_html($carrier->name ?? '') . '</td>';
                $html .= '<td>' . esc_html(implode(', ', $services)) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            
            wp_send_json_success($html);
        } else {
            wp_send_json_error(__('Nepodařilo se získat seznam dopravců z eLogist', 'wc-shoptet-elogist'));
        }
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba: ', 'wc-shoptet-elogist') . esc_html($e->getMessage()));
    }
});

// Přidat do admin settings další tlačítka pro testování
add_action('admin_footer', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'wse-settings' && isset($_GET['tab']) && $_GET['tab'] === 'orders') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Přidat tlačítko pro detailní test
            var detailButton = '<button type="button" class="button" id="send-detailed-test-order" style="margin-left: 10px;">Detailní test objednávka</button>';
            $('#send-test-order').after(detailButton);
            
            // Přidat tlačítko pro získání dopravců
            var carriersButton = '<button type="button" class="button" id="get-carriers-list" style="margin-left: 10px;">Seznam dopravců</button>';
            $('#send-detailed-test-order').after(carriersButton);
            
            // Handler pro detailní test
            $('#send-detailed-test-order').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Odesílám...');
                
                $.post(ajaxurl, {
                    action: 'wse_test_elogist_order_detailed',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#test-order-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                    } else {
                        $('#test-order-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text(originalText);
                });
            });
            
            // Handler pro seznam dopravců
            $('#get-carriers-list').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Načítám...');
                
                $.post(ajaxurl, {
                    action: 'wse_get_elogist_carriers',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#test-order-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                    } else {
                        $('#test-order-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text(originalText);
                });
            });
        });
        </script>
        <?php
    }
});