<?php
/**
 * Product Meta Display Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Product_Meta_Display
{
    public static function init()
    {
        // Admin product meta display
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'display_xml_sync_info']);
        add_action('woocommerce_product_options_attributes', [__CLASS__, 'display_xml_attributes_info']);
        
        // Frontend product meta display
        add_action('woocommerce_single_product_summary', [__CLASS__, 'display_inspirovano_field'], 25);
        add_action('woocommerce_product_meta_end', [__CLASS__, 'display_additional_meta']);
        
        // Add custom fields to product variations
        add_action('woocommerce_product_after_variable_attributes', [__CLASS__, 'display_variation_xml_info'], 10, 3);
        
        // Admin columns
        add_filter('manage_edit-product_columns', [__CLASS__, 'add_admin_columns']);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'display_admin_column_content'], 10, 2);
    }

    /**
     * Zobrazení XML sync informací v admin product detail
     */
    public static function display_xml_sync_info()
    {
        global $post;
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        $xml_guid = get_post_meta($post->ID, '_xml_guid', true);
        $xml_id = get_post_meta($post->ID, '_xml_id', true);
        $xml_code = get_post_meta($post->ID, '_xml_code', true);
        $last_sync = get_post_meta($post->ID, '_xml_last_sync', true);
        $xml_manufacturer = get_post_meta($post->ID, '_xml_manufacturer', true);
        $xml_supplier = get_post_meta($post->ID, '_xml_supplier', true);
        $inspirovano = get_post_meta($post->ID, '_inspirovano', true);
        
        if ($xml_guid) {
            ?>
            <div class="options_group wse-xml-sync-info">
                <h4><?php _e('XML Feed synchronizace', 'wc-shoptet-elogist'); ?></h4>
                
                <p class="form-field">
                    <label><?php _e('XML GUID:', 'wc-shoptet-elogist'); ?></label>
                    <span><code><?php echo esc_html($xml_guid); ?></code></span>
                </p>
                
                <?php if ($xml_id): ?>
                <p class="form-field">
                    <label><?php _e('XML ID:', 'wc-shoptet-elogist'); ?></label>
                    <span><code><?php echo esc_html($xml_id); ?></code></span>
                </p>
                <?php endif; ?>
                
                <?php if ($xml_code): ?>
                <p class="form-field">
                    <label><?php _e('Kód produktu:', 'wc-shoptet-elogist'); ?></label>
                    <span><code><?php echo esc_html($xml_code); ?></code></span>
                </p>
                <?php endif; ?>
                
                <?php if ($xml_manufacturer): ?>
                <p class="form-field">
                    <label><?php _e('Výrobce:', 'wc-shoptet-elogist'); ?></label>
                    <span><strong><?php echo esc_html($xml_manufacturer); ?></strong></span>
                </p>
                <?php endif; ?>
                
                <?php if ($xml_supplier): ?>
                <p class="form-field">
                    <label><?php _e('Dodavatel:', 'wc-shoptet-elogist'); ?></label>
                    <span><?php echo esc_html($xml_supplier); ?></span>
                </p>
                <?php endif; ?>
                
                <?php if ($inspirovano): ?>
                <p class="form-field">
                    <label><?php _e('Inspirováno:', 'wc-shoptet-elogist'); ?></label>
                    <span><em><?php echo esc_html($inspirovano); ?></em></span>
                </p>
                <?php endif; ?>
                
                <?php if ($last_sync): ?>
                <p class="form-field">
                    <label><?php _e('Poslední XML sync:', 'wc-shoptet-elogist'); ?></label>
                    <span><?php echo esc_html(mysql2date('j.n.Y H:i:s', $last_sync)); ?></span>
                    <span class="wse-sync-status wse-sync-ok">✓</span>
                </p>
                <?php endif; ?>
                
                <p class="form-field">
                    <label><?php _e('Akce:', 'wc-shoptet-elogist'); ?></label>
                    <button type="button" class="button wse-resync-product" data-product-id="<?php echo $post->ID; ?>">
                        <?php _e('Resynchronizovat z XML', 'wc-shoptet-elogist'); ?>
                    </button>
                </p>
            </div>
            
            <style>
            .wse-xml-sync-info {
                border: 1px solid #ddd;
                background: #f9f9f9;
                padding: 15px;
                border-radius: 4px;
            }
            .wse-xml-sync-info h4 {
                margin-top: 0;
                color: #2271b1;
            }
            .wse-sync-status.wse-sync-ok {
                color: #00a32a;
                font-weight: bold;
                margin-left: 10px;
            }
            .wse-resync-product {
                margin-left: 10px;
            }
            </style>
            <?php
        }
    }

    /**
     * Zobrazení XML atributů v admin
     */
    public static function display_xml_attributes_info()
    {
        global $post;
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        $product = wc_get_product($post->ID);
        
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_children();
            
            if (!empty($variations)) {
                ?>
                <div class="options_group">
                    <h4><?php _e('XML Feed varianty', 'wc-shoptet-elogist'); ?></h4>
                    <div class="wse-variations-table-container">
                        <table class="widefat wse-variations-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Varianta', 'wc-shoptet-elogist'); ?></th>
                                    <th><?php _e('XML ID', 'wc-shoptet-elogist'); ?></th>
                                    <th><?php _e('Kód', 'wc-shoptet-elogist'); ?></th>
                                    <th><?php _e('Cena', 'wc-shoptet-elogist'); ?></th>
                                    <th><?php _e('Sklad', 'wc-shoptet-elogist'); ?></th>
                                    <th><?php _e('Sync', 'wc-shoptet-elogist'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($variations, 0, 15) as $variation_id): ?>
                                <?php 
                                $variation = wc_get_product($variation_id);
                                $xml_variant_id = get_post_meta($variation_id, '_xml_variant_id', true);
                                $xml_variant_code = get_post_meta($variation_id, '_xml_variant_code', true);
                                $xml_last_sync = get_post_meta($variation_id, '_xml_last_sync', true);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo admin_url('post.php?post=' . $variation_id . '&action=edit'); ?>" target="_blank">
                                            <?php echo $variation->get_name(); ?>
                                        </a>
                                        <div class="variation-attributes">
                                            <?php
                                            $attributes = $variation->get_attributes();
                                            foreach ($attributes as $attr_name => $attr_value) {
                                                echo '<small>' . esc_html($attr_name) . ': ' . esc_html($attr_value) . '</small><br>';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td><?php echo $xml_variant_id ? '<code>' . esc_html($xml_variant_id) . '</code>' : '-'; ?></td>
                                    <td><?php echo $xml_variant_code ? '<code>' . esc_html($xml_variant_code) . '</code>' : '-'; ?></td>
                                    <td>
                                        <?php if ($variation->get_price()): ?>
                                            <?php echo wc_price($variation->get_price()); ?>
                                            <?php if ($variation->is_on_sale()): ?>
                                                <br><small>Sleva z: <?php echo wc_price($variation->get_regular_price()); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($variation->managing_stock()): ?>
                                            <span class="stock-<?php echo $variation->get_stock_status(); ?>">
                                                <?php echo $variation->get_stock_quantity(); ?> ks
                                            </span>
                                        <?php else: ?>
                                            <span class="stock-<?php echo $variation->get_stock_status(); ?>">
                                                <?php echo $variation->get_stock_status() === 'instock' ? 'Skladem' : 'Vyprodáno'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($xml_last_sync): ?>
                                            <span class="sync-ok" title="<?php echo esc_attr(mysql2date('j.n.Y H:i:s', $xml_last_sync)); ?>">
                                                ✓ <?php echo esc_html(mysql2date('j.n', $xml_last_sync)); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="sync-none">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (count($variations) > 15): ?>
                                <tr>
                                    <td colspan="6">
                                        <em><?php printf(__('... a dalších %d variant', 'wc-shoptet-elogist'), count($variations) - 15); ?></em>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <style>
                .wse-variations-table-container {
                    max-height: 400px;
                    overflow-y: auto;
                    border: 1px solid #ddd;
                }
                .wse-variations-table {
                    margin: 0;
                }
                .wse-variations-table th,
                .wse-variations-table td {
                    padding: 8px;
                    border-bottom: 1px solid #eee;
                    font-size: 12px;
                }
                .wse-variations-table th {
                    background: #f9f9f9;
                    position: sticky;
                    top: 0;
                    z-index: 1;
                }
                .variation-attributes {
                    margin-top: 3px;
                }
                .variation-attributes small {
                    color: #666;
                    display: block;
                    line-height: 1.2;
                }
                .stock-instock {
                    color: #00a32a;
                }
                .stock-outofstock {
                    color: #d63638;
                }
                .sync-ok {
                    color: #00a32a;
                    font-size: 11px;
                }
                .sync-none {
                    color: #666;
                }
                </style>
                <?php
            }
        }
    }

    /**
     * Zobrazení "Inspirováno" pole na frontendu
     */
    public static function display_inspirovano_field()
    {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $inspirovano = get_post_meta($product->get_id(), '_inspirovano', true);
        
        if ($inspirovano) {
            ?>
            <div class="wse-inspirovano-field">
                <strong><?php _e('Inspirováno:', 'wc-shoptet-elogist'); ?></strong> 
                <em><?php echo esc_html($inspirovano); ?></em>
            </div>
            <style>
            .wse-inspirovano-field {
                margin: 15px 0;
                padding: 10px;
                background: #f8f9fa;
                border-left: 4px solid #2271b1;
                font-size: 14px;
            }
            </style>
            <?php
        }
    }

    /**
     * Zobrazení dodatečných metadat na frontendu
     */
    public static function display_additional_meta()
    {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $xml_manufacturer = get_post_meta($product->get_id(), '_xml_manufacturer', true);
        $xml_supplier = get_post_meta($product->get_id(), '_xml_supplier', true);
        
        ?>
        <div class="wse-additional-meta">
            <?php if ($xml_manufacturer): ?>
            <span class="wse-manufacturer">
                <?php _e('Výrobce:', 'wc-shoptet-elogist'); ?> 
                <strong><?php echo esc_html($xml_manufacturer); ?></strong>
            </span>
            <?php endif; ?>
            
            <?php if ($xml_supplier): ?>
            <span class="wse-supplier">
                <?php _e('Dodavatel:', 'wc-shoptet-elogist'); ?> 
                <?php echo esc_html($xml_supplier); ?>
            </span>
            <?php endif; ?>
        </div>
        
        <style>
        .wse-additional-meta {
            margin-top: 10px;
        }
        .wse-additional-meta span {
            display: block;
            margin: 5px 0;
            font-size: 13px;
            color: #666;
        }
        </style>
        <?php
    }

    /**
     * Zobrazení XML info u variant v admin
     */
    public static function display_variation_xml_info($loop, $variation_data, $variation)
    {
        $xml_variant_id = get_post_meta($variation->ID, '_xml_variant_id', true);
        $xml_variant_code = get_post_meta($variation->ID, '_xml_variant_code', true);
        $xml_last_sync = get_post_meta($variation->ID, '_xml_last_sync', true);
        
        if ($xml_variant_id) {
            ?>
            <div class="wse-variation-xml-info">
                <p><strong><?php _e('XML Feed data:', 'wc-shoptet-elogist'); ?></strong></p>
                <p>
                    <label><?php _e('XML ID:', 'wc-shoptet-elogist'); ?></label>
                    <code><?php echo esc_html($xml_variant_id); ?></code>
                </p>
                <?php if ($xml_variant_code): ?>
                <p>
                    <label><?php _e('Kód:', 'wc-shoptet-elogist'); ?></label>
                    <code><?php echo esc_html($xml_variant_code); ?></code>
                </p>
                <?php endif; ?>
                <?php if ($xml_last_sync): ?>
                <p>
                    <label><?php _e('Poslední sync:', 'wc-shoptet-elogist'); ?></label>
                    <?php echo esc_html(mysql2date('j.n.Y H:i:s', $xml_last_sync)); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <style>
            .wse-variation-xml-info {
                background: #f9f9f9;
                padding: 10px;
                border-radius: 4px;
                margin: 10px 0;
                border-left: 4px solid #2271b1;
            }
            .wse-variation-xml-info p {
                margin: 5px 0;
                font-size: 12px;
            }
            .wse-variation-xml-info label {
                font-weight: bold;
                margin-right: 5px;
            }
            </style>
            <?php
        }
    }

    /**
     * Přidání sloupců do admin product list
     */
    public static function add_admin_columns($columns)
    {
        $new_columns = [];
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Přidat XML sync sloupec po title
            if ($key === 'name') {
                $new_columns['wse_xml_sync'] = __('XML Sync', 'wc-shoptet-elogist');
            }
        }
        
        return $new_columns;
    }

    /**
     * Zobrazení obsahu XML sync sloupce
     */
    public static function display_admin_column_content($column, $post_id)
    {
        if ($column === 'wse_xml_sync') {
            $xml_guid = get_post_meta($post_id, '_xml_guid', true);
            $last_sync = get_post_meta($post_id, '_xml_last_sync', true);
            
            if ($xml_guid) {
                if ($last_sync) {
                    $sync_time = mysql2date('j.n.Y H:i', $last_sync);
                    echo '<span class="wse-sync-status wse-synced" title="Poslední sync: ' . esc_attr($sync_time) . '">✓ ' . esc_html($sync_time) . '</span>';
                } else {
                    echo '<span class="wse-sync-status wse-pending">Čeká na sync</span>';
                }
                
                // XML ID pro rychlou identifikaci
                echo '<br><small><code>' . esc_html(substr($xml_guid, 0, 8)) . '...</code></small>';
            } else {
                echo '<span class="wse-sync-status wse-manual">Manuální</span>';
            }
        }
    }
}

