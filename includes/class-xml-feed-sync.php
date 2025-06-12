<?php
/**
 * XML Feed Synchronization Class - OPRAVENÉ
 * Pro synchronizaci produktů z Shoptet XML feedu s podporou variant
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_XML_Feed_Sync
{
    private $xml_feed_url;
    private $logger;
    private $stats;
    private $category_cache = [];
    private $attribute_cache = [];


    public function __construct($xml_feed_url = null)
    {
        $this->xml_feed_url = $xml_feed_url ?: get_option('wse_xml_feed_url', '');
        $this->logger = WSE_Logger::get_instance();
        $this->stats = [
            'total_processed' => 0,
            'total_imported' => 0,
            'total_updated' => 0,
            'total_errors' => 0,
            'total_variants' => 0,
            'duration' => 0
        ];
    }

    /**
     * Test XML feedu
     */
    public function test_xml_feed()
    {
        try {
            if (empty($this->xml_feed_url)) {
                return [
                    'success' => false,
                    'message' => 'XML Feed URL není nakonfigurována'
                ];
            }

            $this->logger->info('Testing XML feed', ['url' => $this->xml_feed_url], 'xml_sync');

            // Pokus o stažení XML s timeoutem
            $response = wp_remote_get($this->xml_feed_url, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'WSE XML Sync/' . WSE_VERSION
                ]
            ]);

            if (is_wp_error($response)) {
                return [
                    'success' => false,
                    'message' => 'Chyba při stahování XML: ' . $response->get_error_message()
                ];
            }

            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => "HTTP chyba: {$http_code}"
                ];
            }

            $xml_content = wp_remote_retrieve_body($response);
            
            if (empty($xml_content)) {
                return [
                    'success' => false,
                    'message' => 'XML feed je prázdný'
                ];
            }

            // Kontrola validity XML
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml_content);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $error_messages = [];
                foreach ($errors as $error) {
                    $error_messages[] = trim($error->message);
                }
                return [
                    'success' => false,
                    'message' => 'Neplatný XML: ' . implode('; ', $error_messages)
                ];
            }

            // Kontrola struktury
            if (!isset($xml->SHOPITEM)) {
                return [
                    'success' => false,
                    'message' => 'XML neobsahuje žádné produkty (chybí SHOPITEM elementy)'
                ];
            }

            $products_count = count($xml->SHOPITEM);
            $variants_count = 0;

            // Spočítat varianty
            foreach ($xml->SHOPITEM as $item) {
                if (isset($item->VARIANTS->VARIANT)) {
                    $variants_count += count($item->VARIANTS->VARIANT);
                }
            }

            $this->logger->info('XML feed test successful', [
                'products_count' => $products_count,
                'variants_count' => $variants_count
            ], 'xml_sync');

            return [
                'success' => true,
                'message' => "XML feed je platný",
                'products_count' => $products_count,
                'variants_count' => $variants_count
            ];

        } catch (Exception $e) {
            $this->logger->error('XML feed test failed', [
                'error' => $e->getMessage()
            ], 'xml_sync');

            return [
                'success' => false,
                'message' => 'Chyba při testování: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Hlavní synchronizační metoda
     */
    public function sync_products_from_xml()
    {
        $start_time = time();
        
        try {
            $this->logger->info('Starting XML products sync', ['url' => $this->xml_feed_url], 'xml_sync');

            // Test XML feedu před synchronizací
            $test_result = $this->test_xml_feed();
            if (!$test_result['success']) {
                throw new Exception('XML feed test failed: ' . $test_result['message']);
            }

            // Stažení XML
            $xml_content = $this->download_xml_feed();
            if (!$xml_content) {
                throw new Exception('Failed to download XML feed');
            }

            // Zpracování XML
            $xml = simplexml_load_string($xml_content);
            if ($xml === false) {
                throw new Exception('Failed to parse XML content');
            }

            // Inicializace atributů před zpracováním produktů
            $this->initialize_product_attributes();

            // Zpracování produktů
            $this->process_xml_products($xml);

            // Dokončení
            $this->stats['duration'] = time() - $start_time;
            update_option('wse_last_xml_sync', current_time('mysql'));

            $this->logger->info('XML sync completed successfully', $this->stats, 'xml_sync');

            return $this->stats;

        } catch (Exception $e) {
            $this->stats['duration'] = time() - $start_time;
            
            $this->logger->error('XML sync failed', [
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ], 'xml_sync');

            throw $e;
        }
    }

    /**
     * Inicializace produktových atributů
     */
    private function initialize_product_attributes()
    {
        // Definice atributů, které budeme importovat
        $attributes = [
            'značka' => [
                'name' => 'Značka',
                'slug' => 'znacka',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ],
            'pohlaví' => [
                'name' => 'Pohlaví',
                'slug' => 'pohlavi',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ],
            'dominantní ingredience' => [
                'name' => 'Dominantní ingredience',
                'slug' => 'dominantni-ingredience',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ],
            'druh vůně' => [
                'name' => 'Druh vůně',
                'slug' => 'druh-vune',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ],
            'roční období' => [
                'name' => 'Roční období',
                'slug' => 'rocni-obdobi',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ]
        ];

        foreach ($attributes as $key => $attribute_data) {
            $attribute_name = 'pa_' . $attribute_data['slug'];
            
            // Kontrola, zda atribut existuje
            $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);
            
            if (!$attribute_id) {
                // Vytvoření nového atributu
                $attribute_id = wc_create_attribute([
                    'name' => $attribute_data['name'],
                    'slug' => $attribute_data['slug'],
                    'type' => $attribute_data['type'],
                    'order_by' => $attribute_data['order_by'],
                    'has_archives' => $attribute_data['has_archives']
                ]);

                if (is_wp_error($attribute_id)) {
                    $this->logger->warning('Failed to create attribute', [
                        'attribute' => $attribute_data['name'],
                        'error' => $attribute_id->get_error_message()
                    ], 'xml_sync');
                    continue;
                }

                // Registrace taxonomie
                register_taxonomy($attribute_name, 'product', [
                    'hierarchical' => false,
                    'labels' => [
                        'name' => $attribute_data['name'],
                        'singular_name' => $attribute_data['name']
                    ],
                    'show_ui' => true,
                    'show_in_menu' => false,
                    'show_tagcloud' => false,
                    'query_var' => true,
                    'rewrite' => ['slug' => $attribute_data['slug']],
                ]);
            }

            // Uložení do cache
            $this->attribute_cache[$key] = $attribute_name;
        }
    }

    /**
     * Stažení XML feedu
     */
    private function download_xml_feed()
    {
        $response = wp_remote_get($this->xml_feed_url, [
            'timeout' => 1200, // Zvýšený timeout pro velké soubory
            'headers' => [
                'User-Agent' => 'WSE XML Sync/' . WSE_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to download XML feed', [
                'error' => $response->get_error_message()
            ], 'xml_sync');
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            $this->logger->error('HTTP error downloading XML feed', [
                'http_code' => $http_code
            ], 'xml_sync');
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Zpracování produktů z XML
     */
    private function process_xml_products($xml)
    {
        if (!isset($xml->SHOPITEM)) {
            throw new Exception('No SHOPITEM elements found in XML');
        }

        $batch_size = 50; // Zpracovávat po dávkách
        $processed = 0;

        foreach ($xml->SHOPITEM as $shopitem) {
            try {
                $this->process_single_product($shopitem);
                $this->stats['total_processed']++;
                $processed++;

                // Každých 50 produktů uložit mezivýsledky a uvolnit paměť
                if ($processed % $batch_size === 0) {
                    $this->logger->debug("Processed {$processed} products", [], 'xml_sync');
                    
                    // Uvolnit paměť
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }

            } catch (Exception $e) {
                $this->stats['total_errors']++;
                $this->logger->error('Failed to process product', [
                    'product_id' => (string)($shopitem['id'] ?? 'unknown'),
                    'product_name' => (string)($shopitem->NAME ?? 'unknown'),
                    'error' => $e->getMessage()
                ], 'xml_sync');
            }
        }
    }

    /**
     * Zpracování jednotlivého produktu
     */
    private function process_single_product($shopitem)
    {
        // Základní data produktu
        $xml_id = (string)($shopitem['id'] ?? '');
        $xml_guid = (string)($shopitem->GUID ?? '');
        $name = (string)($shopitem->NAME ?? '');
        $manufacturer = (string)($shopitem->MANUFACTURER ?? '');
        $supplier = (string)($shopitem->SUPPLIER ?? '');

        if (empty($xml_guid) || empty($name)) {
            throw new Exception('Missing required product data (GUID or NAME)');
        }

        // Kontrola, zda produkt již existuje
        $existing_product = $this->find_existing_product_by_guid($xml_guid);

        // Zpracování podle toho, zda má produkt varianty
        $has_variants = isset($shopitem->VARIANTS->VARIANT) && count($shopitem->VARIANTS->VARIANT) > 0;

        if ($has_variants) {
            $this->process_variable_product($shopitem, $existing_product);
        } else {
            $this->process_simple_product($shopitem, $existing_product);
        }
    }

    /**
     * Zpracování produktu s variantami
     */
    private function process_variable_product($shopitem, $existing_product = null)
    {
        $xml_guid = (string)($shopitem->GUID ?? '');
        $name = (string)($shopitem->NAME ?? '');

        // Vytvoření nebo aktualizace hlavního produktu
        if ($existing_product) {
            $product_id = $existing_product->get_id();
            $this->logger->debug('Updating variable product', ['product_id' => $product_id, 'name' => $name], 'xml_sync');
        } else {
            $product_id = $this->create_variable_product($shopitem);
            $this->stats['total_imported']++;
            $this->logger->info('Created new variable product', ['product_id' => $product_id, 'name' => $name], 'xml_sync');
        }

        // Aktualizace základních dat
        $this->update_product_basic_data($product_id, $shopitem, 'variable');

        // Zpracování variant
        $this->process_product_variants($product_id, $shopitem);

        if ($existing_product) {
            $this->stats['total_updated']++;
        }
    }

    /**
     * Zpracování jednoduchého produktu
     */
    private function process_simple_product($shopitem, $existing_product = null)
    {
        $xml_guid = (string)($shopitem->GUID ?? '');
        $name = (string)($shopitem->NAME ?? '');

        if ($existing_product) {
            $product_id = $existing_product->get_id();
            $this->logger->debug('Updating simple product', ['product_id' => $product_id, 'name' => $name], 'xml_sync');
            $this->stats['total_updated']++;
        } else {
            $product_id = $this->create_simple_product($shopitem);
            $this->stats['total_imported']++;
            $this->logger->info('Created new simple product', ['product_id' => $product_id, 'name' => $name], 'xml_sync');
        }

        // Aktualizace dat
        $this->update_product_basic_data($product_id, $shopitem, 'simple');
        
        // Pro jednoduché produkty nastavit cenu a sklad
        $this->update_simple_product_price_stock($product_id, $shopitem);
    }

    /**
     * Vytvoření proměnného produktu
     */
    private function create_variable_product($shopitem)
    {
        $name = (string)($shopitem->NAME ?? '');
        $slug = sanitize_title($name);
        
        // Ujistit se, že slug je unikátní
        $unique_slug = wp_unique_post_slug($slug, 0, 'publish', 'product', 0);

        $product = new WC_Product_Variable();
        $product->set_name($name);
        $product->set_slug($unique_slug);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_manage_stock(false);
        
        $product_id = $product->save();

        return $product_id;
    }

    /**
     * Vytvoření jednoduchého produktu
     */
    private function create_simple_product($shopitem)
    {
        $name = (string)($shopitem->NAME ?? '');
        $slug = sanitize_title($name);
        
        // Ujistit se, že slug je unikátní
        $unique_slug = wp_unique_post_slug($slug, 0, 'publish', 'product', 0);

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_slug($unique_slug);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        
        $product_id = $product->save();

        return $product_id;
    }

    /**
     * Aktualizace základních dat produktu
     */
    private function update_product_basic_data($product_id, $shopitem, $product_type)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Základní data
        $name = (string)($shopitem->NAME ?? '');
        $short_description = $this->clean_html_content((string)($shopitem->SHORT_DESCRIPTION ?? ''));
        $description = $this->clean_html_content((string)($shopitem->DESCRIPTION ?? ''));
        $manufacturer = (string)($shopitem->MANUFACTURER ?? '');
        $supplier = (string)($shopitem->SUPPLIER ?? '');

        $product->set_name($name);
        $product->set_short_description($short_description);
        $product->set_description($description);

        // Obrázky - pouze pokud je povoleno
        if (get_option('wse_import_images', true)) {
            $this->update_product_images($product, $shopitem);
        }

        // Kategorie
        $this->update_product_categories($product, $shopitem);

        // Meta data pro tracking
        update_post_meta($product_id, '_xml_guid', (string)($shopitem->GUID ?? ''));
        update_post_meta($product_id, '_xml_id', (string)($shopitem['id'] ?? ''));
        update_post_meta($product_id, '_xml_manufacturer', $manufacturer);
        update_post_meta($product_id, '_xml_supplier', $supplier);
        update_post_meta($product_id, '_xml_last_sync', current_time('mysql'));

        if (isset($shopitem->TEXT_PROPERTIES->TEXT_PROPERTY)) {
            $inspirovano_value = '';
            foreach ($shopitem->TEXT_PROPERTIES->TEXT_PROPERTY as $text_prop) {
                $name  = trim((string) $text_prop->NAME);
                $value = trim((string) $text_prop->VALUE);
                if (($name === 'Inspirováno' || $name === 'Podobné') && $value !== '') {
                    $inspirovano_value = $value;
                    break; 
                }
            }
            if ($inspirovano_value !== '') {
                update_post_meta($product_id, '_inspirovano', $inspirovano_value);
            }
        }

        // Import INFORMATION_PARAMETERS jako atributy
        $this->update_information_parameters($product_id, $shopitem);

        $product->save();
    }

    /**
     * Načte INFORMATION_PARAMETERS a uloží je jako WC atributy
     */
    private function update_information_parameters($product_id, SimpleXMLElement $item)
    {
        // Mapování názvů parametrů na taxonomie (case-insensitive)
        $map = [
            'značka'                 => 'pa_znacka',
            'pohlavi'                => 'pa_pohlavi',
            'pohlaví'                => 'pa_pohlavi', // s háčkem
            'dominantní ingredience' => 'pa_dominantni-ingredience',
            'druh vůně'              => 'pa_druh-vune',
            'roční období'           => 'pa_rocni-obdobi',
        ];
        
        $collected = [];

        if (isset($item->INFORMATION_PARAMETERS->INFORMATION_PARAMETER)) {
            foreach ($item->INFORMATION_PARAMETERS->INFORMATION_PARAMETER as $param) {
                $param_name = mb_strtolower(trim((string)$param->NAME), 'UTF-8');
                
                if (!isset($map[$param_name])) {
                    continue;
                }
                
                $taxonomy = $map[$param_name];

                // Zpracování hodnot parametru
                $values = [];
                if (isset($param->VALUE)) {
                    foreach ($param->VALUE as $val) {
                        $value = trim((string)$val);
                        if ($value !== '') {
                            $values[] = $value;
                        }
                    }
                }

                if (empty($values)) {
                    continue;
                }

                // Vytvoření termů a přiřazení k produktu
                $term_ids = [];
                foreach ($values as $value) {
                    // Zkontrolovat, zda term existuje
                    $term = get_term_by('name', $value, $taxonomy);
                    
                    if (!$term) {
                        // Vytvořit nový term
                        $result = wp_insert_term($value, $taxonomy, [
                            'slug' => sanitize_title($value)
                        ]);
                        
                        if (!is_wp_error($result)) {
                            $term_ids[] = $result['term_id'];
                        } else {
                            $this->logger->warning('Failed to create term', [
                                'taxonomy' => $taxonomy,
                                'value' => $value,
                                'error' => $result->get_error_message()
                            ], 'xml_sync');
                        }
                    } else {
                        $term_ids[] = $term->term_id;
                    }
                }

                // Přiřadit termy k produktu
                if (!empty($term_ids)) {
                    wp_set_object_terms($product_id, $term_ids, $taxonomy, false);
                    $collected[$taxonomy] = $values;
                }
            }
        }

        // Aktualizovat _product_attributes
        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($product_attributes)) {
            $product_attributes = [];
        }

        foreach ($collected as $taxonomy => $vals) {
            if (!isset($product_attributes[$taxonomy])) {
                $product_attributes[$taxonomy] = [
                    'name'         => $taxonomy,
                    'value'        => '',
                    'position'     => count($product_attributes),
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 1,
                ];
            }
            // Pro zobrazení v administraci
            $product_attributes[$taxonomy]['value'] = implode(' | ', $vals);
        }

        update_post_meta($product_id, '_product_attributes', $product_attributes);
    }

    /**
     * Aktualizace ceny a skladů pro jednoduchý produkt
     */
    private function update_simple_product_price_stock($product_id, $shopitem)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Cena
        $price = (float)($shopitem->PRICE_VAT ?? 0);
        $regular_price = isset($shopitem->STANDARD_PRICE) && (float)$shopitem->STANDARD_PRICE > 0 
                        ? (float)$shopitem->STANDARD_PRICE 
                        : $price;

        if ($price > 0) {
            $product->set_regular_price($regular_price);
            $product->set_price($price);
            if ($price < $regular_price) {
                $product->set_sale_price($price);
            }
        }

        // Sklad
        if (isset($shopitem->STOCK->AMOUNT)) {
            $stock_amount = (int)$shopitem->STOCK->AMOUNT;
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_amount);
            $product->set_stock_status($stock_amount > 0 ? 'instock' : 'outofstock');
        }

        // SKU
        if (isset($shopitem->CODE) && !empty((string)$shopitem->CODE)) {
            $product->set_sku((string)$shopitem->CODE);
        }

        // Hmotnost
        if (isset($shopitem->LOGISTIC->WEIGHT)) {
            $weight = (float)$shopitem->LOGISTIC->WEIGHT;
            if ($weight > 0) {
                $product->set_weight($weight);
            }
        }

        // EAN
        if (isset($shopitem->EAN) && !empty((string)$shopitem->EAN)) {
            update_post_meta($product_id, '_ean', (string)$shopitem->EAN);
        }

        $product->save();
    }

    /**
     * Zpracování variant produktu
     */
    private function process_product_variants($product_id, $shopitem)
    {
        if (!isset($shopitem->VARIANTS->VARIANT)) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return;
        }

        // Vytvoření nebo aktualizace atributu "Velikost"
        $this->ensure_size_attribute($product_id);

        $size_values = [];
        $variant_objects = [];

        // Zpracování každé varianty
        foreach ($shopitem->VARIANTS->VARIANT as $variant_xml) {
            try {
                $variant_id = $this->process_single_variant($product_id, $variant_xml);
                if ($variant_id) {
                    $variant_objects[] = $variant_id;
                    $this->stats['total_variants']++;

                    // Získat hodnotu velikosti
                    $size_value = $this->get_variant_size($variant_xml);
                    if ($size_value && !in_array($size_value, $size_values)) {
                        $size_values[] = $size_value;
                    }
                }
            } catch (Exception $e) {
                $this->logger->error('Failed to process variant', [
                    'parent_product_id' => $product_id,
                    'variant_xml_id' => (string)($variant_xml['id'] ?? 'unknown'),
                    'error' => $e->getMessage()
                ], 'xml_sync');
                $this->stats['total_errors']++;
            }
        }

        // Aktualizace dostupných hodnot atributu velikosti
        if (!empty($size_values)) {
            $this->update_size_attribute_values($product_id, $size_values);
        }

        // Aktualizace seznamu variant u hlavního produktu
        if (!empty($variant_objects)) {
            $product->set_children($variant_objects);
            $product->save();
        }
    }

    /**
     * Zpracování jedné varianty
     */
    private function process_single_variant($parent_id, $variant_xml)
    {
        $xml_variant_id = (string)($variant_xml['id'] ?? '');
        $variant_code = (string)($variant_xml->CODE ?? '');
        $size_value = $this->get_variant_size($variant_xml);

        if (empty($size_value)) {
            throw new Exception('Variant without size parameter');
        }

        // Hledání existující varianty
        $existing_variation = $this->find_existing_variation($parent_id, $xml_variant_id);

        if ($existing_variation) {
            $variation_id = $existing_variation->get_id();
            $variation = $existing_variation;
        } else {
            // Vytvoření nové varianty
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation_id = $variation->save();
        }

        // Nastavení atributů varianty
        $variation->set_attributes([
            'pa_velikost' => sanitize_title($size_value)
        ]);

        // Cena
        $price = (float)($variant_xml->PRICE_VAT ?? 0);
        $regular_price = isset($variant_xml->STANDARD_PRICE) && (float)$variant_xml->STANDARD_PRICE > 0 
                        ? (float)$variant_xml->STANDARD_PRICE 
                        : $price;

        if ($price > 0) {
            $variation->set_regular_price($regular_price);
            $variation->set_price($price);
            if ($price < $regular_price) {
                $variation->set_sale_price($price);
            }
        }

        // Sklad
        if (isset($variant_xml->STOCK->AMOUNT)) {
            $stock_amount = (int)$variant_xml->STOCK->AMOUNT;
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($stock_amount);
            $variation->set_stock_status($stock_amount > 0 ? 'instock' : 'outofstock');
        }

        // SKU
        if (!empty($variant_code)) {
            $variation->set_sku($variant_code);
        }

        // Hmotnost
        if (isset($variant_xml->LOGISTIC->WEIGHT)) {
            $weight = (float)$variant_xml->LOGISTIC->WEIGHT;
            if ($weight > 0) {
                $variation->set_weight($weight);
            }
        }

        // EAN
        if (isset($variant_xml->EAN) && !empty((string)$variant_xml->EAN)) {
            update_post_meta($variation_id, '_ean', (string)$variant_xml->EAN);
        }

        // Obrázek varianty
        if (get_option('wse_import_images', true) && isset($variant_xml->IMAGE_REF) && !empty((string)$variant_xml->IMAGE_REF)) {
            $image_id = $this->import_image((string)$variant_xml->IMAGE_REF, sanitize_title($size_value));
            if ($image_id) {
                $variation->set_image_id($image_id);
            }
        }

        // Meta data pro tracking
        update_post_meta($variation_id, '_xml_variant_id', $xml_variant_id);
        update_post_meta($variation_id, '_xml_variant_code', $variant_code);
        update_post_meta($variation_id, '_xml_last_sync', current_time('mysql'));

        $variation->save();

        return $variation_id;
    }

    /**
     * Získání hodnoty velikosti z varianty
     */
    private function get_variant_size($variant_xml)
    {
        if (!isset($variant_xml->PARAMETERS->PARAMETER)) {
            return null;
        }

        foreach ($variant_xml->PARAMETERS->PARAMETER as $param) {
            if ((string)($param->NAME ?? '') === 'Velikost') {
                return (string)($param->VALUE ?? '');
            }
        }

        return null;
    }

    /**
     * Zajištění existence atributu "Velikost"
     */
    private function ensure_size_attribute($product_id)
    {
        $attribute_name = 'pa_velikost';
        
        // Kontrola, zda atribut existuje
        $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);
        
        if (!$attribute_id) {
            // Vytvoření nového atributu
            $attribute_id = wc_create_attribute([
                'name' => 'Velikost',
                'slug' => 'velikost',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false
            ]);

            if (is_wp_error($attribute_id)) {
                throw new Exception('Failed to create size attribute: ' . $attribute_id->get_error_message());
            }

            // Registrace taxonomie
            register_taxonomy($attribute_name, 'product', [
                'hierarchical' => false,
                'labels' => [
                    'name' => 'Velikost',
                    'singular_name' => 'Velikost'
                ],
                'show_ui' => true,
                'show_in_menu' => false,
                'show_tagcloud' => false,
                'query_var' => true,
                'rewrite' => ['slug' => 'velikost'],
            ]);
        }

        // Přiřazení atributu k produktu
        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($product_attributes)) {
            $product_attributes = [];
        }

        if (!isset($product_attributes[$attribute_name])) {
            $product_attributes[$attribute_name] = [
                'name' => $attribute_name,
                'value' => '',
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            ];

            update_post_meta($product_id, '_product_attributes', $product_attributes);
        }
    }

    /**
     * Aktualizace hodnot atributu velikosti
     */
    private function update_size_attribute_values($product_id, $size_values)
    {
        $attribute_name = 'pa_velikost';

        // Načíst existující atributy produktu
        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($product_attributes)) {
            $product_attributes = [];
        }

        // Zajistit, že atribut pa_velikost je v metadatech
        if (!isset($product_attributes[$attribute_name])) {
            $product_attributes[$attribute_name] = [
                'name'         => $attribute_name,
                'value'        => '',
                'position'     => 0,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => 1,
            ];
        }

        // Pro každou velikost:
        foreach ($size_values as $size_value) {
            $term_slug = sanitize_title($size_value);

            // 1) Vytvořit nebo dohledat term
            $term = get_term_by('slug', $term_slug, $attribute_name);
            if (!$term) {
                $result = wp_insert_term($size_value, $attribute_name, [
                    'slug' => $term_slug,
                ]);
                if (is_wp_error($result)) {
                    $this->logger->warning('Failed to create size term', [
                        'size_value' => $size_value,
                        'error'      => $result->get_error_message(),
                    ], 'xml_sync');
                    continue;
                }
                $term_id = $result['term_id'];
            } else {
                $term_id = $term->term_id;
            }

            // 2) Přiřadit term k produktu
            wp_set_object_terms($product_id, (int)$term_id, $attribute_name, true);
        }

        // 3) Uložit seznam hodnot do metadat produktu
        //    Woo čeká řetězec oddělený " | "
        $product_attributes[$attribute_name]['value'] = implode(' | ', $size_values);
        update_post_meta($product_id, '_product_attributes', $product_attributes);
    }

    /**
     * Aktualizace obrázků produktu
     */
    private function update_product_images($product, $shopitem)
    {
        if (!isset($shopitem->IMAGES->IMAGE)) {
            return;
        }

        $image_ids = [];
        $product_name = $product->get_name();

        foreach ($shopitem->IMAGES->IMAGE as $image) {
            $image_url = (string)$image;
            $image_description = (string)($image['description'] ?? '');
            
            if (!empty($image_url)) {
                $image_id = $this->import_image($image_url, $product_name, $image_description);
                if ($image_id) {
                    $image_ids[] = $image_id;
                }
            }
        }

        if (!empty($image_ids)) {
            // První obrázek jako hlavní
            $product->set_image_id($image_ids[0]);
            
            // Zbytek jako galerie
            if (count($image_ids) > 1) {
                $product->set_gallery_image_ids(array_slice($image_ids, 1));
            }
        }
    }

    /**
     * Import obrázku
     */
    private function import_image($image_url, $product_name, $description = '')
    {
        // Kontrola, zda obrázek již existuje
        $existing_attachment = $this->find_existing_image($image_url);
        if ($existing_attachment) {
            return $existing_attachment;
        }

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        try {
            // Stažení obrázku
            $tmp = download_url($image_url, 30); // 30 sekund timeout
            
            if (is_wp_error($tmp)) {
                $this->logger->warning('Failed to download image', [
                    'image_url' => $image_url,
                    'error' => $tmp->get_error_message()
                ], 'xml_sync');
                return false;
            }

            // Příprava dat pro upload
            $file_array = [
                'name' => basename(parse_url($image_url, PHP_URL_PATH)) ?: 'image.jpg',
                'tmp_name' => $tmp
            ];

            // Upload
            $attachment_id = media_handle_sideload($file_array, 0, $description);

            // Vyčištění
            @unlink($tmp);

            if (is_wp_error($attachment_id)) {
                $this->logger->warning('Failed to upload image', [
                    'image_url' => $image_url,
                    'error' => $attachment_id->get_error_message()
                ], 'xml_sync');
                return false;
            }

            // Uložení původní URL pro budoucí kontroly
            update_post_meta($attachment_id, '_source_url', $image_url);
            
            // Nastavit alt text
            if (!empty($product_name)) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $product_name);
            }

            return $attachment_id;

        } catch (Exception $e) {
            $this->logger->error('Failed to import image', [
                'image_url' => $image_url,
                'error' => $e->getMessage()
            ], 'xml_sync');
            return false;
        }
    }

    /**
     * Hledání existujícího obrázku
     */
    private function find_existing_image($image_url)
    {
        global $wpdb;

        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1",
            $image_url
        ));

        if ($attachment_id && wp_attachment_is_image($attachment_id)) {
            return (int)$attachment_id;
        }

        return false;
    }

    /**
     * Aktualizace kategorií produktu — pouze z <DEFAULT_CATEGORY>
     */
    private function update_product_categories( $product, SimpleXMLElement $shopitem ) {
        if ( ! isset( $shopitem->CATEGORIES->DEFAULT_CATEGORY ) ) {
            return;
        }

        // Načteme cestu, např. "Parfémy > Vůně pro mladíky"
        $default = (string) $shopitem->CATEGORIES->DEFAULT_CATEGORY;
        $default = trim( $default );
        if ( $default === '' ) {
            return;
        }

        $path = explode( ' > ', $default );
        $leaf_cat_id = $this->create_category_hierarchy( $path );

        if ( $leaf_cat_id ) {
            // Přiřadíme jen finální kategorii (nepřepisujeme ostatní, ale produkt může mít více rodičů)
            $product->set_category_ids( [ $leaf_cat_id ] );
        }
    }

    /**
     * Vytvoření / načtení kategorie i celé hierarchie z pole názvů.
     * - Nevytváří duplicitní termy díky cache a kontrole parentu.
     * - Vrací ID konečné (nejhlubší) kategorie.
     */
    private function create_category_hierarchy( array $path, $parent_id = 0 ) {
        if ( empty( $path ) ) {
            return null;
        }

        // Aktuální úroveň
        $name = trim( array_shift( $path ) );
        if ( $name === '' ) {
            return null;
        }

        $slug = sanitize_title( $name );
        $cache_key = $parent_id . '|' . $slug;

        if ( isset( $this->category_cache[ $cache_key ] ) ) {
            $term_id = $this->category_cache[ $cache_key ];
        } else {
            // Zjistíme, zda už term existuje se stejným parentem
            $existing = get_term_by( 'slug', $slug, 'product_cat' );
            if ( $existing && (int) $existing->parent === (int) $parent_id ) {
                $term_id = $existing->term_id;
            } else {
                // Vytvoříme nový
                $result = wp_insert_term( $name, 'product_cat', [
                    'slug'   => $slug,
                    'parent' => $parent_id,
                ] );
                if ( is_wp_error( $result ) ) {
                    $this->logger->warning( 'Failed to create category', [
                        'name'      => $name,
                        'parent_id' => $parent_id,
                        'error'     => $result->get_error_message(),
                    ], 'xml_sync' );
                    return null;
                }
                $term_id = $result['term_id'];
            }
            // Uložíme do cache, abychom to v rámci jednoho importu netvořili znovu
            $this->category_cache[ $cache_key ] = $term_id;
        }

        // Pokud máme ještě podkategorie, jdeme rekurzivně dál
        if ( ! empty( $path ) ) {
            return $this->create_category_hierarchy( $path, $term_id );
        }

        return $term_id;
    }

    /**
     * Vyčištění HTML obsahu
     */
    private function clean_html_content($content)
    {
        if (empty($content)) {
            return '';
        }

        // Odstranění CDATA
        $content = str_replace(['<![CDATA[', ']]>'], '', $content);
        
        // Použití WordPress sanitizace
        return wp_kses($content, wp_kses_allowed_html('post'));
    }

    /**
     * Hledání existujícího produktu podle GUID
     */
    private function find_existing_product_by_guid($xml_guid)
    {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_xml_guid' AND meta_value = %s LIMIT 1",
            $xml_guid
        ));

        if ($product_id) {
            return wc_get_product($product_id);
        }

        return null;
    }

    /**
     * Hledání existující varianty
     */
    private function find_existing_variation($parent_id, $xml_variant_id)
    {
        global $wpdb;

        $variation_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_xml_variant_id' AND meta_value = %s 
             AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation')
             LIMIT 1",
            $xml_variant_id,
            $parent_id
        ));

        if ($variation_id) {
            return wc_get_product($variation_id);
        }

        return null;
    }

    /**
     * Synchronizace konkrétního produktu podle GUID
     */
    public function sync_single_product_by_guid($xml_guid)
    {
        try {
            $this->logger->info('Starting single product sync', ['xml_guid' => $xml_guid], 'xml_sync');

            // Stažení XML
            $xml_content = $this->download_xml_feed();
            if (!$xml_content) {
                throw new Exception('Failed to download XML feed');
            }

            $xml = simplexml_load_string($xml_content);
            if ($xml === false) {
                throw new Exception('Failed to parse XML content');
            }

            // Hledání konkrétního produktu v XML
            $target_product = null;
            foreach ($xml->SHOPITEM as $shopitem) {
                if ((string)($shopitem->GUID ?? '') === $xml_guid) {
                    $target_product = $shopitem;
                    break;
                }
            }

            if (!$target_product) {
                throw new Exception('Product not found in XML feed');
            }

            // Zpracování produktu
            $this->process_single_product($target_product);

            $this->logger->info('Single product sync completed', ['xml_guid' => $xml_guid], 'xml_sync');

            return true;

        } catch (Exception $e) {
            $this->logger->error('Single product sync failed', [
                'xml_guid' => $xml_guid,
                'error' => $e->getMessage()
            ], 'xml_sync');

            throw $e;
        }
    }

    /**
     * Smazání produktů, které nejsou v XML feedu
     */
     public function clean_orphaned_products($dry_run = true)
     {
        try {
            $this->logger->info('Starting orphaned products cleanup', ['dry_run' => $dry_run], 'xml_sync');

            // Získání všech XML GUID z feedu
            $xml_content = $this->download_xml_feed();
            if (!$xml_content) {
                throw new Exception('Failed to download XML feed');
            }

            $xml = simplexml_load_string($xml_content);
            if ($xml === false) {
                throw new Exception('Failed to parse XML content');
            }

            $xml_guids = [];
            foreach ($xml->SHOPITEM as $shopitem) {
                $guid = (string)($shopitem->GUID ?? '');
                if (!empty($guid)) {
                    $xml_guids[] = $guid;
                }
            }

            // Získání všech produktů s XML GUID z databáze
            global $wpdb;
            $db_products = $wpdb->get_results(
                "SELECT post_id, meta_value as xml_guid 
                 FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_xml_guid'"
            );

            $orphaned_products = [];
            foreach ($db_products as $db_product) {
                if (!in_array($db_product->xml_guid, $xml_guids)) {
                    $orphaned_products[] = $db_product->post_id;
                }
            }

            if ($dry_run) {
                $this->logger->info('Orphaned products found (dry run)', [
                    'count' => count($orphaned_products),
                    'product_ids' => array_slice($orphaned_products, 0, 10) // První 10 pro ukázku
                ], 'xml_sync');

                return [
                    'found' => count($orphaned_products),
                    'product_ids' => $orphaned_products
                ];
            } else {
                // Skutečné smazání
                $deleted_count = 0;
                foreach ($orphaned_products as $product_id) {
                    if (wp_delete_post($product_id, true)) {
                        $deleted_count++;
                    }
                }

                $this->logger->info('Orphaned products deleted', [
                    'deleted_count' => $deleted_count,
                    'total_found' => count($orphaned_products)
                ], 'xml_sync');

                return [
                    'deleted' => $deleted_count,
                    'found' => count($orphaned_products)
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Orphaned products cleanup failed', [
                'error' => $e->getMessage()
            ], 'xml_sync');

            throw $e;
        }
    }

    /**
     * Získání statistik synchronizace
     */
    public function get_sync_statistics()
    {
        global $wpdb;

        // Počet produktů z XML
        $xml_products_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_xml_guid'"
        );

        // Počet variant z XML
        $xml_variants_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_xml_variant_id'"
        );

        // Poslední synchronizace
        $last_sync = get_option('wse_last_xml_sync');

        // Nejstarší a nejnovější sync produktů
        $sync_dates = $wpdb->get_row(
            "SELECT MIN(meta_value) as oldest_sync, MAX(meta_value) as newest_sync 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_xml_last_sync'"
        );

        return [
            'total_xml_products' => (int)$xml_products_count,
            'total_xml_variants' => (int)$xml_variants_count,
            'last_full_sync' => $last_sync,
            'oldest_product_sync' => $sync_dates->oldest_sync ?? null,
            'newest_product_sync' => $sync_dates->newest_sync ?? null
        ];
    }
}
