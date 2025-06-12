/**
 * WSE Checkout Widgets JavaScript
 * Zpracovává widgety pro výběr výdejních míst (Zásilkovna, PPL ParcelShop)
 */

(function($) {
    'use strict';

    const WSE_CheckoutWidgets = {
        
        // Konfigurace pro Zásilkovnu
        packetaConfig: {
            apiKey: null, // Bude nastaveno z PHP
            options: {
                country: "cz",
                language: "cs",
                valueFormat: "\"Packeta\",id,carrierId,carrierPickupPointId,name,city,street",
                view: "modal",
                vendors: [
                    { country: "cz" },
                    { country: "cz", group: "zbox" }
                ]
            }
        },

        // Aktuálně vybraná dopravní metoda
        currentShippingMethod: null,

        // Cache pro uložená místa
        savedPickupPoints: {},

        init: function() {
            console.log('[WSE] Initializing checkout widgets');
            
            this.packetaConfig.apiKey = wse_checkout_widgets.packeta_api_key;
            this.bindEvents();
            this.handleInitialShippingMethod();
        },

        bindEvents: function() {
            const self = this;

            // Změna dopravní metody
            $(document).on('change', 'input[name^="shipping_method"]', function() {
                self.handleShippingMethodChange();
            });

            // Tlačítko pro výběr výdejního místa
            $(document).on('click', '.wse-select-pickup-point, .wse-change-pickup-point', function(e) {
                e.preventDefault();
                
                const $container = $(this).closest('.wse-pickup-point-selector');
                const widgetType = $container.data('widget-type');
                const methodId = $container.data('method-id');
                
                self.openPickupPointWidget(widgetType, methodId, $container);
            });

            // Update checkout při AJAX refresh
            $(document.body).on('updated_checkout', function() {
                self.handleShippingMethodChange();
            });

            // Validace před odesláním objednávky
            $(document).on('checkout_place_order', function() {
                return self.validatePickupPointSelection();
            });
        },

        handleInitialShippingMethod: function() {
            // Zkontrolovat, jestli je už vybraná nějaká dopravní metoda při načtení stránky
            this.handleShippingMethodChange();
        },

        handleShippingMethodChange: function() {
            const selectedMethod = $('input[name^="shipping_method"]:checked').val();
            
            if (!selectedMethod) {
                this.hideAllPickupPointSelectors();
                return;
            }

            // Extrahovat method ID (bez instance ID)
            const methodId = selectedMethod.split(':')[0];
            this.currentShippingMethod = methodId;

            console.log('[WSE] Shipping method changed to:', methodId);

            // Skrýt všechny selektory
            this.hideAllPickupPointSelectors();

            // Zobrazit příslušný selektor
            const $selector = $('.wse-pickup-point-selector[data-method-id="' + methodId + '"]');
            
            if ($selector.length > 0) {
                $selector.show();
                this.loadSavedPickupPoint(methodId, $selector);
            }
        },

        hideAllPickupPointSelectors: function() {
            $('.wse-pickup-point-selector').hide();
        },

        openPickupPointWidget: function(widgetType, methodId, $container) {
            console.log('[WSE] Opening widget:', widgetType, 'for method:', methodId);

            if (widgetType === 'zasilkovna') {
                this.openZasilkovnaWidget(methodId, $container);
            } else if (widgetType === 'ppl_parcelshop') {
                this.openPPLParcelShopWidget(methodId, $container);
            }
        },

        openZasilkovnaWidget: function(methodId, $container) {
            const self = this;

            if (typeof Packeta === 'undefined' || typeof Packeta.Widget === 'undefined') {
                console.error('[WSE] Packeta widget is not loaded');
                alert(wse_checkout_widgets.texts.error_saving);
                return;
            }

            // Callback funkce pro výběr místa
            const onPointSelected = function(point) {
                console.log('[WSE] Zásilkovna point selected:', point);
                
                if (point && point.id) {
                    self.savePickupPoint(methodId, {
                        pickup_id: point.id,
                        pickup_name: point.name + ', ' + point.city + ', ' + point.street,
                        pickup_data: point.formatedValue || JSON.stringify(point),
                        widget_type: 'zasilkovna',
                        point_details: point
                    }, $container);
                }
            };

            // Otevřít Packeta widget
            try {
                Packeta.Widget.pick(
                    this.packetaConfig.apiKey,
                    onPointSelected,
                    this.packetaConfig.options
                );
            } catch (error) {
                console.error('[WSE] Error opening Packeta widget:', error);
                alert(wse_checkout_widgets.texts.error_saving);
            }
        },

        openPPLParcelShopWidget: function(methodId, $container) {
            const self = this;
            
            // Pro PPL ParcelShop - zatím fallback na alert
            // Můžete implementovat specifický PPL widget pokud je k dispozici
            console.log('[WSE] PPL ParcelShop widget - not implemented yet');
            
            // Mockup pro testování
            const mockPoint = {
                id: 'PPL_TEST_001',
                name: 'PPL ParcelShop - TEST',
                city: 'Praha',
                street: 'Testovací 123',
                postcode: '110 00'
            };
            
            if (confirm('Chcete vybrat testovací PPL ParcelShop?')) {
                self.savePickupPoint(methodId, {
                    pickup_id: mockPoint.id,
                    pickup_name: mockPoint.name + ', ' + mockPoint.city + ', ' + mockPoint.street,
                    pickup_data: JSON.stringify(mockPoint),
                    widget_type: 'ppl_parcelshop',
                    point_details: mockPoint
                }, $container);
            }
        },

        savePickupPoint: function(methodId, pointData, $container) {
            const self = this;
            
            console.log('[WSE] Saving pickup point:', pointData);

            // Uložit do formuláře
            $container.find('.wse-pickup-point-data').val(pointData.pickup_data);
            $container.find('.wse-pickup-point-id').val(pointData.pickup_id);
            $container.find('.wse-pickup-point-name').val(pointData.pickup_name);

            // Uložit do cache
            this.savedPickupPoints[methodId] = pointData;

            // Aktualizovat UI
            this.updatePickupPointDisplay($container, pointData);

            // AJAX uložení do session
            $.ajax({
                url: wse_checkout_widgets.ajax_url,
                type: 'POST',
                data: {
                    action: 'wse_save_pickup_point',
                    nonce: wse_checkout_widgets.nonce,
                    method_id: methodId,
                    pickup_data: pointData.pickup_data,
                    pickup_id: pointData.pickup_id,
                    pickup_name: pointData.pickup_name
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[WSE] Pickup point saved successfully');
                    } else {
                        console.error('[WSE] Failed to save pickup point:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[WSE] AJAX error saving pickup point:', error);
                }
            });
        },

        updatePickupPointDisplay: function($container, pointData) {
            const $selectedInfo = $container.find('.wse-selected-point-details');
            const $selectedSection = $container.find('.wse-pickup-point-selected');
            const $notSelectedSection = $container.find('.wse-pickup-point-not-selected');

            // Zobrazit informace o vybraném místě
            $selectedInfo.html(
                '<div class="wse-point-name">' + this.escapeHtml(pointData.pickup_name) + '</div>' +
                '<div class="wse-point-id"><small>ID: ' + this.escapeHtml(pointData.pickup_id) + '</small></div>'
            );

            // Přepnout zobrazení
            $selectedSection.show();
            $notSelectedSection.hide();
        },

        loadSavedPickupPoint: function(methodId, $container) {
            // Zkontrolovat cache
            if (this.savedPickupPoints[methodId]) {
                this.updatePickupPointDisplay($container, this.savedPickupPoints[methodId]);
                return;
            }

            // Zkontrolovat formulářová pole
            const savedId = $container.find('.wse-pickup-point-id').val();
            const savedName = $container.find('.wse-pickup-point-name').val();
            const savedData = $container.find('.wse-pickup-point-data').val();

            if (savedId && savedName) {
                const pointData = {
                    pickup_id: savedId,
                    pickup_name: savedName,
                    pickup_data: savedData
                };
                
                this.savedPickupPoints[methodId] = pointData;
                this.updatePickupPointDisplay($container, pointData);
            } else {
                // Žádné místo není vybráno
                $container.find('.wse-pickup-point-selected').hide();
                $container.find('.wse-pickup-point-not-selected').show();
            }
        },

        validatePickupPointSelection: function() {
            const selectedMethod = $('input[name^="shipping_method"]:checked').val();
            
            if (!selectedMethod) {
                return true; // Nechť WooCommerce validuje dopravní metodu
            }

            const methodId = selectedMethod.split(':')[0];
            const pickupMethods = ['wse_elogist_ppl_parcelshop', 'wse_elogist_zasilkovna'];

            if (pickupMethods.includes(methodId)) {
                const $container = $('.wse-pickup-point-selector[data-method-id="' + methodId + '"]');
                const selectedPointId = $container.find('.wse-pickup-point-id').val();

                if (!selectedPointId) {
                    alert(wse_checkout_widgets.texts.point_required);
                    
                    // Scroll k selektoru výdejního místa
                    if ($container.length > 0) {
                        $('html, body').animate({
                            scrollTop: $container.offset().top - 100
                        }, 500);
                    }
                    
                    return false;
                }
            }

            return true;
        },

        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };

    // Inicializace po načtení DOM
    $(document).ready(function() {
        // Čekat na načtení Packeta widgetu
        const initWidgets = function() {
            if (typeof Packeta !== 'undefined') {
                WSE_CheckoutWidgets.init();
            } else {
                setTimeout(initWidgets, 100);
            }
        };
        
        initWidgets();
    });

    // Export pro debugging
    window.WSE_CheckoutWidgets = WSE_CheckoutWidgets;

})(jQuery);
