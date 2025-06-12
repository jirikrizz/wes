<?php
/**
 * eLogist Shipping Method - OPRAVENÁ VERZE
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_ELogist_Shipping extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id = 'wse_elogist_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('eLogist Doprava', 'wc-shoptet-elogist');
        $this->method_description = __('Dopravní metody z eLogist systému', 'wc-shoptet-elogist');
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];

        $this->init();
    }

    public function init() {
        // Debug: zaznamenat inicializaci
        error_log(sprintf(
            '[ELOGIST DEBUG] WSE_ELogist_Shipping init() – id=%s, instance=%s, čas=%s',
            $this->id,
            $this->instance_id,
            current_time('mysql')
        ));

        // Globální (neinstanční) nastavení
        $this->init_form_fields();

        // Instanční nastavení (pro zónu dopravy)
        $this->init_instance_form_fields();

        // Načíst všechna nastavení
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');

        // Hooky pro uložení v adminu
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_update_options_shipping_' . $this->id . '_' . $this->instance_id, [$this, 'process_admin_options']);
    }

    /**
     * Globální nastavení (volitelné)
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Globálně povolit', 'wc-shoptet-elogist'),
                'type' => 'checkbox',
                'description' => __('Povolit eLogist dopravu globálně', 'wc-shoptet-elogist'),
                'default' => 'yes',
            ],
        ];
    }

    /**
     * Instanční nastavení pro zónu
     */
    public function init_instance_form_fields() {
        $this->instance_form_fields = [
            'enabled' => [
                'title' => __('Povolit v této zóně', 'wc-shoptet-elogist'),
                'type' => 'checkbox',
                'description' => __('Povolit eLogist dopravu v této zóně', 'wc-shoptet-elogist'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Název metody', 'wc-shoptet-elogist'),
                'type' => 'text',
                'description' => __('Název zobrazovaný zákazníkovi', 'wc-shoptet-elogist'),
                'default' => __('Doprava', 'wc-shoptet-elogist'),
            ],
            'ppl_enabled' => [
                'title' => __('PPL – adresa', 'wc-shoptet-elogist'),
                'type' => 'checkbox',
                'description' => __('Povolit PPL doručení na adresu', 'wc-shoptet-elogist'),
                'default' => 'yes',
            ],
            'ppl_cost' => [
                'title' => __('PPL – cena', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Cena za PPL doručení', 'wc-shoptet-elogist'),
                'default' => '99',
                'desc_tip' => true,
            ],
            'ppl_free_limit' => [
                'title' => __('PPL – limit zdarma', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Limit objednávky pro dopravu zdarma', 'wc-shoptet-elogist'),
                'default' => '1500',
                'desc_tip' => true,
            ],
            'ppl_parcelshop_enabled' => [
                'title' => __('PPL ParcelShop', 'wc-shoptet-elogist'),
                'type' => 'checkbox',
                'description' => __('Povolit PPL ParcelShop', 'wc-shoptet-elogist'),
                'default' => 'yes',
            ],
            'ppl_parcelshop_cost' => [
                'title' => __('ParcelShop – cena', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Cena za ParcelShop', 'wc-shoptet-elogist'),
                'default' => '69',
                'desc_tip' => true,
            ],
            'ppl_parcelshop_free_limit' => [
                'title' => __('ParcelShop – limit zdarma', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Limit objednávky pro ParcelShop zdarma', 'wc-shoptet-elogist'),
                'default' => '1000',
                'desc_tip' => true,
            ],
            'zasilkovna_enabled' => [
                'title' => __('Zásilkovna – výdejní místa', 'wc-shoptet-elogist'),
                'type' => 'checkbox',
                'description' => __('Povolit výdejní místa/Z-Box', 'wc-shoptet-elogist'),
                'default' => 'yes',
            ],
            'zasilkovna_cost' => [
                'title' => __('Zásilkovna – cena', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Cena za výdejní místa', 'wc-shoptet-elogist'),
                'default' => '59',
                'desc_tip' => true,
            ],
            'zasilkovna_free_limit' => [
                'title' => __('Zásilkovna – limit zdarma', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Limit objednávky pro výdejní místa zdarma', 'wc-shoptet-elogist'),
                'default' => '700',
                'desc_tip' => true,
            ],
            'zasilkovna_home_enabled' => [
                'title' => __('Zásilkovna na adresu', 'wc-shoptet-elogist'),
                'type' => 'checkbox',
                'description' => __('Povolit doručení na adresu', 'wc-shoptet-elogist'),
                'default' => 'yes',
            ],
            'zasilkovna_home_cost' => [
                'title' => __('Zásilkovna na adresu – cena', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Cena za doručení na adresu', 'wc-shoptet-elogist'),
                'default' => '89',
                'desc_tip' => true,
            ],
            'zasilkovna_home_free_limit' => [
                'title' => __('Zásilkovna na adresu – limit zdarma', 'wc-shoptet-elogist'),
                'type' => 'number',
                'description' => __('Limit objednávky pro adresu zdarma', 'wc-shoptet-elogist'),
                'default' => '1200',
                'desc_tip' => true,
            ],
        ];
    }

    public function process_admin_options() {
        // Debug: zaznamenat pole v POST a uložení
        error_log(sprintf(
            '[ELOGIST DEBUG] process_admin_options – id=%s instance=%s POST keys=%s',
            $this->id,
            $this->instance_id,
            implode(', ', array_keys($_POST))
        ));

        parent::process_admin_options();

        error_log(sprintf(
            '[ELOGIST DEBUG] settings po uložení – %s',
            print_r($this->settings, true)
        ));
    }

    /**
     * Rozšířená calculate_shipping s debug logováním
     */
    public function calculate_shipping($package = [])
    {
        // Debug: log metodu calculate_shipping
        error_log(sprintf(
            '[ELOGIST DEBUG] calculate_shipping called – id=%s, instance=%s, enabled=%s',
            $this->id,
            $this->instance_id,
            $this->get_option('enabled')
        ));

        if ($this->get_option('enabled') !== 'yes') {
            error_log('[ELOGIST DEBUG] Shipping method is disabled, skipping');
            return;
        }

        // Výpočet celkové hodnoty košíku
        $cart_total = 0;
        if (WC()->cart) {
            $cart_total = WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax();
        }

        error_log(sprintf('[ELOGIST DEBUG] Cart total: %s', $cart_total));

        $rates_added = 0;

        // PPL - doručení na adresu
        if ($this->get_option('ppl_enabled') === 'yes') {
            $cost = floatval($this->get_option('ppl_cost', 99));
            $free_limit = floatval($this->get_option('ppl_free_limit', 1500));

            if ($cart_total >= $free_limit && $free_limit > 0) {
                $cost = 0;
            }

            $label = 'PPL - doručení na adresu';
            if ($cost > 0) {
                $label .= ' (' . wc_price($cost) . ')';
            } else {
                $label .= ' - ' . __('Zdarma', 'wc-shoptet-elogist');
            }

            $rate_data = [
                'id' => $this->get_rate_id('ppl'),
                'label' => $label,
                'cost' => $cost,
                'meta_data' => [
                    'carrier_id' => 'PPL',
                    'carrier_service' => 'PPL Parcel CZ Private',
                    'carrier_name' => 'PPL',
                    'pickup_point_required' => false
                ]
            ];

            $this->add_rate($rate_data);
            $rates_added++;

            error_log(sprintf('[ELOGIST DEBUG] Added PPL rate: %s (cost: %s)', $label, $cost));
        }

        // PPL ParcelShop
        if ($this->get_option('ppl_parcelshop_enabled') === 'yes') {
            $cost = floatval($this->get_option('ppl_parcelshop_cost', 69));
            $free_limit = floatval($this->get_option('ppl_parcelshop_free_limit', 1000));

            if ($cart_total >= $free_limit && $free_limit > 0) {
                $cost = 0;
            }

            $label = 'PPL ParcelShop';
            if ($cost > 0) {
                $label .= ' (' . wc_price($cost) . ')';
            } else {
                $label .= ' - ' . __('Zdarma', 'wc-shoptet-elogist');
            }

            $rate_data = [
                'id' => $this->get_rate_id('ppl_parcelshop'),
                'label' => $label,
                'cost' => $cost,
                'meta_data' => [
                    'carrier_id' => 'PPL',
                    'carrier_service' => 'ParcelShop',
                    'carrier_name' => 'PPL ParcelShop',
                    'pickup_point_required' => true,
                    'widget_type' => 'ppl_parcelshop'
                ]
            ];

            $this->add_rate($rate_data);
            $rates_added++;

            error_log(sprintf('[ELOGIST DEBUG] Added PPL ParcelShop rate: %s (cost: %s)', $label, $cost));
        }

        // Zásilkovna - výdejní místa (včetně Z-Boxů)
        if ($this->get_option('zasilkovna_enabled') === 'yes') {
            $cost = floatval($this->get_option('zasilkovna_cost', 59));
            $free_limit = floatval($this->get_option('zasilkovna_free_limit', 700));

            if ($cart_total >= $free_limit && $free_limit > 0) {
                $cost = 0;
            }

            $label = 'Zásilkovna - výdejní místo / Z-Box';
            if ($cost > 0) {
                $label .= ' (' . wc_price($cost) . ')';
            } else {
                $label .= ' - ' . __('Zdarma', 'wc-shoptet-elogist');
            }

            $rate_data = [
                'id' => $this->get_rate_id('zasilkovna'),
                'label' => $label,
                'cost' => $cost,
                'meta_data' => [
                    'carrier_id' => 'ZASILKOVNA',
                    'carrier_service' => 'Osobní odběr',
                    'carrier_name' => 'Zásilkovna',
                    'pickup_point_required' => true,
                    'widget_type' => 'zasilkovna'
                ]
            ];

            $this->add_rate($rate_data);
            $rates_added++;

            error_log(sprintf('[ELOGIST DEBUG] Added Zásilkovna pickup rate: %s (cost: %s)', $label, $cost));
        }

        // Zásilkovna - doručení na adresu
        if ($this->get_option('zasilkovna_home_enabled') === 'yes') {
            $cost = floatval($this->get_option('zasilkovna_home_cost', 89));
            $free_limit = floatval($this->get_option('zasilkovna_home_free_limit', 1200));

            if ($cart_total >= $free_limit && $free_limit > 0) {
                $cost = 0;
            }

            $label = 'Zásilkovna - doručení na adresu';
            if ($cost > 0) {
                $label .= ' (' . wc_price($cost) . ')';
            } else {
                $label .= ' - ' . __('Zdarma', 'wc-shoptet-elogist');
            }

            $rate_data = [
                'id' => $this->get_rate_id('zasilkovna_home'),
                'label' => $label,
                'cost' => $cost,
                'meta_data' => [
                    'carrier_id' => 'ZASILKOVNA',
                    'carrier_service' => 'Nejvýhodnější doručení na adresu',
                    'carrier_name' => 'Zásilkovna HD',
                    'pickup_point_required' => false
                ]
            ];

            $this->add_rate($rate_data);
            $rates_added++;

            error_log(sprintf('[ELOGIST DEBUG] Added Zásilkovna home rate: %s (cost: %s)', $label, $cost));
        }

        error_log(sprintf('[ELOGIST DEBUG] Total rates added: %d', $rates_added));
    }

    /**
     * Pomocná metoda pro generování rate ID
     */
	public function get_rate_id($suffix = '') {
		if (empty($suffix)) {
			return $this->id . ':' . $this->instance_id;
		}
		return $this->id . '_' . $suffix . ':' . $this->instance_id;
	}

    /**
     * Enhanced is_available s debug logováním
     */
    public function is_available($package) {
        $available = $this->is_enabled();

        error_log(sprintf(
            '[ELOGIST DEBUG] is_available check – id=%s, instance=%s, enabled=%s, available=%s',
            $this->id,
            $this->instance_id,
            $this->get_option('enabled'),
            $available ? 'true' : 'false'
        ));

        return $available;
    }

    /**
     * Admin form field helper s debug informacemi
     */
    public function admin_options() {
        error_log(sprintf(
            '[ELOGIST DEBUG] admin_options called – id=%s, instance=%s',
            $this->id,
            $this->instance_id
        ));

        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <p><?php echo esc_html($this->get_method_description()); ?></p>

        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
        <div style="background: #f0f8ff; border: 1px solid #0073aa; padding: 10px; margin: 10px 0;">
            <h4>🔧 Debug informace</h4>
            <p><strong>Method ID:</strong> <?php echo esc_html($this->id); ?></p>
            <p><strong>Instance ID:</strong> <?php echo esc_html($this->instance_id); ?></p>
            <p><strong>Enabled:</strong> <?php echo esc_html($this->get_option('enabled', 'N/A')); ?></p>
            <p><strong>Settings count:</strong> <?php echo count($this->settings); ?></p>
            <details>
                <summary>Všechna nastavení</summary>
                <pre><?php echo esc_html(print_r($this->settings, true)); ?></pre>
            </details>
        </div>
        <?php endif; ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    /**
     * Validace nastavení při ukládání
     */
    public function validate_settings($form_fields, $posted) {
        error_log(sprintf(
            '[ELOGIST DEBUG] validate_settings – id=%s, instance=%s, posted_keys=%s',
            $this->id,
            $this->instance_id,
            implode(', ', array_keys($posted))
        ));

        // Validace číselných hodnot
        $numeric_fields = [
            'ppl_cost', 'ppl_free_limit',
            'ppl_parcelshop_cost', 'ppl_parcelshop_free_limit',
            'zasilkovna_cost', 'zasilkovna_free_limit',
            'zasilkovna_home_cost', 'zasilkovna_home_free_limit'
        ];

        foreach ($numeric_fields as $field) {
            if (isset($posted[$field]) && !is_numeric($posted[$field])) {
                $posted[$field] = 0;
                error_log(sprintf('[ELOGIST DEBUG] Invalid numeric value for %s, setting to 0', $field));
            }
        }

        return $posted;
    }

    /**
     * Test metoda pro ověření funkčnosti
     */
    public function test_shipping_method() {
        $test_package = [
            'contents' => [],
            'contents_cost' => 100,
            'applied_coupons' => [],
            'user' => ['ID' => 0],
            'destination' => [
                'country' => 'CZ',
                'state' => '',
                'postcode' => '110 00',
                'city' => 'Praha',
                'address' => 'Test',
                'address_2' => ''
            ]
        ];

        ob_start();
        $this->calculate_shipping($test_package);
        $output = ob_get_clean();

        $rates = $this->rates;

        error_log(sprintf(
            '[ELOGIST DEBUG] Test shipping method result – rates_count=%d, rates=%s',
            count($rates),
            print_r($rates, true)
        ));

        return [
            'rates_count' => count($rates),
            'rates' => $rates,
            'output' => $output
        ];
    }

    /**
     * Debug metoda pro kontrolu nastavení
     */
    public function debug_settings() {
        error_log(sprintf(
            '[ELOGIST DEBUG] Shipping method settings dump – id=%s, instance=%s, enabled=%s, settings=%s',
            $this->id,
            $this->instance_id,
            $this->get_option('enabled'),
            print_r($this->settings, true)
        ));
    }
}

// CSS a AJAX handlers - PŘESUNOUT VEN ZE TŘÍDY
add_action('admin_head', function() {
    if (isset($_GET['section']) && strpos($_GET['section'], 'wse_elogist_shipping') !== false) {
        ?>
        <style>
        .wse-elogist-debug {
            background: #f0f8ff;
            border: 1px solid #0073aa;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        .wse-elogist-debug h4 {
            margin-top: 0;
            color: #0073aa;
        }
        .wse-elogist-debug details {
            margin-top: 10px;
        }
        .wse-elogist-debug pre {
            background: #fff;
            padding: 10px;
            border-radius: 3px;
            font-size: 11px;
            max-height: 300px;
            overflow: auto;
        }
        </style>
        <?php
    }
});

// Debug AJAX handler pro testování shipping method
add_action('wp_ajax_wse_test_shipping_method', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    $instance_id = intval($_POST['instance_id'] ?? 0);
    $shipping_method = new WSE_ELogist_Shipping($instance_id);

    $result = $shipping_method->test_shipping_method();

    wp_send_json_success([
        'message' => sprintf('Test dokončen. Nalezeno %d dopravních sazeb.', $result['rates_count']),
        'details' => $result
    ]);
});

// Přidat test tlačítko do admin formuláře
add_action('woocommerce_shipping_zone_method_status_toggle_after', function($method, $zone) {
    if ($method->method_id === 'wse_elogist_shipping' && defined('WP_DEBUG') && WP_DEBUG) {
        ?>
        <button type="button" class="button wse-test-shipping-method" 
                data-instance-id="<?php echo esc_attr($method->instance_id); ?>">
            🧪 Test metodu
        </button>

        <script>
        jQuery(document).on('click', '.wse-test-shipping-method', function(e) {
            e.preventDefault();

            var button = jQuery(this);
            var instanceId = button.data('instance-id');
            var originalText = button.text();

            button.prop('disabled', true).text('Testování...');

            jQuery.post(ajaxurl, {
                action: 'wse_test_shipping_method',
                instance_id: instanceId
            }, function(response) {
                if (response.success) {
                    alert('✅ ' + response.data.message);
                    console.log('WSE Shipping Test Results:', response.data.details);
                } else {
                    alert('❌ Chyba: ' + response.data);
                }
            }).always(function() {
                button.prop('disabled', false).text(originalText);
            });
        });
        </script>
        <?php
    }
}, 10, 2);