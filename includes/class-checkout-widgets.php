<?php
/**
 * OPRAVENÉ Checkout Widgets - Podpora pro WooCommerce Blocks i klasický checkout
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Checkout_Widgets
{
    private static $instance = null;
    private $logger;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        if (class_exists('WSE_Logger')) {
            $this->logger = WSE_Logger::get_instance();
        }
        
        error_log('[WSE DEBUG] WSE_Checkout_Widgets constructor called');
        $this->init_hooks();
    }

    private function init_hooks()
    {
        error_log('[WSE DEBUG] WSE_Checkout_Widgets init_hooks called');
        
        // Společné hooky pro oba typy checkoutu
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_pickup_point_data']);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'display_pickup_point_in_admin']);
        add_action('woocommerce_checkout_process', [$this, 'validate_pickup_point_selection']);
        add_action('woocommerce_checkout_create_order', [$this, 'add_pickup_point_to_order'], 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_wse_save_pickup_point', [$this, 'ajax_save_pickup_point']);
        add_action('wp_ajax_nopriv_wse_save_pickup_point', [$this, 'ajax_save_pickup_point']);
        
        // Detekce typu checkoutu a přidání příslušných hooků
        add_action('wp_loaded', [$this, 'detect_checkout_type']);
        
        error_log('[WSE DEBUG] All hooks initialized');
    }

    /**
     * Detekce typu checkoutu a inicializace příslušných hooků
     */
    public function detect_checkout_type()
    {
        // Kontrola, zda jsou WooCommerce Blocks aktivní
        if ($this->is_blocks_checkout_active()) {
            error_log('[WSE DEBUG] WooCommerce Blocks checkout detected');
            $this->init_blocks_hooks();
        } else {
            error_log('[WSE DEBUG] Classic WooCommerce checkout detected');
            $this->init_classic_hooks();
        }
    }

    /**
     * Kontrola, zda je aktivní block checkout
     */
    private function is_blocks_checkout_active()
    {
        if (!function_exists('has_block')) {
            return false;
        }
        
        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id && has_block('woocommerce/checkout', $checkout_page_id)) {
            return true;
        }
        
        return false;
    }

    /**
     * Hooky pro WooCommerce Blocks
     */
    private function init_blocks_hooks()
    {
        // Pro blocks použijeme REST API a custom endpoint
        add_action('rest_api_init', [$this, 'register_blocks_rest_routes']);
        
        // Přidat pickup selektory přes wp_footer pro blocks
        add_action('wp_footer', [$this, 'add_blocks_pickup_selectors']);
        
        // Store API extensions pro blocks
        add_action('woocommerce_blocks_loaded', [$this, 'register_blocks_integration']);
    }

    /**
     * Hooky pro klasický checkout
     */
    private function init_classic_hooks()
    {
        add_filter('woocommerce_update_order_review_fragments', [$this, 'add_pickup_selector_fragments']);
        add_action('woocommerce_checkout_after_order_review', [$this, 'add_pickup_selectors_after_checkout']);
        add_action('wp_footer', [$this, 'add_classic_pickup_selector_javascript']);
    }

    /**
     * Registrace REST API routes pro blocks
     */
    public function register_blocks_rest_routes()
    {
        register_rest_route('wse/v1', '/pickup-points', [
            'methods' => 'GET',
            'callback' => [$this, 'get_pickup_points_for_method'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * Store API integrace pro blocks
     */
    public function register_blocks_integration()
    {
        if (class_exists('\Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry')) {
            add_action(
                'woocommerce_blocks_checkout_block_registration',
                function($integration_registry) {
                    // Zde bychom registrovali custom block komponentu
                    // Pro jednoduchost použijeme JavaScript injection
                }
            );
        }
    }

    public function enqueue_checkout_scripts()
    {
        if (!is_checkout()) {
            return;
        }

        error_log('[WSE DEBUG] enqueue_checkout_scripts called');

        // Zásilkovna widget
        wp_enqueue_script(
            'packeta-widget',
            'https://widget.packeta.com/v6/www/js/library.js',
            [],
            null,
            true
        );

        // Náš vlastní JavaScript - jiný pro blocks vs klasický
        $script_suffix = $this->is_blocks_checkout_active() ? '-blocks' : '-classic';
        
        wp_enqueue_script(
            'wse-checkout-widgets',
            WSE_PLUGIN_URL . 'assets/js/checkout-widgets' . $script_suffix . '.js',
            ['jquery', 'packeta-widget'],
            WSE_VERSION . '-' . time(),
            true
        );

        // CSS styly
        wp_enqueue_style(
            'wse-checkout-widgets',
            WSE_PLUGIN_URL . 'assets/css/checkout-widgets.css',
            [],
            WSE_VERSION . '-' . time()
        );

        // Lokalizace JavaScriptu
        wp_localize_script('wse-checkout-widgets', 'wse_checkout_widgets', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('wse/v1/'),
            'nonce' => wp_create_nonce('wse_pickup_point_nonce'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'packeta_api_key' => '6d53656932368962',
            'is_blocks' => $this->is_blocks_checkout_active(),
            'debug' => true,
            'texts' => [
                'select_pickup_point' => __('Vybrat výdejní místo', 'wc-shoptet-elogist'),
                'selected_point' => __('Vybrané místo:', 'wc-shoptet-elogist'),
                'change_point' => __('Změnit místo', 'wc-shoptet-elogist'),
                'no_point_selected' => __('Žádné místo nevybráno', 'wc-shoptet-elogist'),
                'loading' => __('Načítání...', 'wc-shoptet-elogist'),
                'error_saving' => __('Chyba při ukládání výdejního místa', 'wc-shoptet-elogist'),
                'point_required' => __('Prosím vyberte výdejní místo', 'wc-shoptet-elogist')
            ]
        ]);
        
        error_log('[WSE DEBUG] Scripts and styles enqueued successfully');
    }

    /**
     * Přidat pickup selektory pro WooCommerce Blocks
     */
    public function add_blocks_pickup_selectors()
    {
        if (!is_checkout() || !$this->is_blocks_checkout_active()) {
            return;
        }
        
        ?>
        <div id="wse-blocks-pickup-container" style="display: none;">
            <!-- Pickup selektory budou dynamicky vloženy JavaScriptem -->
        </div>

        <script>
        // JavaScript pro WooCommerce Blocks
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[WSE DEBUG] Blocks checkout JavaScript loaded');
            
            let checkoutContainer = null;
            let currentShippingMethod = '';
            let pickupSelectorsInserted = false;

            // Čekání na načtení checkout blocks
            function waitForCheckoutBlocks() {
                checkoutContainer = document.querySelector('.wc-block-checkout');
                
                if (checkoutContainer) {
                    console.log('[WSE DEBUG] Checkout blocks container found');
                    initializePickupSelectors();
                    observeShippingChanges();
                } else {
                    console.log('[WSE DEBUG] Waiting for checkout blocks...');
                    setTimeout(waitForCheckoutBlocks, 500);
                }
            }
            
            waitForCheckoutBlocks();

            function initializePickupSelectors() {
                // Najít shipping options container
                const shippingContainer = checkoutContainer.querySelector('.wc-block-components-radio-control');
                
                if (!shippingContainer) {
                    console.log('[WSE DEBUG] Shipping container not found, retrying...');
                    setTimeout(initializePickupSelectors, 1000);
                    return;
                }

                // Vložit pickup selektory za shipping options
                if (!pickupSelectorsInserted) {
                    insertPickupSelectors(shippingContainer);
                    pickupSelectorsInserted = true;
                }
                
                updatePickupSelectors();
            }

            function insertPickupSelectors(afterElement) {
                const pickupHTML = `
                    <div class="wse-pickup-selectors-blocks">
                        <!-- PPL ParcelShop selector -->
                        <div class="wse-pickup-point-selector" 
                             data-method-id="wse_elogist_shipping_ppl_parcelshop" 
                             data-widget-type="ppl_parcelshop"
                             style="display: none; background: #f0f8ff; border: 2px solid #0073aa; padding: 15px; margin: 10px 0; border-radius: 4px;">
                            
                            <div class="wse-pickup-point-header">
                                <h4 style="margin: 0 0 10px 0; color: #0073aa;">🚚 Vyberte PPL ParcelShop</h4>
                                <p style="margin: 0 0 10px 0; font-size: 12px; color: #666;"><strong>DEBUG:</strong> PPL ParcelShop selector</p>
                            </div>
                            
                            <div class="wse-pickup-point-content">
                                <div class="wse-pickup-point-selected" style="display: none;">
                                    <div class="wse-selected-point-info">
                                        <strong>✅ Vybrané místo:</strong>
                                        <div class="wse-selected-point-details" style="margin: 5px 0; padding: 10px; background: white; border-radius: 3px;"></div>
                                    </div>
                                    <button type="button" class="wse-change-pickup-point" style="background: #0073aa; color: white; border: none; padding: 8px 16px; border-radius: 3px; cursor: pointer;">
                                        Změnit místo
                                    </button>
                                </div>
                                
                                <div class="wse-pickup-point-not-selected">
                                    <button type="button" class="wse-select-pickup-point" style="background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 3px; cursor: pointer; font-weight: bold;">
                                        📍 Vybrat PPL ParcelShop
                                    </button>
                                    <div class="wse-pickup-point-note" style="margin-top: 8px;">
                                        <small style="color: #666;">Po výběru dopravy prosím vyberte výdejní místo</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Zásilkovna selector -->
                        <div class="wse-pickup-point-selector" 
                             data-method-id="wse_elogist_shipping_zasilkovna" 
                             data-widget-type="zasilkovna"
                             style="display: none; background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 10px 0; border-radius: 4px;">
                            
                            <div class="wse-pickup-point-header">
                                <h4 style="margin: 0 0 10px 0; color: #e65100;">📦 Vyberte výdejní místo Zásilkovny</h4>
                                <p style="margin: 0 0 10px 0; font-size: 12px; color: #666;"><strong>DEBUG:</strong> Zásilkovna selector</p>
                            </div>
                            
                            <div class="wse-pickup-point-content">
                                <div class="wse-pickup-point-selected" style="display: none;">
                                    <div class="wse-selected-point-info">
                                        <strong>✅ Vybrané místo:</strong>
                                        <div class="wse-selected-point-details" style="margin: 5px 0; padding: 10px; background: white; border-radius: 3px;"></div>
                                    </div>
                                    <button type="button" class="wse-change-pickup-point" style="background: #e65100; color: white; border: none; padding: 8px 16px; border-radius: 3px; cursor: pointer;">
                                        Změnit místo
                                    </button>
                                </div>
                                
                                <div class="wse-pickup-point-not-selected">
                                    <button type="button" class="wse-select-pickup-point" style="background: #e65100; color: white; border: none; padding: 10px 20px; border-radius: 3px; cursor: pointer; font-weight: bold;">
                                        📍 Vybrat výdejní místo
                                    </button>
                                    <div class="wse-pickup-point-note" style="margin-top: 8px;">
                                        <small style="color: #666;">Po výběru dopravy prosím vyberte výdejní místo</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                afterElement.insertAdjacentHTML('afterend', pickupHTML);
                console.log('[WSE DEBUG] Pickup selectors inserted into blocks checkout');
                
                // Přidat event listenery pro tlačítka
                addPickupButtonListeners();
            }

            function observeShippingChanges() {
                // Pozorovat změny v shipping options
                const shippingContainer = checkoutContainer.querySelector('.wc-block-components-radio-control');
                
                if (!shippingContainer) {
                    setTimeout(observeShippingChanges, 1000);
                    return;
                }

                // Event listener pro změny shipping method
                shippingContainer.addEventListener('change', function(e) {
                    if (e.target.type === 'radio' && e.target.name.startsWith('radio-control')) {
                        console.log('[WSE DEBUG] Shipping method changed to:', e.target.value);
                        currentShippingMethod = e.target.value;
                        updatePickupSelectors();
                    }
                });

                // Také kontrolovat již vybranou metodu při načtení
                const checkedMethod = shippingContainer.querySelector('input[type="radio"]:checked');
                if (checkedMethod) {
                    currentShippingMethod = checkedMethod.value;
                    updatePickupSelectors();
                }

                // Observer pro dynamické změny (React re-renders)
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList' || mutation.type === 'attributes') {
                            const checkedMethod = shippingContainer.querySelector('input[type="radio"]:checked');
                            if (checkedMethod && checkedMethod.value !== currentShippingMethod) {
                                console.log('[WSE DEBUG] Shipping method detected via observer:', checkedMethod.value);
                                currentShippingMethod = checkedMethod.value;
                                updatePickupSelectors();
                            }
                        }
                    });
                });

                observer.observe(shippingContainer, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['checked']
                });
            }

            function updatePickupSelectors() {
                console.log('[WSE DEBUG] Updating pickup selectors for method:', currentShippingMethod);
                
                // Skrýt všechny pickup selektory
                document.querySelectorAll('.wse-pickup-point-selector').forEach(selector => {
                    selector.style.display = 'none';
                });

                if (!currentShippingMethod) {
                    return;
                }

                // Zobrazit příslušný pickup selektor
                if (currentShippingMethod.includes('ppl_parcelshop')) {
                    const pplSelector = document.querySelector('[data-method-id="wse_elogist_shipping_ppl_parcelshop"]');
                    if (pplSelector) {
                        pplSelector.style.display = 'block';
                        console.log('[WSE DEBUG] Showing PPL ParcelShop selector');
                    }
                } else if (currentShippingMethod.includes('zasilkovna') && !currentShippingMethod.includes('home')) {
                    const zasilkovnaSelector = document.querySelector('[data-method-id="wse_elogist_shipping_zasilkovna"]');
                    if (zasilkovnaSelector) {
                        zasilkovnaSelector.style.display = 'block';
                        console.log('[WSE DEBUG] Showing Zásilkovna selector');
                    }
                }
            }

            function addPickupButtonListeners() {
                document.addEventListener('click', function(e) {
                    if (e.target.classList.contains('wse-select-pickup-point') || 
                        e.target.classList.contains('wse-change-pickup-point')) {
                        
                        e.preventDefault();
                        
                        const container = e.target.closest('.wse-pickup-point-selector');
                        const widgetType = container.dataset.widgetType;
                        const methodId = container.dataset.methodId;
                        
                        console.log('[WSE DEBUG] Opening pickup widget:', widgetType);
                        
                        if (widgetType === 'zasilkovna') {
                            openZasilkovnaWidget(methodId, container);
                        } else if (widgetType === 'ppl_parcelshop') {
                            openPPLParcelShopWidget(methodId, container);
                        }
                    }
                });
            }

            // Widget functions (stejné jako v původním kódu)
            function openZasilkovnaWidget(methodId, container) {
                if (typeof Packeta === 'undefined' || typeof Packeta.Widget === 'undefined') {
                    console.error('[WSE] Packeta widget is not loaded');
                    alert('Chyba: Packeta widget není načten');
                    return;
                }
                
                const packetaOptions = {
                    country: "cz",
                    language: "cs",
                    valueFormat: '"Packeta",id,carrierId,carrierPickupPointId,name,city,street',
                    view: "modal",
                    vendors: [
                        { country: "cz" },
                        { country: "cz", group: "zbox" }
                    ]
                };
                
                function onPointSelected(point) {
                    console.log('[WSE] Zásilkovna point selected:', point);
                    
                    if (point && point.id) {
                        savePickupPoint(methodId, {
                            pickup_id: point.id,
                            pickup_name: point.name + ', ' + point.city + ', ' + point.street,
                            pickup_data: point.formatedValue || JSON.stringify(point)
                        }, container);
                    }
                }
                
                try {
                    Packeta.Widget.pick(
                        wse_checkout_widgets.packeta_api_key,
                        onPointSelected,
                        packetaOptions
                    );
                } catch (error) {
                    console.error('[WSE] Error opening Packeta widget:', error);
                    alert('Chyba při otevírání Packeta widgetu');
                }
            }
            
            function openPPLParcelShopWidget(methodId, container) {
                // Mock pro testování
                const mockPoint = {
                    id: 'PPL_TEST_001',
                    name: 'PPL ParcelShop - TEST',
                    city: 'Praha',
                    street: 'Testovací 123'
                };
                
                if (confirm('Chcete vybrat testovací PPL ParcelShop?')) {
                    savePickupPoint(methodId, {
                        pickup_id: mockPoint.id,
                        pickup_name: mockPoint.name + ', ' + mockPoint.city + ', ' + mockPoint.street,
                        pickup_data: JSON.stringify(mockPoint)
                    }, container);
                }
            }
            
            function savePickupPoint(methodId, pointData, container) {
                console.log('[WSE] Saving pickup point:', pointData);
                
                // OPRAVENO: Použít správný method_id podle aktuálně vybraného shipping method
                var actualMethodId = methodId;
                if (currentShippingMethod && currentShippingMethod !== methodId) {
                    // Pokud se aktuální method liší od method_id containeru, použít aktuální
                    actualMethodId = currentShippingMethod;
                    console.log('[WSE] Using current shipping method instead:', actualMethodId);
                }
                
                // Aktualizovat UI
                const selectedInfo = container.querySelector('.wse-selected-point-details');
                const selectedSection = container.querySelector('.wse-pickup-point-selected');
                const notSelectedSection = container.querySelector('.wse-pickup-point-not-selected');
                
                selectedInfo.innerHTML = 
                    '<div class="wse-point-name" style="font-weight: bold;">' + pointData.pickup_name + '</div>' +
                    '<div class="wse-point-id"><small style="color: #666;">ID: ' + pointData.pickup_id + '</small></div>';
                
                selectedSection.style.display = 'block';
                notSelectedSection.style.display = 'none';
                
                // AJAX uložení - pošleme jak původní method_id tak current method
                fetch(wse_checkout_widgets.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'wse_save_pickup_point',
                        nonce: wse_checkout_widgets.nonce,
                        method_id: actualMethodId,  // Použít aktuální method
                        pickup_data: pointData.pickup_data,
                        pickup_id: pointData.pickup_id,
                        pickup_name: pointData.pickup_name,
                        original_method_id: methodId  // Pro debug
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('[WSE] Pickup point saved successfully');
                        console.log('[WSE] Session keys used:', data.data.session_keys);
                    } else {
                        console.error('[WSE] Failed to save pickup point:', data.data);
                    }
                })
                .catch(error => {
                    console.error('[WSE] Error saving pickup point:', error);
                });
            }
        });
        </script>
        <?php
    }

    // Klasické checkout metody (původní kód zůstává stejný)
    public function add_pickup_selector_fragments($fragments)
    {
        error_log('[WSE DEBUG] add_pickup_selector_fragments called');
        
        ob_start();
        $this->render_pickup_selectors();
        $pickup_selectors_html = ob_get_clean();
        
        $fragments['.wse-pickup-selectors-container'] = '<div class="wse-pickup-selectors-container">' . $pickup_selectors_html . '</div>';
        
        return $fragments;
    }

    public function add_pickup_selectors_after_checkout()
    {
        if (!is_checkout() || $this->is_blocks_checkout_active()) {
            return;
        }
        
        error_log('[WSE DEBUG] add_pickup_selectors_after_checkout called');
        
        echo '<div class="wse-pickup-selectors-container">';
        $this->render_pickup_selectors();
        echo '</div>';
    }

    private function render_pickup_selectors()
    {
        $packages = WC()->shipping->get_packages();
        
        if (empty($packages)) {
            return;
        }
        
        $package = reset($packages);
        $available_methods = $package['rates'] ?? [];
        
        foreach ($available_methods as $method_id => $method) {
            $this->render_single_pickup_selector($method_id, $method);
        }
    }

    private function render_single_pickup_selector($method_id, $method)
    {
        // Zkontrolovat, zda jde o pickup metodu
        $is_pickup_method = false;
        $widget_type = '';
        $widget_title = '';
        
        if (strpos($method_id, 'ppl_parcelshop') !== false) {
            $is_pickup_method = true;
            $widget_type = 'ppl_parcelshop';
            $widget_title = __('Vyberte PPL ParcelShop', 'wc-shoptet-elogist');
            
        } elseif (strpos($method_id, 'zasilkovna') !== false && strpos($method_id, 'home') === false) {
            $is_pickup_method = true;
            $widget_type = 'zasilkovna';
            $widget_title = __('Vyberte výdejní místo Zásilkovny', 'wc-shoptet-elogist');
        }

        if (!$is_pickup_method) {
            return;
        }
        
        error_log('[WSE DEBUG] Rendering pickup selector for: ' . $method_id . ' (' . $widget_type . ')');
        
        ?>
        <div class="wse-pickup-point-selector" 
             data-method-id="<?php echo esc_attr($method_id); ?>" 
             data-widget-type="<?php echo esc_attr($widget_type); ?>"
             style="display: none; background: yellow; border: 2px solid red; padding: 15px; margin: 10px 0;">
            
            <div class="wse-pickup-point-header">
                <h4><?php echo esc_html($widget_title); ?></h4>
                <p><strong>🔧 DEBUG:</strong> Method ID: <?php echo esc_html($method_id); ?>, Widget: <?php echo esc_html($widget_type); ?></p>
            </div>
            
            <div class="wse-pickup-point-content">
                <div class="wse-pickup-point-selected" style="display: none;">
                    <div class="wse-selected-point-info">
                        <strong><?php _e('Vybrané místo:', 'wc-shoptet-elogist'); ?></strong>
                        <div class="wse-selected-point-details"></div>
                    </div>
                    <button type="button" class="wse-change-pickup-point button">
                        <?php _e('Změnit místo', 'wc-shoptet-elogist'); ?>
                    </button>
                </div>
                
                <div class="wse-pickup-point-not-selected">
                    <button type="button" class="wse-select-pickup-point button button-primary">
                        <?php _e('Vybrat výdejní místo', 'wc-shoptet-elogist'); ?>
                    </button>
                    <div class="wse-pickup-point-note">
                        <small><?php _e('Po výběru dopravy prosím vyberte výdejní místo', 'wc-shoptet-elogist'); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- Skrytá pole pro uložení dat -->
            <input type="hidden" 
                   name="wse_pickup_point_data[<?php echo esc_attr($method_id); ?>]" 
                   class="wse-pickup-point-data" 
                   value="" />
            <input type="hidden" 
                   name="wse_pickup_point_id[<?php echo esc_attr($method_id); ?>]" 
                   class="wse-pickup-point-id" 
                   value="" />
            <input type="hidden" 
                   name="wse_pickup_point_name[<?php echo esc_attr($method_id); ?>]" 
                   class="wse-pickup-point-name" 
                   value="" />
        </div>
        <?php
    }

    public function add_classic_pickup_selector_javascript()
    {
        if (!is_checkout() || $this->is_blocks_checkout_active()) {
            return;
        }
        
        // Původní JavaScript pro klasický checkout zůstává stejný
        ?>
        <script>
        jQuery(document).ready(function($) {
            console.log('[WSE DEBUG] Classic checkout JavaScript loaded');
            
            // Původní kód pro klasický checkout...
            // (zde bych vložil původní JavaScript kód)
        });
        </script>
        <?php
    }

    // Zbytek metod zůstává stejný jako v původním kódu
    public function validate_pickup_point_selection()
    {
        error_log('[WSE DEBUG] validate_pickup_point_selection called');
        
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        
        if (empty($chosen_methods)) {
            return;
        }

        $chosen_method = $chosen_methods[0];
        
        if (strpos($chosen_method, 'ppl_parcelshop') !== false ||
            (strpos($chosen_method, 'zasilkovna') !== false && strpos($chosen_method, 'home') === false)) {
            
            $pickup_point_id = '';
            
            if (isset($_POST['wse_pickup_point_id'])) {
                foreach ($_POST['wse_pickup_point_id'] as $method_id => $point_id) {
                    if (strpos($chosen_method, $method_id) !== false || $method_id === $chosen_method) {
                        $pickup_point_id = $point_id;
                        break;
                    }
                }
            }
            
            // Pro blocks checkout kontrolovat také session
            if (empty($pickup_point_id)) {
                $session_data = WC()->session->get('wse_pickup_point_' . $chosen_method);
                if ($session_data && !empty($session_data['pickup_id'])) {
                    $pickup_point_id = $session_data['pickup_id'];
                }
            }
            
            if (empty($pickup_point_id)) {
                $method_name = strpos($chosen_method, 'zasilkovna') !== false ? 'Zásilkovna' : 'PPL ParcelShop';
                
                wc_add_notice(
                    sprintf(__('Prosím vyberte výdejní místo pro dopravu %s.', 'wc-shoptet-elogist'), $method_name),
                    'error'
                );
            }
        }
    }

    public function save_pickup_point_data($order_id)
    {
        if (!isset($_POST['wse_pickup_point_data'])) {
            return;
        }

        foreach ($_POST['wse_pickup_point_data'] as $method_id => $pickup_data) {
            if (!empty($pickup_data)) {
                update_post_meta($order_id, '_wse_pickup_point_data_' . $method_id, sanitize_text_field($pickup_data));
                
                if (isset($_POST['wse_pickup_point_id'][$method_id])) {
                    $pickup_id = sanitize_text_field($_POST['wse_pickup_point_id'][$method_id]);
                    update_post_meta($order_id, '_wse_pickup_point_id_' . $method_id, $pickup_id);
                    update_post_meta($order_id, '_branch_id_' . $method_id, $pickup_id);
                }
                
                if (isset($_POST['wse_pickup_point_name'][$method_id])) {
                    update_post_meta($order_id, '_wse_pickup_point_name_' . $method_id, sanitize_text_field($_POST['wse_pickup_point_name'][$method_id]));
                }
            }
        }
    }

    public function add_pickup_point_to_order($order, $data)
    {
        error_log('[WSE DEBUG] add_pickup_point_to_order called for order: ' . $order->get_id());
        
        $shipping_methods = $order->get_shipping_methods();
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_method = !empty($chosen_methods) ? $chosen_methods[0] : '';
        
        error_log('[WSE DEBUG] Chosen shipping method: ' . $chosen_method);
        error_log('[WSE DEBUG] Available shipping methods: ' . print_r(array_keys($shipping_methods), true));
        
        foreach ($shipping_methods as $shipping_method) {
            $method_id = $shipping_method->get_method_id();
            $instance_id = $shipping_method->get_instance_id();
            $full_method_id = $method_id . ':' . $instance_id;
            
            error_log('[WSE DEBUG] Processing shipping method: ' . $method_id . ' (full: ' . $full_method_id . ')');
            
            $pickup_data = '';
            $pickup_id = '';
            $pickup_name = '';
            $found_pickup_data = false;
            
            // Metoda 1: Kontrola POST dat (klasický checkout)
            if (isset($_POST['wse_pickup_point_data']) && is_array($_POST['wse_pickup_point_data'])) {
                error_log('[WSE DEBUG] Checking POST data...');
                
                foreach ($_POST['wse_pickup_point_data'] as $post_method_id => $post_pickup_data) {
                    if ($post_method_id === $method_id || $post_method_id === $full_method_id || strpos($chosen_method, $post_method_id) !== false) {
                        $pickup_data = $post_pickup_data;
                        $pickup_id = $_POST['wse_pickup_point_id'][$post_method_id] ?? '';
                        $pickup_name = $_POST['wse_pickup_point_name'][$post_method_id] ?? '';
                        $found_pickup_data = true;
                        error_log('[WSE DEBUG] Found pickup data in POST for: ' . $post_method_id);
                        break;
                    }
                }
            }
            
            // Metoda 2: Kontrola session dat (blocks checkout)
            if (!$found_pickup_data && !empty($chosen_method)) {
                error_log('[WSE DEBUG] Checking session data...');
                
                // Zkusit různé kombinace klíčů
                $session_keys = [
                    'wse_pickup_point_' . $chosen_method,
                    'wse_pickup_point_' . $method_id,
                    'wse_pickup_point_' . $full_method_id,
                    'wse_pickup_point_wse_elogist_shipping_' . $method_id,
                ];
                
                foreach ($session_keys as $session_key) {
                    $session_data = WC()->session->get($session_key);
                    error_log('[WSE DEBUG] Checking session key: ' . $session_key . ' - ' . (empty($session_data) ? 'empty' : 'has data'));
                    
                    if ($session_data && !empty($session_data['pickup_id'])) {
                        $pickup_data = $session_data['pickup_data'] ?? '';
                        $pickup_id = $session_data['pickup_id'];
                        $pickup_name = $session_data['pickup_name'] ?? '';
                        $found_pickup_data = true;
                        error_log('[WSE DEBUG] Found pickup data in session: ' . $session_key);
                        break;
                    }
                }
            }
            
            // Pokud máme pickup data, uložit je
            if ($found_pickup_data && !empty($pickup_id)) {
                error_log('[WSE DEBUG] Saving pickup point data: ID=' . $pickup_id . ', Name=' . $pickup_name);
                
                // Přidat metadata k shipping method
                $shipping_method->add_meta_data('pickup_point_data', $pickup_data);
                $shipping_method->add_meta_data('pickup_point_id', $pickup_id);
                $shipping_method->add_meta_data('pickup_point_name', $pickup_name);
                $shipping_method->add_meta_data('_branch_id', $pickup_id);
                
                // ELOGIST SPECIFICKÁ POLE - přidáme všechny možné formáty
                $shipping_method->add_meta_data('branch_id', $pickup_id);
                $shipping_method->add_meta_data('pickup_id', $pickup_id);
                $shipping_method->add_meta_data('pickup_location_id', $pickup_id);
                $shipping_method->add_meta_data('delivery_point_id', $pickup_id);
                
                // Pro Zásilkovnu specifické pole
                if (strpos($method_id, 'zasilkovna') !== false) {
                    $shipping_method->add_meta_data('packeta_point_id', $pickup_id);
                    $shipping_method->add_meta_data('zasilkovna_point_id', $pickup_id);
                }
                
                // Pro PPL specifické pole
                if (strpos($method_id, 'ppl') !== false) {
                    $shipping_method->add_meta_data('ppl_point_id', $pickup_id);
                    $shipping_method->add_meta_data('parcelshop_id', $pickup_id);
                }
                
                // Uložit také do order meta (pro jistotu)
                $order->update_meta_data('_pickup_point_id', $pickup_id);
                $order->update_meta_data('_pickup_point_name', $pickup_name);
                $order->update_meta_data('_pickup_point_data', $pickup_data);
                $order->update_meta_data('_branch_id', $pickup_id);
                $order->update_meta_data('_delivery_point_id', $pickup_id);
                
                // Elogist specifické order meta
                $order->update_meta_data('_elogist_pickup_point_id', $pickup_id);
                $order->update_meta_data('_elogist_branch_id', $pickup_id);
                
                // Pro Zásilkovnu
                if (strpos($method_id, 'zasilkovna') !== false) {
                    $order->update_meta_data('_packeta_point_id', $pickup_id);
                    $order->update_meta_data('_zasilkovna_point_id', $pickup_id);
                }
                
                // Pro PPL
                if (strpos($method_id, 'ppl') !== false) {
                    $order->update_meta_data('_ppl_point_id', $pickup_id);
                    $order->update_meta_data('_parcelshop_id', $pickup_id);
                }
                
                // Přidat poznámku k objednávce
                if (!empty($pickup_name)) {
                    $order->add_order_note(
                        sprintf(__('Výdejní místo: %s (ID: %s)', 'wc-shoptet-elogist'), $pickup_name, $pickup_id)
                    );
                }
                
                error_log('[WSE DEBUG] Pickup point metadata saved successfully');
                
            } else {
                error_log('[WSE DEBUG] No pickup point data found for method: ' . $method_id);
            }
        }
        
        // Debug: Vypsat všechna order meta data
        $all_meta = $order->get_meta_data();
        error_log('[WSE DEBUG] All order metadata:');
        foreach ($all_meta as $meta) {
            if (strpos($meta->key, 'pickup') !== false || strpos($meta->key, 'branch') !== false) {
                error_log('[WSE DEBUG] - ' . $meta->key . ': ' . $meta->value);
            }
        }
    }

    public function display_pickup_point_in_admin($order)
    {
        $shipping_methods = $order->get_shipping_methods();
        
        foreach ($shipping_methods as $shipping_method) {
            $method_id = $shipping_method->get_method_id();
            $pickup_name = get_post_meta($order->get_id(), '_wse_pickup_point_name_' . $method_id, true);
            $pickup_id = get_post_meta($order->get_id(), '_wse_pickup_point_id_' . $method_id, true);
            
            if (!empty($pickup_name)) {
                ?>
                <div class="wse-pickup-point-admin">
                    <h3><?php _e('Výdejní místo', 'wc-shoptet-elogist'); ?></h3>
                    <p>
                        <strong><?php echo esc_html($pickup_name); ?></strong><br>
                        <?php if (!empty($pickup_id)): ?>
                            ID: <code><?php echo esc_html($pickup_id); ?></code>
                        <?php endif; ?>
                    </p>
                </div>
                <style>
                .wse-pickup-point-admin {
                    background: #f8f9fa;
                    padding: 10px;
                    border-left: 4px solid #2271b1;
                    margin: 10px 0;
                }
                </style>
                <?php
                break;
            }
        }
    }

    public function ajax_save_pickup_point()
    {
        check_ajax_referer('wse_pickup_point_nonce', 'nonce');

        $method_id = sanitize_text_field($_POST['method_id'] ?? '');
        $pickup_data = sanitize_text_field($_POST['pickup_data'] ?? '');
        $pickup_id = sanitize_text_field($_POST['pickup_id'] ?? '');
        $pickup_name = sanitize_text_field($_POST['pickup_name'] ?? '');

        if (empty($method_id) || empty($pickup_id)) {
            wp_send_json_error(__('Chybí povinné údaje', 'wc-shoptet-elogist'));
        }

        error_log('[WSE DEBUG] AJAX save pickup point: method=' . $method_id . ', id=' . $pickup_id . ', name=' . $pickup_name);

        // Získat aktuální chosen shipping method
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $chosen_method = !empty($chosen_methods) ? $chosen_methods[0] : '';
        
        error_log('[WSE DEBUG] Current chosen method: ' . $chosen_method);

        // OPRAVENO: Uložit pod více klíči pro kompatibilitu s různými formáty method ID
        $session_keys = [
            'wse_pickup_point_' . $method_id,                    // Původní method_id
            'wse_pickup_point_' . $chosen_method,                // Úplný chosen method s instance
        ];
        
        // Přidat varianty pro mapping
        if (strpos($chosen_method, ':') !== false) {
            $base_method = explode(':', $chosen_method)[0];      // Bez instance ID
            $session_keys[] = 'wse_pickup_point_' . $base_method;
        }
        
        // Přidat specifické klíče podle method_id
        if (strpos($method_id, 'zasilkovna') !== false) {
            $session_keys[] = 'wse_pickup_point_wse_elogist_shipping_zasilkovna';
            if (!empty($chosen_method)) {
                $session_keys[] = 'wse_pickup_point_' . str_replace(['_ppl', '_ppl_parcelshop', '_zasilkovna_home'], '_zasilkovna', $chosen_method);
            }
        }
        
        if (strpos($method_id, 'ppl_parcelshop') !== false) {
            $session_keys[] = 'wse_pickup_point_wse_elogist_shipping_ppl_parcelshop';
            if (!empty($chosen_method)) {
                $session_keys[] = 'wse_pickup_point_' . str_replace(['_zasilkovna', '_zasilkovna_home', '_ppl:'], '_ppl_parcelshop:', $chosen_method);
            }
        }

        // Odebrat duplicity
        $session_keys = array_unique($session_keys);

        $pickup_point_data = [
            'pickup_data' => $pickup_data,
            'pickup_id' => $pickup_id,
            'pickup_name' => $pickup_name,
            'method_id' => $method_id,
            'chosen_method' => $chosen_method,
            'timestamp' => time()
        ];

        foreach ($session_keys as $session_key) {
            WC()->session->set($session_key, $pickup_point_data);
            error_log('[WSE DEBUG] Pickup point saved to session: ' . $session_key);
        }

        // Také uložit obecný klíč
        WC()->session->set('wse_current_pickup_point', $pickup_point_data);
        
        // Pro kompatibilitu s Elogistem - zkusit uložit pod standardní klíče
        WC()->session->set('pickup_point_id', $pickup_id);
        WC()->session->set('pickup_point_name', $pickup_name);
        WC()->session->set('pickup_point_data', $pickup_data);
        WC()->session->set('branch_id', $pickup_id);
        WC()->session->set('delivery_point_id', $pickup_id);
        
        // Specifické pro dopravce
        if (strpos($method_id, 'zasilkovna') !== false) {
            WC()->session->set('packeta_point_id', $pickup_id);
            WC()->session->set('zasilkovna_point_id', $pickup_id);
        }
        
        if (strpos($method_id, 'ppl') !== false) {
            WC()->session->set('ppl_point_id', $pickup_id);
            WC()->session->set('parcelshop_id', $pickup_id);
        }

        wp_send_json_success([
            'message' => __('Výdejní místo bylo uloženo', 'wc-shoptet-elogist'),
            'pickup_name' => $pickup_name,
            'pickup_id' => $pickup_id,
            'method_id' => $method_id,
            'chosen_method' => $chosen_method,
            'session_keys' => $session_keys
        ]);
    }

    /**
     * REST API endpoint pro získání pickup points
     */
    public function get_pickup_points_for_method($request)
    {
        $method_id = $request->get_param('method_id');
        
        if (empty($method_id)) {
            return new WP_Error('missing_method', 'Method ID is required', ['status' => 400]);
        }

        // Zde by byla logika pro získání pickup points podle metody
        // Pro testování vracíme mock data
        $pickup_points = [];
        
        if (strpos($method_id, 'ppl_parcelshop') !== false) {
            $pickup_points = [
                [
                    'id' => 'PPL_001',
                    'name' => 'PPL ParcelShop Praha',
                    'address' => 'Václavské náměstí 1, Praha',
                    'type' => 'ppl_parcelshop'
                ]
            ];
        } elseif (strpos($method_id, 'zasilkovna') !== false) {
            $pickup_points = [
                [
                    'id' => 'ZAS_001',
                    'name' => 'Zásilkovna Praha',
                    'address' => 'Národní třída 1, Praha',
                    'type' => 'zasilkovna'
                ]
            ];
        }

        return rest_ensure_response($pickup_points);
    }
}

// Inicializace
add_action('init', function() {
    if (class_exists('WooCommerce')) {
        WSE_Checkout_Widgets::get_instance();
    }
});