// CSS pro admin column
add_action('admin_head', function() {
    if (get_current_screen()->id === 'edit-product') {
        ?>
        <style>
        .column-wse_xml_sync {
            width: 120px;
        }
        .wse-sync-status {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }
        .wse-sync-status.wse-synced {
            background: #d5f4e6;
            color: #00a32a;
        }
        .wse-sync-status.wse-pending {
            background: #fff3cd;
            color: #856404;
        }
        .wse-sync-status.wse-manual {
            background: #f0f0f1;
            color: #50575e;
        }
        </style>
        <?php
    }
});

// AJAX handler pro resync produktu
add_action('wp_ajax_wse_resync_product', function() {
    check_ajax_referer('wse_resync_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wc-shoptet-elogist'));
    }
    
    $product_id = intval($_POST['product_id'] ?? 0);
    
    if (!$product_id) {
        wp_send_json_error(__('Invalid product ID', 'wc-shoptet-elogist'));
    }
    
    $xml_guid = get_post_meta($product_id, '_xml_guid', true);
    
    if (!$xml_guid) {
        wp_send_json_error(__('Produkt není synchronizován z XML', 'wc-shoptet-elogist'));
    }
    
    try {
        // Trigger resync for this specific product
        do_action('wse_resync_single_product', $product_id, $xml_guid);
        
        wp_send_json_success(__('Resynchronizace byla naplánována', 'wc-shoptet-elogist'));
        
    } catch (Exception $e) {
        wp_send_json_error(__('Chyba při resynchronizaci: ', 'wc-shoptet-elogist') . $e->getMessage());
    }
});

// JavaScript pro resync tlačítko
add_action('admin_footer', function() {
    if (get_current_screen()->id === 'product') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.wse-resync-product').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var productId = button.data('product-id');
                var originalText = button.text();
                
                if (!confirm('Opravdu chcete resynchronizovat tento produkt z XML feedu?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Resynchronizuji...');
                
                $.post(ajaxurl, {
                    action: 'wse_resync_product',
                    product_id: productId,
                    nonce: '<?php echo wp_create_nonce('wse_resync_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        button.text('✓ Naplánováno').css('color', '#00a32a');
                        setTimeout(function() {
                            button.prop('disabled', false).text(originalText).css('color', '');
                        }, 3000);
                    } else {
                        alert('Chyba: ' + response.data);
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }
});

// Initialize the class
WSE_Product_Meta_Display::init();