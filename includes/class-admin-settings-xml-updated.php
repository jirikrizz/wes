<?php
/**
 * Admin Settings Page - Aktualizovaná verze s XML Feed podporou
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Admin_Settings
{
    public static function add_admin_menu()
    {
        add_options_page(
            __('WooCommerce XML eLogist', 'wc-shoptet-elogist'),
            __('XML eLogist', 'wc-shoptet-elogist'),
            'manage_options',
            'wse-settings',
            [__CLASS__, 'settings_page']
        );
    }

    public static function init_settings()
    {
        // Registrace nastavení
        register_setting('wse_settings', 'wse_xml_feed_url');
        register_setting('wse_settings', 'wse_elogist_username');
        register_setting('wse_settings', 'wse_elogist_password');
        register_setting('wse_settings', 'wse_elogist_project_id');
        register_setting('wse_settings', 'wse_elogist_wsdl_url');
        register_setting('wse_settings', 'wse_xml_sync_interval');
        register_setting('wse_settings', 'wse_auto_publish_products');
        register_setting('wse_settings', 'wse_import_images');
        register_setting('wse_settings', 'wse_update_existing_products');

        // Sekce nastavení
        add_settings_section(
            'wse_xml_section',
            __('XML Feed nastavení', 'wc-shoptet-elogist'),
            [__CLASS__, 'xml_section_callback'],
            'wse_settings'
        );

        add_settings_section(
            'wse_elogist_section',
            __('eLogist nastavení', 'wc-shoptet-elogist'),
            [__CLASS__, 'elogist_section_callback'],
            'wse_settings'
        );

        add_settings_section(
            'wse_sync_section',
            __('Synchronizační nastavení', 'wc-shoptet-elogist'),
            [__CLASS__, 'sync_section_callback'],
            'wse_settings'
        );

        // XML Feed fields
        add_settings_field(
            'wse_xml_feed_url',
            __('XML Feed URL', 'wc-shoptet-elogist'),
            [__CLASS__, 'xml_feed_url_field'],
            'wse_settings',
            'wse_xml_section'
        );

        add_settings_field(
            'wse_xml_sync_interval',
            __('Interval synchronizace', 'wc-shoptet-elogist'),
            [__CLASS__, 'xml_sync_interval_field'],
            'wse_settings',
            'wse_xml_section'
        );

        // eLogist fields
        add_settings_field(
            'wse_elogist_username',
            __('Uživatelské jméno', 'wc-shoptet-elogist'),
            [__CLASS__, 'elogist_username_field'],
            'wse_settings',
            'wse_elogist_section'
        );

        add_settings_field(
            'wse_elogist_password',
            __('Heslo', 'wc-shoptet-elogist'),
            [__CLASS__, 'elogist_password_field'],
            'wse_settings',
            'wse_elogist_section'
        );

        add_settings_field(
            'wse_elogist_project_id',
            __('Project ID', 'wc-shoptet-elogist'),
            [__CLASS__, 'elogist_project_id_field'],
            'wse_settings',
            'wse_elogist_section'
        );

        add_settings_field(
            'wse_elogist_wsdl_url',
            __('WSDL URL', 'wc-shoptet-elogist'),
            [__CLASS__, 'elogist_wsdl_url_field'],
            'wse_settings',
            'wse_elogist_section'
        );

        // Sync fields
        add_settings_field(
            'wse_auto_publish_products',
            __('Automaticky publikovat produkty', 'wc-shoptet-elogist'),
            [__CLASS__, 'auto_publish_field'],
            'wse_settings',
            'wse_sync_section'
        );

        add_settings_field(
            'wse_import_images',
            __('Importovat obrázky', 'wc-shoptet-elogist'),
            [__CLASS__, 'import_images_field'],
            'wse_settings',
            'wse_sync_section'
        );

        add_settings_field(
            'wse_update_existing_products',
            __('Aktualizovat existující produkty', 'wc-shoptet-elogist'),
            [__CLASS__, 'update_existing_field'],
            'wse_settings',
            'wse_sync_section'
        );
    }

    public static function settings_page()
    {
        $active_tab = $_GET['tab'] ?? 'settings';
        ?>
        <div class="wrap wse-admin-page">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="nav-tab-wrapper">
                <a href="?page=wse-settings&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Nastavení', 'wc-shoptet-elogist'); ?>
                </a>
                <a href="?page=wse-settings&tab=xml" class="nav-tab <?php echo $active_tab === 'xml' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('XML Synchronizace', 'wc-shoptet-elogist'); ?>
                </a>
                <a href="?page=wse-settings&tab=diagnostics" class="nav-tab <?php echo $active_tab === 'diagnostics' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Diagnostika', 'wc-shoptet-elogist'); ?>
                </a>
                <a href="?page=wse-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logy', 'wc-shoptet-elogist'); ?>
                </a>
                <a href="?page=wse-settings&tab=orders" class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Objednávky', 'wc-shoptet-elogist'); ?>
                </a>
            </div>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'settings':
                        self::render_settings_tab();
                        break;
                    case 'xml':
                        self::render_xml_tab();
                        break;
                    case 'diagnostics':
                        self::render_diagnostics_tab();
                        break;
                    case 'logs':
                        self::render_logs_tab();
                        break;
                    case 'orders':
                        self::render_orders_tab();
                        break;
                }
                ?>
            </div>
        </div>

        <?php self::render_admin_scripts(); ?>
        <?php
    }

    private static function render_settings_tab()
    {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('wse_settings');
            do_settings_sections('wse_settings');
            submit_button();
            ?>
        </form>
        <?php
    }

    private static function render_xml_tab()
    {
        $last_sync = get_option('wse_last_xml_sync');
        if ($last_sync) {
            $last_sync = mysql2date('j.n.Y H:i:s', $last_sync);
        } else {
            $last_sync = __('Nikdy', 'wc-shoptet-elogist');
        }

        // Získání statistik
        $xml_feed_url = get_option('wse_xml_feed_url');
        $stats = null;
        if (!empty($xml_feed_url)) {
            try {
                $xml_sync = new WSE_XML_Feed_Sync($xml_feed_url);
                $stats = $xml_sync->get_sync_statistics();
            } catch (Exception $e) {
                // Ignorovat chyby při získávání statistik
            }
        }

        ?>
        <div style="margin: 20px 0;">
            <h2><?php _e('XML Feed Synchronizace', 'wc-shoptet-elogist'); ?></h2>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Stav synchronizace', 'wc-shoptet-elogist'); ?></h3>
                <p><strong><?php _e('Poslední synchronizace:', 'wc-shoptet-elogist'); ?></strong> <?php echo esc_html($last_sync); ?></p>
                
                <?php if ($stats): ?>
                <div class="wse-stats-grid">
                    <div class="wse-stat-item">
                        <div class="wse-stat-number"><?php echo number_format($stats['total_xml_products']); ?></div>
                        <div class="wse-stat-label"><?php _e('Produktů ze XML', 'wc-shoptet-elogist'); ?></div>
                    </div>
                    <div class="wse-stat-item">
                        <div class="wse-stat-number"><?php echo number_format($stats['total_xml_variants']); ?></div>
                        <div class="wse-stat-label"><?php _e('Variant produktů', 'wc-shoptet-elogist'); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <p>
                    <button type="button" class="button button-primary" id="test-xml-feed">
                        <?php _e('Test XML Feed', 'wc-shoptet-elogist'); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="sync-xml-products">
                        <?php _e('Spustit synchronizaci', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
                <div id="xml-test-result"></div>
                <div id="xml-sync-result"></div>
            </div>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Rozšířené možnosti', 'wc-shoptet-elogist'); ?></h3>
                
                <h4><?php _e('Vyčištění osiřelých produktů', 'wc-shoptet-elogist'); ?></h4>
                <p><?php _e('Smaže produkty, které již nejsou v XML feedu.', 'wc-shoptet-elogist'); ?></p>
                <p>
                    <button type="button" class="button" id="check-orphaned-products">
                        <?php _e('Zkontrolovat osiřelé produkty', 'wc-shoptet-elogist'); ?>
                    </button>
                    <button type="button" class="button button-delete" id="delete-orphaned-products" style="display: none;">
                        <?php _e('Smazat osiřelé produkty', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
                <div id="orphaned-products-result"></div>
                
                <hr>
                
                <h4><?php _e('Ruční synchronizace konkrétního produktu', 'wc-shoptet-elogist'); ?></h4>
                <p>
                    <input type="text" id="product-guid" placeholder="XML GUID produktu" class="regular-text">
                    <button type="button" class="button" id="sync-single-product">
                        <?php _e('Synchronizovat produkt', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
                <div id="single-product-result"></div>
            </div>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Automatická synchronizace', 'wc-shoptet-elogist'); ?></h3>
                <?php 
                $next_scheduled = wp_next_scheduled('wse_xml_sync_cron');
                if ($next_scheduled) {
                    $next_sync = date('j.n.Y H:i:s', $next_scheduled);
                } else {
                    $next_sync = __('Není naplánováno', 'wc-shoptet-elogist');
                }
                ?>
                <p><strong><?php _e('Příští automatická synchronizace:', 'wc-shoptet-elogist'); ?></strong> <?php echo esc_html($next_sync); ?></p>
                
                <p>
                    <button type="button" class="button" id="reschedule-cron">
                        <?php _e('Přeplánovat automatickou synchronizaci', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
            </div>
        </div>
        
        <style>
        .wse-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .wse-stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .wse-stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #2271b1;
        }
        .wse-stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .button-delete {
            background: #d63638 !important;
            border-color: #d63638 !important;
            color: white !important;
        }
        .button-delete:hover {
            background: #b32d2e !important;
            border-color: #b32d2e !important;
        }
        </style>
        <?php
    }

    private static function render_diagnostics_tab()
    {
        ?>
        <div style="margin: 20px 0;">
            <h2><?php _e('Systémová diagnostika', 'wc-shoptet-elogist'); ?></h2>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Systémové požadavky', 'wc-shoptet-elogist'); ?></h3>
                
                <?php 
                $requirements = self::check_system_requirements();
                ?>
                
                <table class="wse-requirements-table">
                    <thead>
                        <tr>
                            <th><?php _e('Požadavek', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('Stav', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('Hodnota', 'wc-shoptet-elogist'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requirements as $req => $data): ?>
                        <tr>
                            <td><?php echo esc_html($data['name']); ?></td>
                            <td>
                                <span class="<?php echo $data['status'] ? 'status-ok' : 'status-error'; ?>">
                                    <?php echo $data['status'] ? '✓' : '✗'; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($data['value']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Rozšířená diagnostika', 'wc-shoptet-elogist'); ?></h3>
                <p>
                    <button type="button" class="button button-primary" id="run-diagnostics">
                        <?php _e('Spustit diagnostiku', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
                <div id="diagnostics-result"></div>
            </div>
        </div>
        <?php
    }

    private static function render_logs_tab()
    {
        $logger = WSE_Logger::get_instance();
        $logs = $logger->get_logs(null, null, 100);
        $stats = $logger->get_log_stats();
        ?>
        <div style="margin: 20px 0;">
            <h2><?php _e('Systémové logy', 'wc-shoptet-elogist'); ?></h2>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Statistiky logů (24h)', 'wc-shoptet-elogist'); ?></h3>
                <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div>
                        <strong class="wse-status-error"><?php echo $stats['error']; ?></strong>
                        <span>chyb</span>
                    </div>
                    <div>
                        <strong class="wse-status-warning"><?php echo $stats['warning']; ?></strong>
                        <span>varování</span>
                    </div>
                    <div>
                        <strong class="wse-status-info"><?php echo $stats['info']; ?></strong>
                        <span>informací</span>
                    </div>
                    <div>
                        <strong><?php echo $stats['debug']; ?></strong>
                        <span>debug</span>
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 10px;">
                <button type="button" class="button" id="refresh-logs" onclick="location.reload();">
                    <?php _e('Obnovit', 'wc-shoptet-elogist'); ?>
                </button>
                <button type="button" class="button" onclick="if(confirm('Opravdu smazat všechny logy?')) location.href='?page=wse-settings&tab=logs&clear_logs=1';">
                    <?php _e('Vymazat logy', 'wc-shoptet-elogist'); ?>
                </button>
                
                <select id="log-level-filter" style="margin-left: 20px;">
                    <option value=""><?php _e('Všechny úrovně', 'wc-shoptet-elogist'); ?></option>
                    <option value="error"><?php _e('Pouze chyby', 'wc-shoptet-elogist'); ?></option>
                    <option value="warning"><?php _e('Varování a horší', 'wc-shoptet-elogist'); ?></option>
                    <option value="info"><?php _e('Info a horší', 'wc-shoptet-elogist'); ?></option>
                </select>
                
                <select id="log-source-filter" style="margin-left: 10px;">
                    <option value=""><?php _e('Všechny zdroje', 'wc-shoptet-elogist'); ?></option>
                    <option value="xml_sync">XML Sync</option>
                    <option value="elogist_api">eLogist API</option>
                    <option value="order_sync">Order Sync</option>
                </select>
            </div>
            
            <?php
            // Handle log clearing
            if (isset($_GET['clear_logs']) && current_user_can('manage_options')) {
                $logger->clear_all_logs();
                echo '<div class="notice notice-success"><p>' . __('Logy byly vymazány', 'wc-shoptet-elogist') . '</p></div>';
                $logs = [];
            }
            ?>
            
                            <table class="wse-logs-table" id="logs-table">
                <thead>
                    <tr>
                        <th style="width: 150px;"><?php _e('Čas', 'wc-shoptet-elogist'); ?></th>
                        <th style="width: 80px;"><?php _e('Úroveň', 'wc-shoptet-elogist'); ?></th>
                        <th style="width: 120px;"><?php _e('Zdroj', 'wc-shoptet-elogist'); ?></th>
                        <th><?php _e('Zpráva', 'wc-shoptet-elogist'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="4"><?php _e('Žádné logy k zobrazení', 'wc-shoptet-elogist'); ?></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr data-level="<?php echo esc_attr($log->level); ?>" data-source="<?php echo esc_attr($log->source); ?>">
                            <td><?php echo esc_html(mysql2date('j.n.Y H:i:s', $log->timestamp)); ?></td>
                            <td>
                                <span class="log-level-<?php echo esc_attr($log->level); ?>">
                                    <?php echo esc_html(ucfirst($log->level)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->source); ?></td>
                            <td>
                                <div><?php echo esc_html($log->message); ?></div>
                                <?php if (!empty($log->context) && $log->context !== '[]'): ?>
                                <div class="log-context">
                                    <details>
                                        <summary>Context</summary>
                                        <pre><?php echo esc_html($log->context); ?></pre>
                                    </details>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_orders_tab()
    {
        global $wpdb;
        
        // Získání synchronizovaných objednávek
        $table_name = $wpdb->prefix . 'wse_order_sync';
        $orders = $wpdb->get_results("
            SELECT os.*, p.post_date, p.post_status 
            FROM {$table_name} os 
            LEFT JOIN {$wpdb->posts} p ON os.wc_order_id = p.ID 
            ORDER BY os.last_status_check DESC 
            LIMIT 50
        ");
        ?>
        <div style="margin: 20px 0;">
            <h2><?php _e('Synchronizované objednávky', 'wc-shoptet-elogist'); ?></h2>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Test objednávka', 'wc-shoptet-elogist'); ?></h3>
                <p><?php _e('Odešle testovací objednávku do eLogist systému pro ověření funkčnosti.', 'wc-shoptet-elogist'); ?></p>
                <p>
                    <button type="button" class="button button-primary" id="send-test-order">
                        <?php _e('Odeslat test objednávku', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
                <div id="test-order-result"></div>
            </div>
            
            <div class="wse-diagnostic-box">
                <h3><?php _e('Přehled objednávek', 'wc-shoptet-elogist'); ?></h3>
                
                <?php if (!empty($orders)): ?>
                <p>
                    <strong><?php echo count($orders); ?></strong> 
                    <?php _e('synchronizovaných objednávek (posledních 50)', 'wc-shoptet-elogist'); ?>
                </p>
                <?php endif; ?>
                
                <table class="wse-orders-table">
                    <thead>
                        <tr>
                            <th><?php _e('WC Objednávka', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('eLogist ID', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('Stav', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('Tracking', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('Poslední check', 'wc-shoptet-elogist'); ?></th>
                            <th><?php _e('Akce', 'wc-shoptet-elogist'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="6"><?php _e('Žádné synchronizované objednávky', 'wc-shoptet-elogist'); ?></td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order_sync): ?>
                            <?php $order = wc_get_order($order_sync->wc_order_id); ?>
                            <tr>
                                <td>
                                    <?php if ($order): ?>
                                        <a href="<?php echo admin_url('post.php?post=' . $order_sync->wc_order_id . '&action=edit'); ?>" class="order-link">
                                            #<?php echo $order->get_order_number(); ?>
                                        </a>
                                        <div class="order-customer">
                                            <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?>
                                        </div>
                                        <small><?php echo esc_html(mysql2date('j.n.Y', $order_sync->post_date)); ?></small>
                                    <?php else: ?>
                                        #<?php echo $order_sync->wc_order_id; ?> <em>(smazána)</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($order_sync->elogist_order_id): ?>
                                        <code><?php echo esc_html($order_sync->elogist_order_id); ?></code>
                                        <?php if ($order_sync->elogist_sys_order_id): ?>
                                            <br><small>Sys: <?php echo esc_html($order_sync->elogist_sys_order_id); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <em>N/A</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr(strtolower($order_sync->current_status ?: 'new')); ?>">
                                        <?php echo esc_html($order_sync->current_status ?: 'NEW'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($order_sync->tracking_number): ?>
                                        <code><?php echo esc_html($order_sync->tracking_number); ?></code>
                                    <?php else: ?>
                                        <em>N/A</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(mysql2date('j.n.Y H:i', $order_sync->last_status_check)); ?></td>
                                <td>
                                    <?php if ($order): ?>
                                        <a href="<?php echo admin_url('post.php?post=' . $order_sync->wc_order_id . '&action=edit'); ?>" class="button button-small">
                                            <?php _e('Zobrazit', 'wc-shoptet-elogist'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function render_admin_scripts()
    {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Test XML Feed button
            $('#test-xml-feed').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Testování...');
                
                $.post(ajaxurl, {
                    action: 'wse_test_xml_feed',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#xml-test-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                    } else {
                        $('#xml-test-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text(originalText);
                });
            });

            // XML Sync button
            $('#sync-xml-products').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Synchronizuji...');
                
                $.post(ajaxurl, {
                    action: 'wse_sync_xml_feed',
                    nonce: '<?php echo wp_create_nonce('wse_sync_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#xml-sync-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                    } else {
                        $('#xml-sync-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text(originalText);
                });
            });

            // Check orphaned products
            $('#check-orphaned-products').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Kontroluji...');
                
                $.post(ajaxurl, {
                    action: 'wse_check_orphaned_products',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#orphaned-products-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                        if (response.data.indexOf('0 osiřelých') === -1) {
                            $('#delete-orphaned-products').show();
                        }
                    } else {
                        $('#orphaned-products-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Zkontrolovat osiřelé produkty');
                });
            });

            // Delete orphaned products
            $('#delete-orphaned-products').on('click', function() {
                if (!confirm('Opravdu chcete smazat všechny osiřelé produkty? Tato akce je nevratná!')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('Mazání...');
                
                $.post(ajaxurl, {
                    action: 'wse_delete_orphaned_products',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#orphaned-products-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                        button.hide();
                    } else {
                        $('#orphaned-products-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Smazat osiřelé produkty');
                });
            });

            // Sync single product
            $('#sync-single-product').on('click', function() {
                var guid = $('#product-guid').val().trim();
                if (!guid) {
                    alert('Zadejte XML GUID produktu');
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('Synchronizuji...');
                
                $.post(ajaxurl, {
                    action: 'wse_sync_single_product',
                    guid: guid,
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#single-product-result').html('<div class="wse-test-result success">' + response.data + '</div>');
                        $('#product-guid').val('');
                    } else {
                        $('#single-product-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Synchronizovat produkt');
                });
            });

            // Test eLogist connection
            $('#send-test-order').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Odesílám...');
                
                $.post(ajaxurl, {
                    action: 'wse_send_test_order',
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

            // Diagnostics button
            $('#run-diagnostics').on('click', function() {
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Spouštím diagnostiku...');
                
                $.post(ajaxurl, {
                    action: 'wse_run_diagnostics',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#diagnostics-result').html(response.data);
                    } else {
                        $('#diagnostics-result').html('<div class="wse-test-result error">' + response.data + '</div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text(originalText);
                });
            });

            // Log filtering
            $('#log-level-filter, #log-source-filter').on('change', function() {
                var levelFilter = $('#log-level-filter').val();
                var sourceFilter = $('#log-source-filter').val();
                
                $('#logs-table tbody tr').each(function() {
                    var row = $(this);
                    var level = row.data('level');
                    var source = row.data('source');
                    var showRow = true;
                    
                    // Level filtering
                    if (levelFilter) {
                        if (levelFilter === 'error' && level !== 'error') {
                            showRow = false;
                        } else if (levelFilter === 'warning' && !['error', 'warning'].includes(level)) {
                            showRow = false;
                        } else if (levelFilter === 'info' && !['error', 'warning', 'info'].includes(level)) {
                            showRow = false;
                        }
                    }
                    
                    // Source filtering
                    if (sourceFilter && source !== sourceFilter) {
                        showRow = false;
                    }
                    
                    row.toggle(showRow);
                });
            });

            // Reschedule cron
            $('#reschedule-cron').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Přeplánovávám...');
                
                $.post(ajaxurl, {
                    action: 'wse_reschedule_cron',
                    nonce: '<?php echo wp_create_nonce('wse_test_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Automatická synchronizace byla přeplánována');
                        location.reload();
                    } else {
                        alert('Chyba: ' + response.data);
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Přeplánovat automatickou synchronizaci');
                });
            });
        });
        </script>
        
        <style>
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
        
        .wse-requirements-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .wse-requirements-table th,
        .wse-requirements-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .wse-requirements-table th {
            background: #f9f9f9;
            font-weight: bold;
        }
        
        .status-ok {
            color: #00a32a;
            font-weight: bold;
        }
        
        .status-error {
            color: #d63638;
            font-weight: bold;
        }
        
        .wse-logs-table,
        .wse-orders-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .wse-logs-table th,
        .wse-logs-table td,
        .wse-orders-table th,
        .wse-orders-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
            font-size: 13px;
        }
        
        .wse-logs-table th,
        .wse-orders-table th {
            background: #f9f9f9;
            font-weight: bold;
        }
        
        .log-level-error {
            color: #d63638;
            font-weight: bold;
        }
        
        .log-level-warning {
            color: #dba617;
            font-weight: bold;
        }
        
        .log-level-info {
            color: #2271b1;
        }
        
        .log-context {
            margin-top: 5px;
        }
        
        .log-context summary {
            cursor: pointer;
            color: #666;
            font-size: 11px;
        }
        
        .log-context pre {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 3px;
            font-size: 11px;
            max-height: 200px;
            overflow: auto;
        }
        
        .order-customer {
            font-size: 11px;
            color: #666;
        }
        
        .order-link {
            font-weight: bold;
            text-decoration: none;
        }
        
        .status-new,
        .status-shipped,
        .status-delivered,
        .status-cancelled {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-new {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-shipped {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-delivered {
            background: #d4edda;
            color: #155724;
        }
        
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .wse-status-error {
            color: #d63638;
        }
        
        .wse-status-warning {
            color: #dba617;
        }
        
        .wse-status-info {
            color: #2271b1;
        }
        </style>
        <?php
    }

    private static function check_system_requirements()
    {
        return [
            'php_version' => [
                'name' => 'PHP verze',
                'status' => version_compare(PHP_VERSION, '7.4', '>='),
                'value' => PHP_VERSION . ' (požadováno 7.4+)'
            ],
            'wordpress_version' => [
                'name' => 'WordPress verze',
                'status' => version_compare(get_bloginfo('version'), '5.0', '>='),
                'value' => get_bloginfo('version') . ' (požadováno 5.0+)'
            ],
            'woocommerce' => [
                'name' => 'WooCommerce',
                'status' => class_exists('WooCommerce'),
                'value' => class_exists('WooCommerce') ? (defined('WC_VERSION') ? WC_VERSION : 'nainstalováno') : 'nenainstalováno'
            ],
            'simplexml_extension' => [
                'name' => 'SimpleXML extension',
                'status' => extension_loaded('simplexml'),
                'value' => extension_loaded('simplexml') ? 'nainstalováno' : 'chybí'
            ],
            'libxml_extension' => [
                'name' => 'LibXML extension',
                'status' => extension_loaded('libxml'),
                'value' => extension_loaded('libxml') ? 'nainstalováno' : 'chybí'
            ],
            'soap_extension' => [
                'name' => 'SOAP extension',
                'status' => extension_loaded('soap'),
                'value' => extension_loaded('soap') ? 'nainstalováno' : 'chybí'
            ],
            'curl_extension' => [
                'name' => 'cURL extension',
                'status' => extension_loaded('curl'),
                'value' => extension_loaded('curl') ? 'nainstalováno' : 'chybí'
            ],
            'memory_limit' => [
                'name' => 'PHP Memory Limit',
                'status' => wp_convert_hr_to_bytes(ini_get('memory_limit')) >= 134217728, // 128MB
                'value' => ini_get('memory_limit') . ' (doporučeno 128MB+)'
            ],
            'max_execution_time' => [
                'name' => 'Max Execution Time',
                'status' => ini_get('max_execution_time') >= 30,
                'value' => ini_get('max_execution_time') . 's (doporučeno 30s+)'
            ]
        ];
    }

    // Section callbacks
    public static function xml_section_callback()
    {
        echo '<p>' . __('Nastavení pro připojení k XML feedu s produkty. Zadejte URL vašeho XML feedu.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function elogist_section_callback()
    {
        echo '<p>' . __('Nastavení pro připojení k eLogist fulfillment systému. Přihlašovací údaje získáte od eLogist.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function sync_section_callback()
    {
        echo '<p>' . __('Nastavení chování při synchronizaci produktů z XML feedu.', 'wc-shoptet-elogist') . '</p>';
    }

    // Field callbacks
    public static function xml_feed_url_field()
    {
        $value = get_option('wse_xml_feed_url', 'https://www.krasnevune.cz/export/productsSupplier.xml?patternId=-4&partnerId=18&hash=2df6184d275f04d885a63e3926ffdad192b2f549ddbcb43ce0780e06c46cf213&manufacturerId=33');
        echo '<input type="url" name="wse_xml_feed_url" value="' . esc_attr($value) . '" class="large-text" placeholder="https://example.com/feed.xml" />';
        echo '<p class="description">' . __('URL XML feedu s produkty. Měl by obsahovat SHOPITEM elementy s variantami.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function xml_sync_interval_field()
    {
        $value = get_option('wse_xml_sync_interval', 'every_6_hours');
        $intervals = [
            'hourly' => __('Každou hodinu', 'wc-shoptet-elogist'),
            'every_6_hours' => __('Každých 6 hodin', 'wc-shoptet-elogist'),
            'twicedaily' => __('Dvakrát denně', 'wc-shoptet-elogist'),
            'daily' => __('Jednou denně', 'wc-shoptet-elogist'),
            'weekly' => __('Jednou týdně', 'wc-shoptet-elogist')
        ];
        
        echo '<select name="wse_xml_sync_interval">';
        foreach ($intervals as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Jak často se má automaticky spouštět synchronizace z XML feedu.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function elogist_username_field()
    {
        $value = get_option('wse_elogist_username');
        echo '<input type="text" name="wse_elogist_username" value="' . esc_attr($value) . '" class="regular-text" placeholder="Uživatelské jméno" />';
        echo '<p class="description">' . __('Uživatelské jméno pro eLogist systém.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function elogist_password_field()
    {
        $value = get_option('wse_elogist_password');
        echo '<input type="password" name="wse_elogist_password" value="' . esc_attr($value) . '" class="regular-text" placeholder="Heslo" />';
        echo '<p class="description">' . __('Heslo pro eLogist systém.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function elogist_project_id_field()
    {
        $value = get_option('wse_elogist_project_id');
        echo '<input type="text" name="wse_elogist_project_id" value="' . esc_attr($value) . '" class="regular-text" placeholder="Project ID" />';
        echo '<p class="description">' . __('Project ID v eLogist systému.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function elogist_wsdl_url_field()
    {
        $value = get_option('wse_elogist_wsdl_url', 'https://elogist-demo.shipmall.cz/api/soap?wsdl');
        echo '<input type="url" name="wse_elogist_wsdl_url" value="' . esc_attr($value) . '" class="large-text" />';
        echo '<p class="description">' . __('WSDL URL pro eLogist SOAP API.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function auto_publish_field()
    {
        $value = get_option('wse_auto_publish_products', true);
        echo '<input type="checkbox" name="wse_auto_publish_products" value="1"' . checked($value, true, false) . ' />';
        echo '<label for="wse_auto_publish_products">' . __('Automaticky publikovat nové produkty', 'wc-shoptet-elogist') . '</label>';
        echo '<p class="description">' . __('Nově importované produkty budou automaticky publikovány ve WooCommerce.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function import_images_field()
    {
        $value = get_option('wse_import_images', true);
        echo '<input type="checkbox" name="wse_import_images" value="1"' . checked($value, true, false) . ' />';
        echo '<label for="wse_import_images">' . __('Importovat obrázky produktů', 'wc-shoptet-elogist') . '</label>';
        echo '<p class="description">' . __('Obrázky z XML feedu budou staženy a importovány do WordPress media knihovny.', 'wc-shoptet-elogist') . '</p>';
    }

    public static function update_existing_field()
    {
        $value = get_option('wse_update_existing_products', true);
        echo '<input type="checkbox" name="wse_update_existing_products" value="1"' . checked($value, true, false) . ' />';
        echo '<label for="wse_update_existing_products">' . __('Aktualizovat existující produkty', 'wc-shoptet-elogist') . '</label>';
        echo '<p class="description">' . __('Existující produkty budou aktualizovány při každé synchronizaci (ceny, skladové zásoby, atd.).', 'wc-shoptet-elogist') . '</p>';
    }
}