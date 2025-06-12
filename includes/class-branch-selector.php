<?php
/**
 * Přidejte tento kód do functions.php vašeho tématu nebo vytvořte samostatný plugin
 * Pro integraci Zásilkovna widgetu
 */

// Přidat Zásilkovna widget script
add_action('wp_enqueue_scripts', function() {
    if (is_checkout()) {
        // Zásilkovna widget
        wp_enqueue_script(
            'zasilkovna-widget',
            'https://widget.packeta.com/v6/www/js/library.js',
            [],
            '6.0',
            true
        );
        
        // Vlastní script pro integraci
        wp_add_inline_script('zasilkovna-widget', '
            var zasilkovnaApiKey = "' . get_option('wse_zasilkovna_api_key', '') . '";
            
            jQuery(document).ready(function($) {
                // Handler pro Zásilkovna widget
                $(document).on("click", ".wse-select-branch[data-carrier=ZASILKOVNA]", function(e) {
                    e.preventDefault();
                    
                    var button = $(this);
                    var methodId = button.data("method-id");
                    
                    // Otevřít Zásilkovna widget
                    Packeta.Widget.pick(zasilkovnaApiKey, function(point) {
                        if (point) {
                            // Uložit vybranou pobočku
                            $("#wse_selected_branch_" + methodId).val(point.id);
                            button.siblings(".wse-selected-branch-name").text(" - " + point.name + ", " + point.city);
                            
                            // Uložit do session
                            $.post("' . admin_url('admin-ajax.php') . '", {
                                action: "wse_save_branch_selection",
                                method_id: methodId,
                                branch_id: point.id,
                                branch_name: point.name,
                                branch_city: point.city,
                                carrier: "ZASILKOVNA"
                            });
                        }
                    }, {
                        country: "cz",
                        language: "cs"
                    });
                });
            });
        ');
    }
});

// Rozšířený AJAX handler pro uložení výběru pobočky s více informacemi
add_action('wp_ajax_wse_save_branch_selection', function() {
    $method_id = sanitize_text_field($_POST['method_id'] ?? '');
    $branch_id = sanitize_text_field($_POST['branch_id'] ?? '');
    $carrier = sanitize_text_field($_POST['carrier'] ?? '');
    $branch_name = sanitize_text_field($_POST['branch_name'] ?? '');
    $branch_city = sanitize_text_field($_POST['branch_city'] ?? '');
    
    if ($method_id && $branch_id) {
        WC()->session->set('wse_selected_branch_' . $method_id, [
            'branch_id' => $branch_id,
            'carrier' => $carrier,
            'branch_name' => $branch_name,
            'branch_city' => $branch_city
        ]);
        
        wp_send_json_success();
    }
    
    wp_send_json_error();
});
add_action('wp_ajax_nopriv_wse_save_branch_selection', function() {
    do_action('wp_ajax_wse_save_branch_selection');
});

// Validace při checkout - kontrola, zda je vybraná pobočka
add_action('woocommerce_checkout_process', function() {
    $chosen_shipping = WC()->session->get('chosen_shipping_methods');
    
    if (!empty($chosen_shipping)) {
        $shipping_method = reset($chosen_shipping);
        
        // Kontrola, zda metoda vyžaduje výběr pobočky (ne pro Zásilkovnu na adresu)
        $methods_requiring_branch = [
            'wse_elogist_ppl_parcelshop',
            'wse_elogist_zasilkovna',
            'wse_elogist_zasilkovna_zbox'
        ];
        
        if (in_array($shipping_method, $methods_requiring_branch)) {
            $branch_data = WC()->session->get('wse_selected_branch_' . $shipping_method);
            
            if (empty($branch_data) || empty($branch_data['branch_id'])) {
                $method_name = '';
                
                switch($shipping_method) {
                    case 'wse_elogist_ppl_parcelshop':
                        $method_name = 'PPL ParcelShop';
                        break;
                    case 'wse_elogist_zasilkovna':
                        $method_name = 'Zásilkovna';
                        break;
                    case 'wse_elogist_zasilkovna_zbox':
                        $method_name = 'Zásilkovna Z-Box';
                        break;
                }
                
                wc_add_notice(
                    sprintf(__('Prosím vyberte výdejní místo pro %s.', 'wc-shoptet-elogist'), $method_name), 
                    'error'
                );
            }
        }
    }
});

// Zobrazit vybranou pobočku v potvrzení objednávky a emailech
add_filter('woocommerce_order_shipping_method', function($shipping_method, $order) {
    $shipping_items = $order->get_shipping_methods();
    
    foreach ($shipping_items as $shipping_item) {
        $branch_id = $shipping_item->get_meta('_branch_id');
        $branch_name = $shipping_item->get_meta('_branch_name');
        $branch_city = $shipping_item->get_meta('_branch_city');
        
        if ($branch_id && $branch_name) {
            $shipping_method .= '<br><small>' . sprintf(__('Výdejní místo: %s, %s', 'wc-shoptet-elogist'), $branch_name, $branch_city) . '</small>';
        }
    }
    
    return $shipping_method;
}, 10, 2);

// Přidat nastavení pro Zásilkovna API klíč
add_action('admin_init', function() {
    register_setting('wse_settings', 'wse_zasilkovna_api_key');
    
    add_settings_field(
        'wse_zasilkovna_api_key',
        __('Zásilkovna API klíč', 'wc-shoptet-elogist'),
        function() {
            $value = get_option('wse_zasilkovna_api_key', '');
            echo '<input type="text" name="wse_zasilkovna_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
            echo '<p class="description">' . __('API klíč pro Zásilkovna widget. Získáte ho v klientské sekci Zásilkovny.', 'wc-shoptet-elogist') . '</p>';
        },
        'wse_settings',
        'wse_elogist_section'
    );
});

// Uložit kompletní informace o pobočce do objednávky
add_action('woocommerce_checkout_create_order_shipping_item', function($item, $package_key, $package, $order) {
    $shipping_method = $item->get_method_id();
    $branch_data = WC()->session->get('wse_selected_branch_' . $shipping_method);
    
    if ($branch_data && isset($branch_data['branch_id'])) {
        $item->add_meta_data('_branch_id', $branch_data['branch_id']);
        $item->add_meta_data('_carrier_id', $branch_data['carrier'] ?? '');
        
        if (!empty($branch_data['branch_name'])) {
            $item->add_meta_data('_branch_name', $branch_data['branch_name']);
        }
        if (!empty($branch_data['branch_city'])) {
            $item->add_meta_data('_branch_city', $branch_data['branch_city']);
        }
    }
}, 10, 4);

// CSS pro lepší vzhled
add_action('wp_head', function() {
    if (is_checkout()) {
        ?>
        <style>
        .wse-branch-selector {
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .wse-select-branch {
            font-size: 13px;
            padding: 5px 15px;
            height: auto;
            background: #2271b1;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .wse-select-branch:hover {
            background: #135e96;
        }
        .wse-selected-branch-name {
            display: block;
            margin-top: 5px;
            font-weight: bold;
            color: #2271b1;
        }
        .shipping_method:checked + label .wse-branch-selector {
            background: #e8f4f8;
        }
        </style>
        <?php
    }
});