<?php
/**
 * Order Synchronization Class - OPRAVENÁ VERZE
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Order_Sync
{
    private static $logger;
    private static $elogist_api;

    public static function init()
    {
        self::$logger = WSE_Logger::get_instance();
        self::$elogist_api = new WSE_ELogist_API();
        
        // Hook na změnu stavu objednávky
        add_action('woocommerce_order_status_changed', [__CLASS__, 'handle_order_status_change'], 10, 4);
    }

    /**
     * Handler pro změnu stavu objednávky
     */
    public static function handle_order_status_change($order_id, $old_status, $new_status, $order)
    {
        // Odeslat do eLogist pouze při změně na "processing"
        if ($new_status === 'processing') {
            self::send_order_to_elogist($order_id);
        }
    }

    /**
     * Odeslání objednávky do eLogist při změně stavu na "processing"
     */
    public static function send_order_to_elogist($order_id)
    {
        if (!self::$logger) {
            self::init();
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            self::$logger->error('Order not found', ['order_id' => $order_id], 'order_sync');
            return false;
        }

        // Kontrola, zda objednávka již nebyla odeslána
        $elogist_order_id = $order->get_meta('_elogist_order_id');
        if (!empty($elogist_order_id)) {
            self::$logger->warning('Order already sent to eLogist', [
                'order_id' => $order_id,
                'elogist_order_id' => $elogist_order_id
            ], 'order_sync');
            return false;
        }

        try {
            self::$logger->info('Sending order to eLogist', [
                'order_id' => $order_id,
                'order_number' => $order->get_order_number()
            ], 'order_sync');

            // Příprava dat objednávky pro eLogist
            $order_data = self::prepare_elogist_order_data($order);
            
            // Odeslání do eLogist
            $response = self::$elogist_api->send_delivery_order($order_data);
            
            if ($response && isset($response->result) && $response->result->code == 1000) {
                // Úspěšné odeslání
                $sys_order_id = $response->deliveryOrderStatus->sysOrderId ?? null;
                
                // Uložit eLogist identifikátory
                $order->update_meta_data('_elogist_order_id', $order_id);
                if ($sys_order_id) {
                    $order->update_meta_data('_elogist_sys_order_id', $sys_order_id);
                }
                $order->update_meta_data('_elogist_status', 'NEW');
                $order->update_meta_data('_elogist_sent_at', current_time('mysql'));
                $order->save();
                
                // Uložit do tracking tabulky
                self::save_order_mapping($order_id, $order_id, $sys_order_id);
                
                // Přidat poznámku k objednávce
                $note = sprintf(
                    __('Objednávka odeslána do eLogist systému. System ID: %s', 'wc-shoptet-elogist'),
                    $sys_order_id ?: 'N/A'
                );
                $order->add_order_note($note);
                
                // Změnit stav na "awaiting shipment"
                $order->update_status('awaiting-shipment', __('Čeká na expedici v eLogist', 'wc-shoptet-elogist'));
                
                self::$logger->info('Order sent to eLogist successfully', [
                    'order_id' => $order_id,
                    'sys_order_id' => $sys_order_id
                ], 'order_sync');
                
                return true;
                
            } else {
                // Chyba při odesílání
                $error_code = isset($response->result) ? $response->result->code : 'unknown';
                $error_description = isset($response->result) ? $response->result->description : 'Neznámá chyba';
                
                // Získat lidsky čitelnou chybu
                $error_message = self::$elogist_api->get_error_message($error_code);
                
                $order->add_order_note(sprintf(
                    __('Chyba při odesílání do eLogist: %s (%s)', 'wc-shoptet-elogist'),
                    $error_message,
                    $error_code
                ));
                
                self::$logger->error('Failed to send order to eLogist', [
                    'order_id' => $order_id,
                    'error_code' => $error_code,
                    'error_description' => $error_description,
                    'error_message' => $error_message
                ], 'order_sync');
                
                return false;
            }
            
        } catch (Exception $e) {
            $order->add_order_note(sprintf(
                __('Kritická chyba při odesílání do eLogist: %s', 'wc-shoptet-elogist'),
                $e->getMessage()
            ));
            
            self::$logger->error('Critical error sending order to eLogist', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ], 'order_sync');
            
            return false;
        }
    }

    /**
     * Příprava dat objednávky pro eLogist - OPRAVENO
     */
    private static function prepare_elogist_order_data($order)
    {
        $project_id = get_option('wse_elogist_project_id');
        
        if (empty($project_id)) {
            throw new Exception('eLogist Project ID není nakonfigurováno');
        }
        
        // Použít objekty místo polí pro SOAP
        $order_data = new stdClass();
        $order_data->projectId = $project_id;
        $order_data->orderId = (string)$order->get_id();
        $order_data->customerOrderId = (string)$order->get_order_number();
        $order_data->orderDateTime = $order->get_date_created()->format('c');
        $order_data->suspended = false;

        // Sender info
        $shop_name = get_bloginfo('name');
        $order_data->sender = new stdClass();
        $order_data->sender->label = $shop_name ?: 'E-shop';

        // Recipient info
        $recipient_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (empty($recipient_name)) {
            $recipient_name = $order->get_billing_company() ?: 'Zákazník';
        }

        $address = new stdClass();
        $address->company = $order->get_billing_company() ?: '';
        $address->street = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $address->city = $order->get_billing_city();
        $address->postcode = $order->get_billing_postcode();
        $address->country = $order->get_billing_country() ?: 'CZ';

        // Pokud má objednávka jinou doručovací adresu, použít ji
        if ($order->has_shipping_address()) {
            $shipping_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            if (!empty($shipping_name)) {
                $recipient_name = $shipping_name;
            }
            
            $address->company = $order->get_shipping_company() ?: '';
            $address->street = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());
            $address->city = $order->get_shipping_city();
            $address->postcode = $order->get_shipping_postcode();
            $address->country = $order->get_shipping_country() ?: 'CZ';
        }

        // Validace povinných údajů
        if (empty($address->street)) {
            throw new Exception('Chybí adresa příjemce');
        }
        if (empty($address->city)) {
            throw new Exception('Chybí město příjemce');
        }
        if (empty($address->postcode)) {
            throw new Exception('Chybí PSČ příjemce');
        }

        $order_data->recipient = new stdClass();
        $order_data->recipient->name = $recipient_name;
        $order_data->recipient->address = $address;
        $order_data->recipient->phone = self::format_phone_number($order->get_billing_phone());
        $order_data->recipient->email = $order->get_billing_email();

        // Shipping info
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method = reset($shipping_methods);
        
        if (!$shipping_method) {
            throw new Exception('Objednávka nemá nastavenou dopravu');
        }
        
        $order_data->shipping = self::map_shipping_method($shipping_method, $order);

        // Order items - OPRAVENO: správná struktura
        $order_data->orderItems = new stdClass();
        $order_data->orderItems->orderItem = self::prepare_order_items($order);
        
        if (empty($order_data->orderItems->orderItem)) {
            throw new Exception('Objednávka neobsahuje žádné položky');
        }

        // Poznámka zákazníka
        $customer_note = $order->get_customer_note();
        if (!empty($customer_note)) {
            $order_data->packingInstruction = $customer_note;
        }

        return $order_data;
    }

	/**
	 * Mapování dopravní metody z WooCommerce do eLogist - VYLEPŠENO PRO DEBUG
	 */
	private static function map_shipping_method($shipping_method, $order)
	{
		$method_id = $shipping_method->get_method_id();
		$instance_id = $shipping_method->get_instance_id();
		$full_method_id = $method_id . ':' . $instance_id;
		$method_title = $shipping_method->get_name();

		self::$logger->info('Mapping shipping method', [
			'method_id' => $method_id,
			'instance_id' => $instance_id,
			'full_method_id' => $full_method_id,
			'method_title' => $method_title,
			'order_id' => $order->get_id()
		], 'order_sync');

		// Základní mapování dopravních metod
		$carrier_mapping = [
			'wse_elogist_shipping_ppl' => [
				'carrierId' => 'PPL',
				'service' => 'PPL Parcel CZ Private'
			],
			'wse_elogist_shipping_ppl_parcelshop' => [
				'carrierId' => 'PPL',
				'service' => 'ParcelShop',
				'requires_branch' => true
			],
			'wse_elogist_shipping_zasilkovna' => [
				'carrierId' => 'ZASILKOVNA',
				'service' => 'Osobní odběr',
				'requires_branch' => true
			],
			'wse_elogist_shipping_zasilkovna_home' => [
				'carrierId' => 'ZASILKOVNA',
				'service' => 'Nejvýhodnější doručení na adresu'
			],
			// Přidáno mapování bez prefixu pro kompatibilitu
			'ppl_parcelshop' => [
				'carrierId' => 'PPL',
				'service' => 'ParcelShop',
				'requires_branch' => true
			],
			'zasilkovna' => [
				'carrierId' => 'ZASILKOVNA',
				'service' => 'Osobní odběr',
				'requires_branch' => true
			],
			// Fallback mapování
			'ppl' => ['carrierId' => 'PPL', 'service' => 'PPL Parcel CZ Private'],
			'dpd' => ['carrierId' => 'DPD-CZ', 'service' => 'DPD Private'],
			'ceska_posta' => ['carrierId' => 'CPOST', 'service' => 'Balík Do ruky']
		];

		// Najít odpovídající carrier
		$carrier = null;
		$matched_key = null;

		// Nejprve zkusit přesné shody
		foreach ($carrier_mapping as $wc_method => $elogist_carrier) {
			if ($method_id === $wc_method || $full_method_id === $wc_method) {
				$carrier = $elogist_carrier;
				$matched_key = $wc_method;
				break;
			}
		}

		// Pokud nenajdeme přesnou shodu, zkusit částečné shody
		if (!$carrier) {
			foreach ($carrier_mapping as $wc_method => $elogist_carrier) {
				if (strpos($method_id, $wc_method) !== false || strpos($full_method_id, $wc_method) !== false) {
					$carrier = $elogist_carrier;
					$matched_key = $wc_method;
					break;
				}
			}
		}

		// Pokud nenajdeme podle ID, zkusit podle názvu
		if (!$carrier) {
			$method_title_lower = strtolower($method_title);

			if (strpos($method_title_lower, 'ppl') !== false && strpos($method_title_lower, 'parcelshop') !== false) {
				$carrier = $carrier_mapping['ppl_parcelshop'];
				$matched_key = 'ppl_parcelshop (by title)';
			} elseif (strpos($method_title_lower, 'ppl') !== false) {
				$carrier = $carrier_mapping['ppl'];
				$matched_key = 'ppl (by title)';
			} elseif ((strpos($method_title_lower, 'zásilkovna') !== false || strpos($method_title_lower, 'zasilkovna') !== false) 
					  && (strpos($method_title_lower, 'adresu') !== false || strpos($method_title_lower, 'doručení') !== false)) {
				$carrier = $carrier_mapping['wse_elogist_shipping_zasilkovna_home'];
				$matched_key = 'zasilkovna_home (by title)';
			} elseif (strpos($method_title_lower, 'zásilkovna') !== false || strpos($method_title_lower, 'zasilkovna') !== false) {
				$carrier = $carrier_mapping['zasilkovna'];
				$matched_key = 'zasilkovna (by title)';
			}
		}

		// Fallback na PPL
		if (!$carrier) {
			$carrier = $carrier_mapping['ppl'];
			$matched_key = 'ppl (fallback)';

			self::$logger->warning('Unknown shipping method, using PPL fallback', [
				'method_id' => $method_id,
				'full_method_id' => $full_method_id,
				'method_title' => $method_title
			], 'order_sync');
		}

		self::$logger->info('Shipping method mapped', [
			'matched_key' => $matched_key,
			'carrier_id' => $carrier['carrierId'],
			'service' => $carrier['service'],
			'requires_branch' => isset($carrier['requires_branch']) ? $carrier['requires_branch'] : false
		], 'order_sync');

		// Vytvořit shipping objekt (StdClass pro eLogist API)
		$shipping = new StdClass();
		$shipping->carrierId = $carrier['carrierId'];
		$shipping->service = $carrier['service'];

		// Zpracování pickup points pro metody, které je vyžadují
		if (isset($carrier['requires_branch']) && $carrier['requires_branch']) {

			// OPRAVENO: Pokusit se najít pickup point různými způsoby podle skutečného method ID
			$branch_id = null;

			// Získat všechny možné method ID varianty
			$method_variants = [
				$method_id,                                    // wse_elogist_shipping
				$full_method_id,                              // wse_elogist_shipping:20
				$method_id . '_' . $matched_key,              // složený klíč
				$matched_key                                  // zasilkovna, ppl_parcelshop atd.
			];

			// Přidat specifické varianty podle dopravce
			if ($carrier['carrierId'] === 'ZASILKOVNA') {
				$method_variants[] = 'wse_elogist_shipping_zasilkovna';
				$method_variants[] = 'wse_elogist_shipping_zasilkovna:' . $instance_id;
				$method_variants[] = 'zasilkovna';
			}

			if ($carrier['carrierId'] === 'PPL' && isset($carrier['requires_branch'])) {
				$method_variants[] = 'wse_elogist_shipping_ppl_parcelshop';
				$method_variants[] = 'wse_elogist_shipping_ppl_parcelshop:' . $instance_id;
				$method_variants[] = 'ppl_parcelshop';
			}

			// Odebrat duplicity a prázdné hodnoty
			$method_variants = array_unique(array_filter($method_variants));

			// Pokusit se najít pickup point podle každé varianty
			foreach ($method_variants as $variant_method_id) {
				$branch_id = self::get_pickup_point_for_order($order, $variant_method_id);
				if (!empty($branch_id)) {
					self::$logger->info('Found pickup point with method variant', [
						'original_method_id' => $method_id,
						'variant_method_id' => $variant_method_id,
						'branch_id' => $branch_id,
						'carrier' => $carrier['carrierId']
					], 'order_sync');
					break;
				}
			}

			if (!empty($branch_id)) {
				$shipping->branchId = $branch_id;
				self::$logger->info('Added branch ID to shipping', [
					'method_id' => $method_id,
					'full_method_id' => $full_method_id,
					'branch_id' => $branch_id,
					'carrier' => $carrier['carrierId'],
					'matched_key' => $matched_key
				], 'order_sync');
			} else {
				// Pro metody vyžadující branch ID je to kritická chyba
				$error_message = sprintf(
					'Výdejní místo nebylo vybráno pro dopravní metodu %s (carrier: %s, method_id: %s)', 
					$carrier['service'],
					$carrier['carrierId'],
					$method_id
				);

				self::$logger->error('Missing pickup point for carrier requiring branch', [
					'method_id' => $method_id,
					'full_method_id' => $full_method_id,
					'carrier' => $carrier['carrierId'],
					'service' => $carrier['service'],
					'order_id' => $order->get_id()
				], 'order_sync');

				throw new Exception($error_message);
			}
		}

		// Poznámka k dopravě
		$customer_note = $order->get_customer_note();
		if (!empty($customer_note)) {
			$shipping->comment = $customer_note;
		}

		// COD (dobírka) - kontrola limitů podle dopravce
		$payment_method = $order->get_payment_method();
		if ($payment_method === 'cod') {
			$cod_value = floatval($order->get_total());

			// Limity dobírky podle dokumentace eLogist
			$cod_limits = [
				'PPL' => 100000,        // PPL do 100 000 CZK
				'ZASILKOVNA' => 20000,  // Zásilkovna do 20 000 CZK
				'DPD-CZ' => 50000,      // DPD do 50 000 CZK
				'CPOST' => 100000       // Česká pošta do 100 000 CZK
			];

			$max_cod = $cod_limits[$carrier['carrierId']] ?? 0;

			if ($max_cod > 0 && $cod_value <= $max_cod) {
				$shipping->cod = new StdClass();
				$shipping->cod->currency = $order->get_currency();
				// eLogist vyžaduje COD jako string s 2 desetinnými místy
				$shipping->cod->value = number_format($cod_value, 2, '.', '');

				self::$logger->info('Added COD to shipping', [
					'cod_value' => $cod_value,
					'formatted_value' => $shipping->cod->value,
					'currency' => $order->get_currency(),
					'carrier' => $carrier['carrierId']
				], 'order_sync');
			} else {
				self::$logger->warning('COD amount exceeds carrier limit', [
					'carrier' => $carrier['carrierId'],
					'cod_amount' => $cod_value,
					'max_allowed' => $max_cod
				], 'order_sync');

				// Můžeme buď throwit exception nebo ignorovat dobírku
				if ($max_cod > 0) {
					throw new Exception(sprintf(
						'Dobírka %s %s překračuje limit %s %s pro dopravce %s',
						number_format($cod_value, 2),
						$order->get_currency(),
						number_format($max_cod, 2),
						$order->get_currency(),
						$carrier['carrierId']
					));
				}
			}
		}

		// Insurance (pojištění zásilky) - OPRAVENO pro eLogist XML validaci
		$total_value = floatval($order->get_total());
		if ($total_value > 0) {
			$shipping->insurance = new StdClass();
			$shipping->insurance->currency = $order->get_currency();
			// eLogist vyžaduje pojištění jako string s 2 desetinnými místy
			$shipping->insurance->value = number_format($total_value, 2, '.', '');

			self::$logger->info('Added insurance to shipping', [
				'total_value' => $total_value,
				'formatted_value' => $shipping->insurance->value,
				'currency' => $order->get_currency(),
				'carrier' => $carrier['carrierId']
			], 'order_sync');
		}

		// Speciální možnosti pro PPL
		if ($carrier['carrierId'] === 'PPL') {
			// Večerní doručení
			$evening_delivery = $order->get_meta('_evening_delivery');
			if ($evening_delivery === 'yes') {
				if (!isset($shipping->option)) {
					$shipping->option = [];
				}

				$option = new StdClass();
				$option->name = 'evening_delivery';
				$option->value = 'true';
				$shipping->option[] = $option;
			}

			// Časové okno doručení
			$delivery_time_window = $order->get_meta('_delivery_time_window');
			if (!empty($delivery_time_window)) {
				if (!isset($shipping->option)) {
					$shipping->option = [];
				}

				$option = new StdClass();
				$option->name = 'delivery_time_window';
				$option->value = $delivery_time_window; // např. "12-14"
				$shipping->option[] = $option;
			}

			// Počet pokusů o doručení
			$delivery_attempts = $order->get_meta('_delivery_attempts');
			if (!empty($delivery_attempts) && is_numeric($delivery_attempts)) {
				$shipping->attempts = intval($delivery_attempts);
			} else {
				$shipping->attempts = 3; // Výchozí hodnota
			}
		}

		// Speciální možnosti pro Zásilkovnu
		if ($carrier['carrierId'] === 'ZASILKOVNA') {
			// Zásilkovna má omezení na váhu a rozměry
			$total_weight = 0;
			foreach ($order->get_items() as $item) {
				$product = $item->get_product();
				if ($product && $product->get_weight()) {
					$total_weight += floatval($product->get_weight()) * $item->get_quantity();
				}
			}

			// Log upozornění pokud je zásilka těžká (Zásilkovna limit obvykle 20-25kg)
			if ($total_weight > 20) {
				self::$logger->warning('Heavy package for Zásilkovna', [
					'total_weight' => $total_weight,
					'order_id' => $order->get_id()
				], 'order_sync');
			}
		}

		// Datum odeslání (volitelné)
		$send_at = $order->get_meta('_preferred_send_date');
		if (!empty($send_at)) {
			$shipping->sendAt = date('Y-m-d', strtotime($send_at));
		}

		return $shipping;
	}


	/**
	 * Vylepšená metoda pro získání pickup point ID - OPRAVENO pro WooCommerce Blocks
	 */
	private static function get_pickup_point_for_order($order, $method_id)
	{
		$order_id = $order->get_id();

		self::$logger->info('Searching pickup point for order', [
			'order_id' => $order_id,
			'method_id' => $method_id
		], 'order_sync');

		// 1. Nový způsob - pickup point data uložená naším pluginem
		$pickup_variants = [
			'_wse_pickup_point_id_' . $method_id,
			'_pickup_point_id',
			'_elogist_pickup_point_id',
			'_elogist_branch_id',
			'_branch_id',
			'_delivery_point_id'
		];

		foreach ($pickup_variants as $meta_key) {
			$pickup_point_id = get_post_meta($order_id, $meta_key, true);
			if (!empty($pickup_point_id)) {
				self::$logger->info('Found pickup point via order meta', [
					'order_id' => $order_id,
					'method_id' => $method_id,
					'meta_key' => $meta_key,
					'pickup_point_id' => $pickup_point_id
				], 'order_sync');
				return $pickup_point_id;
			}
		}

		// 2. Pro Zásilkovnu specifické pole
		if (strpos($method_id, 'zasilkovna') !== false) {
			$zasilkovna_variants = [
				'_packeta_point_id',
				'_zasilkovna_point_id',
				'_wse_pickup_point_id_wse_elogist_shipping_zasilkovna',
				'_wse_pickup_point_id_zasilkovna'
			];

			foreach ($zasilkovna_variants as $meta_key) {
				$pickup_point_id = get_post_meta($order_id, $meta_key, true);
				if (!empty($pickup_point_id)) {
					self::$logger->info('Found Zásilkovna pickup point', [
						'order_id' => $order_id,
						'meta_key' => $meta_key,
						'pickup_point_id' => $pickup_point_id
					], 'order_sync');
					return $pickup_point_id;
				}
			}
		}

		// 3. Pro PPL specifické pole
		if (strpos($method_id, 'ppl') !== false) {
			$ppl_variants = [
				'_ppl_point_id',
				'_parcelshop_id',
				'_wse_pickup_point_id_wse_elogist_shipping_ppl_parcelshop',
				'_wse_pickup_point_id_ppl_parcelshop'
			];

			foreach ($ppl_variants as $meta_key) {
				$pickup_point_id = get_post_meta($order_id, $meta_key, true);
				if (!empty($pickup_point_id)) {
					self::$logger->info('Found PPL pickup point', [
						'order_id' => $order_id,
						'meta_key' => $meta_key,
						'pickup_point_id' => $pickup_point_id
					], 'order_sync');
					return $pickup_point_id;
				}
			}
		}

		// 4. Zkontrolovat shipping method meta data
		$shipping_methods = $order->get_shipping_methods();
		foreach ($shipping_methods as $shipping_method) {
			$sm_method_id = $shipping_method->get_method_id();
			$sm_instance_id = $shipping_method->get_instance_id();
			$sm_full_id = $sm_method_id . ':' . $sm_instance_id;

			// Porovnat různé kombinace method ID
			if ($sm_method_id === $method_id || 
				$sm_full_id === $method_id ||
				strpos($sm_method_id, $method_id) !== false ||
				strpos($method_id, $sm_method_id) !== false) {

				$meta_variants = [
					'pickup_point_id',
					'_branch_id',
					'branch_id',
					'pickup_id',
					'delivery_point_id'
				];

				foreach ($meta_variants as $meta_key) {
					$pickup_id = $shipping_method->get_meta($meta_key);
					if (!empty($pickup_id)) {
						self::$logger->info('Found pickup point via shipping method meta', [
							'order_id' => $order_id,
							'method_id' => $method_id,
							'sm_method_id' => $sm_method_id,
							'sm_full_id' => $sm_full_id,
							'meta_key' => $meta_key,
							'pickup_point_id' => $pickup_id
						], 'order_sync');
						return $pickup_id;
					}
				}
			}
		}

		// 5. NOVÉ: Pokusit se najít podle session dat (pro WooCommerce Blocks)
		$chosen_methods = WC()->session->get('chosen_shipping_methods');
		if (!empty($chosen_methods)) {
			$chosen_method = $chosen_methods[0];

			// Různé kombinace session klíčů
			$session_keys = [
				'wse_pickup_point_' . $method_id,
				'wse_pickup_point_' . $chosen_method,
				'wse_current_pickup_point',
				'pickup_point_id',
				'branch_id'
			];

			// Přidat specifické klíče podle method_id
			if (strpos($method_id, 'zasilkovna') !== false) {
				$session_keys[] = 'zasilkovna_point_id';
				$session_keys[] = 'packeta_point_id';
			}

			if (strpos($method_id, 'ppl') !== false) {
				$session_keys[] = 'ppl_point_id';
				$session_keys[] = 'parcelshop_id';
			}

			foreach ($session_keys as $session_key) {
				$session_data = WC()->session->get($session_key);

				if (is_array($session_data) && !empty($session_data['pickup_id'])) {
					self::$logger->info('Found pickup point via session data', [
						'order_id' => $order_id,
						'method_id' => $method_id,
						'session_key' => $session_key,
						'pickup_point_id' => $session_data['pickup_id']
					], 'order_sync');
					return $session_data['pickup_id'];
				} elseif (is_string($session_data) && !empty($session_data)) {
					self::$logger->info('Found pickup point via session string', [
						'order_id' => $order_id,
						'method_id' => $method_id,
						'session_key' => $session_key,
						'pickup_point_id' => $session_data
					], 'order_sync');
					return $session_data;
				}
			}
		}

		// 6. Fallback - pokusit se najít podle base method ID
		$base_method_id = explode(':', $method_id)[0];
		if ($base_method_id !== $method_id) {
			self::$logger->debug('Trying fallback with base method ID', [
				'order_id' => $order_id,
				'original_method_id' => $method_id,
				'base_method_id' => $base_method_id
			], 'order_sync');

			$fallback_result = self::get_pickup_point_for_order($order, $base_method_id);
			if (!empty($fallback_result)) {
				return $fallback_result;
			}
		}

		// 7. DEBUG - Vypsat všechna order meta data s pickup/branch
		$all_meta = get_post_meta($order_id);
		$pickup_related_meta = [];
		foreach ($all_meta as $key => $value) {
			if (strpos($key, 'pickup') !== false || strpos($key, 'branch') !== false) {
				$pickup_related_meta[$key] = $value[0] ?? $value;
			}
		}

		// Debug session data
		$session_debug = [];
		if (WC()->session) {
			foreach (['wse_pickup_point_', 'pickup_point_', 'branch_'] as $prefix) {
				$session_keys = array_filter(array_keys(WC()->session->get_session_data()), function($key) use ($prefix) {
					return strpos($key, $prefix) === 0;
				});

				foreach ($session_keys as $key) {
					$session_debug[$key] = WC()->session->get($key);
				}
			}
		}

		self::$logger->warning('No pickup point found for order', [
			'order_id' => $order_id,
			'method_id' => $method_id,
			'all_pickup_meta' => $pickup_related_meta,
			'session_pickup_data' => $session_debug,
			'chosen_methods' => $chosen_methods ?? [],
			'shipping_methods' => array_map(function($sm) {
				return [
					'method_id' => $sm->get_method_id(),
					'instance_id' => $sm->get_instance_id(),
					'full_id' => $sm->get_method_id() . ':' . $sm->get_instance_id(),
					'name' => $sm->get_name()
				];
			}, $order->get_shipping_methods())
		], 'order_sync');

		return null;
	}
	/**
	 * Validace pickup point podle formátu dopravce
	 */
	private static function validate_pickup_point_for_method($method_id, $carrier_id, $pickup_point_id)
	{
		if (empty($pickup_point_id)) {
			return false;
		}

		// Validace podle carrieru
		switch ($carrier_id) {
			case 'ZASILKOVNA':
				// Zásilkovna ID jsou obvykle číselná (4-6 číslic)
				if (is_numeric($pickup_point_id) && strlen($pickup_point_id) >= 3 && strlen($pickup_point_id) <= 6) {
					return true;
				}
				// Některá místa mohou mít alfanumerické ID
				if (preg_match('/^[A-Z0-9]{3,8}$/i', $pickup_point_id)) {
					return true;
				}
				break;

			case 'PPL':
				// PPL ParcelShop ID mají specifický formát
				if (preg_match('/^[A-Z0-9_-]{3,20}$/i', $pickup_point_id)) {
					return true;
				}
				break;

			case 'DPD-CZ':
				// DPD pickup points
				if (preg_match('/^[A-Z0-9]{3,15}$/i', $pickup_point_id)) {
					return true;
				}
				break;

			default:
				// Pro ostatní dopravce základní kontrola
				return !empty($pickup_point_id) && strlen($pickup_point_id) >= 2;
		}

		return false;
	}
	
    /**
     * Příprava položek objednávky pro eLogist - OPRAVENO
     */
    private static function prepare_order_items($order)
    {
        $order_items = [];
        $item_index = 0;

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }

            // Získat XML variant ID nebo product code
            $xml_variant_id = get_post_meta($product->get_id(), '_xml_variant_id', true);
            $xml_code = get_post_meta($product->get_id(), '_xml_variant_code', true);
            $xml_guid = get_post_meta($product->get_id(), '_xml_guid', true);
            
            // Pro varianty použít variant code, jinak product SKU nebo XML kód
            $product_id = $xml_variant_id ?: ($xml_code ?: $product->get_sku());
            
            if (empty($product_id)) {
                $product_id = 'wc-' . $product->get_id(); // Fallback
            }

            $order_item = new stdClass();
            
            // Pokud máme XML kód, použít jen productId a quantity
            if ($xml_variant_id || $xml_code) {
                $order_item->productId = $product_id;
                $order_item->quantity = intval($item->get_quantity());
            } else {
                // Pokud nemáme XML kód, přidat productSheet s detaily
                $order_item->productSheet = new stdClass();
                $order_item->productSheet->productId = $product_id;
                $order_item->productSheet->name = $product->get_name();
                
                $description = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
                if (!empty($description)) {
                    $order_item->productSheet->description = mb_substr($description, 0, 500); // Max 500 znaků
                }
                
                $order_item->productSheet->quantityUnit = 'PC';
                
                if ($product->get_sku()) {
                    $order_item->productSheet->productNumber = $product->get_sku();
                }
                
                // Přidat vendor/manufacturer pokud máme
                $manufacturer = get_post_meta($product->get_id(), '_xml_manufacturer', true);
                if (!empty($manufacturer)) {
                    $order_item->productSheet->vendor = $manufacturer;
                }
                
                $order_item->quantity = intval($item->get_quantity());
            }

            $order_items[$item_index] = $order_item;
            $item_index++;
        }

        return $order_items;
    }

    /**
     * Formátování telefonního čísla do mezinárodního formátu
     */
    private static function format_phone_number($phone)
    {
        if (empty($phone)) {
            return '';
        }

        // Odstranit všechny nečíslice kromě +
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Pokud nezačíná +, přidat +420 pro České čísla
        if (!str_starts_with($phone, '+')) {
            if (str_starts_with($phone, '420')) {
                $phone = '+' . $phone;
            } elseif (strlen($phone) === 9) {
                $phone = '+420' . $phone;
            } else {
                $phone = '+420' . $phone;
            }
        }

        return $phone;
    }

    /**
     * Kontrola stavů objednávek v eLogist
     */
    public static function check_elogist_order_status()
    {
        if (!self::$logger) {
            self::init();
        }

        self::$logger->info('Starting eLogist order status check', [], 'order_sync');

        // Získat čas poslední kontroly
        $last_check = get_option('wse_last_status_check', date('c', strtotime('-1 hour')));
        
        try {
            // Získat změny od posledního checkování
            $response = self::$elogist_api->get_order_status_news($last_check);
            
            if (!$response || !isset($response->deliveryOrderStatus)) {
                self::$logger->info('No order status updates from eLogist', [
                    'last_check' => $last_check
                ], 'order_sync');
                update_option('wse_last_status_check', date('c'));
                return;
            }

            // Normalizovat response (může být array nebo jednotlivý objekt)
            $statuses = is_array($response->deliveryOrderStatus) ? 
                       $response->deliveryOrderStatus : 
                       [$response->deliveryOrderStatus];

            $updated_count = 0;
            
            foreach ($statuses as $status) {
                try {
                    $updated = self::update_woocommerce_order_status($status);
                    if ($updated) {
                        $updated_count++;
                    }
                } catch (Exception $e) {
                    self::$logger->error('Failed to update order status', [
                        'elogist_order_id' => $status->orderId ?? 'unknown',
                        'error' => $e->getMessage()
                    ], 'order_sync');
                }
            }

            self::$logger->info('eLogist order status check completed', [
                'total_updates' => count($statuses),
                'successful_updates' => $updated_count,
                'last_check' => $last_check
            ], 'order_sync');

            // Uložit čas kontroly
            update_option('wse_last_status_check', date('c'));

        } catch (Exception $e) {
            self::$logger->error('eLogist order status check failed', [
                'error' => $e->getMessage(),
                'last_check' => $last_check
            ], 'order_sync');
        }
    }

    /**
     * Aktualizace stavu WooCommerce objednávky podle eLogist stavu
     */
    private static function update_woocommerce_order_status($elogist_status)
    {
        $order_id = $elogist_status->orderId ?? null;
        if (!$order_id) {
            return false;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            self::$logger->warning('Order not found for eLogist status update', [
                'order_id' => $order_id
            ], 'order_sync');
            return false;
        }

        // Mapování stavů z eLogist do WooCommerce
        $status_mapping = [
            'NEW' => 'awaiting-shipment',
            'SUSPENDED' => 'on-hold',
            'CANCELLED' => 'cancelled',
            'SHIPPED' => 'shipped',
            'DELIVERED' => 'completed',
            'ABANDONED' => 'failed'
        ];

        $elogist_status_code = $elogist_status->status ?? 'NEW';
        $new_wc_status = $status_mapping[$elogist_status_code] ?? null;

        if (!$new_wc_status) {
            self::$logger->warning('Unknown eLogist status', [
                'elogist_status' => $elogist_status_code,
                'order_id' => $order_id
            ], 'order_sync');
            return false;
        }

        $current_status = $order->get_status();
        
        // Aktualizovat pouze pokud se stav změnil
        if ($current_status !== $new_wc_status) {
            
            // Přidat poznámku s detaily
            $status_note = sprintf(
                __('Status aktualizován z eLogist: %s', 'wc-shoptet-elogist'),
                $elogist_status_code
            );
            
            // Přidat tracking číslo pokud je k dispozici
            if (isset($elogist_status->trackingNo) && !empty($elogist_status->trackingNo)) {
                $tracking_number = $elogist_status->trackingNo;
                $order->update_meta_data('_tracking_number', $tracking_number);
                $status_note .= sprintf(' | Tracking: %s', $tracking_number);
                
                // Uložit tracking do sync tabulky
                self::update_order_tracking($order_id, $tracking_number);
            }
            
            // Uložit eLogist status
            $order->update_meta_data('_elogist_status', $elogist_status_code);
            $order->update_meta_data('_elogist_last_update', current_time('mysql'));
            $order->save();
            
            // Aktualizovat stav objednávky
            $order->update_status($new_wc_status, $status_note);
            
            self::$logger->info('Order status updated', [
                'order_id' => $order_id,
                'old_status' => $current_status,
                'new_status' => $new_wc_status,
                'elogist_status' => $elogist_status_code
            ], 'order_sync');
            
            return true;
        }

        return false;
    }
	
	/**
	 * Rozšířené logování pickup point informací
	 */
	private static function log_pickup_point_info($order)
	{
		$shipping_methods = $order->get_shipping_methods();

		foreach ($shipping_methods as $shipping_method) {
			$method_id = $shipping_method->get_method_id();
			$pickup_point_id = self::get_pickup_point_for_order($order, $method_id);
			$pickup_point_name = get_post_meta($order->get_id(), '_wse_pickup_point_name_' . $method_id, true);

			if (!empty($pickup_point_id)) {
				self::$logger->info('Order pickup point details', [
					'order_id' => $order->get_id(),
					'method_id' => $method_id,
					'pickup_point_id' => $pickup_point_id,
					'pickup_point_name' => $pickup_point_name,
					'shipping_method_name' => $shipping_method->get_name()
				], 'order_sync');
			} else {
				// Log i když pickup point chybí (může být důležité pro debug)
				self::$logger->debug('No pickup point found for shipping method', [
					'order_id' => $order->get_id(),
					'method_id' => $method_id,
					'shipping_method_name' => $shipping_method->get_name()
				], 'order_sync');
			}
		}
	}

    /**
     * Aktualizace stavu z webhook
     */
    public static function update_order_status_from_webhook($webhook_data)
    {
        if (!self::$logger) {
            self::init();
        }

        $order_id = $webhook_data['orderId'] ?? null;
        $elogist_status = $webhook_data['status'] ?? null;
        
        if (!$order_id || !$elogist_status) {
            return false;
        }

        // Vytvořit mock eLogist status objekt
        $status_object = (object) [
            'orderId' => $order_id,
            'status' => $elogist_status,
            'trackingNo' => $webhook_data['trackingNo'] ?? null
        ];

        return self::update_woocommerce_order_status($status_object);
    }

    /**
     * Uložení mapování objednávky
     */
    private static function save_order_mapping($wc_order_id, $elogist_order_id, $elogist_sys_order_id = null)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wse_order_sync';
        
        return $wpdb->replace($table_name, [
            'wc_order_id' => $wc_order_id,
            'elogist_order_id' => $elogist_order_id,
            'elogist_sys_order_id' => $elogist_sys_order_id,
            'last_status_check' => current_time('mysql'),
            'current_status' => 'NEW'
        ]);
    }

    /**
     * Aktualizace tracking čísla
     */
    private static function update_order_tracking($wc_order_id, $tracking_number)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wse_order_sync';
        
        return $wpdb->update(
            $table_name,
            ['tracking_number' => $tracking_number],
            ['wc_order_id' => $wc_order_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Získání synchronizovaných objednávek
     */
    public static function get_synced_orders($limit = 50)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wse_order_sync';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT os.*, p.post_date, p.post_status 
             FROM {$table_name} os 
             LEFT JOIN {$wpdb->posts} p ON os.wc_order_id = p.ID 
             ORDER BY os.last_status_check DESC 
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Test připojení eLogist API
     */
    public static function test_elogist_connection()
    {
        if (!self::$elogist_api) {
            self::$elogist_api = new WSE_ELogist_API();
        }
        
        return self::$elogist_api->test_connection();
    }
}