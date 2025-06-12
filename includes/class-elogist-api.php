<?php
/**
 * eLogist API Client - OPRAVENÁ VERZE
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_ELogist_API
{
    private $wsdl_url;
    private $username;
    private $password;
    private $project_id;
    private $soap_client;
    private $logger;

    public function __construct()
    {
        $this->wsdl_url = get_option('wse_elogist_wsdl_url', 'https://elogist-demo.shipmall.cz/api/soap?wsdl');
        $this->username = get_option('wse_elogist_username');
        $this->password = get_option('wse_elogist_password');
        $this->project_id = get_option('wse_elogist_project_id');
        $this->logger = WSE_Logger::get_instance();
    }

    /**
     * Získání SOAP klienta - OPRAVENO
     */
    private function get_soap_client()
    {
        if (!$this->soap_client) {
            try {
                // Kontrola přihlašovacích údajů
                if (empty($this->username) || empty($this->password)) {
                    throw new Exception('eLogist credentials not configured');
                }

                // Nastavení pro SOAP client podle demo kódu
                $soap_options = [
                    'location' => str_replace('?wsdl', '', $this->wsdl_url), // URL bez ?wsdl
                    'soap_version' => SOAP_1_2,
                    'login' => $this->username,
                    'password' => $this->password,
                    'encoding' => 'UTF-8',
                    'trace' => true,
                    'exceptions' => true,
                    'connection_timeout' => 120,
                    'cache_wsdl' => WSDL_CACHE_BOTH,
                    'user_agent' => 'WSE Integration/' . WSE_VERSION
                ];

                $this->soap_client = new SoapClient($this->wsdl_url, $soap_options);
                
                $this->logger->debug('eLogist SOAP client created', [
                    'wsdl_url' => $this->wsdl_url,
                    'username' => $this->username
                ], 'elogist_api');
                
            } catch (SoapFault $e) {
                $this->logger->error('eLogist SOAP client creation failed', [
                    'error' => $e->getMessage(),
                    'faultcode' => $e->faultcode,
                    'wsdl_url' => $this->wsdl_url
                ], 'elogist_api');
                throw $e;
            } catch (Exception $e) {
                $this->logger->error('eLogist SOAP client creation error', [
                    'error' => $e->getMessage()
                ], 'elogist_api');
                throw $e;
            }
        }
        
        return $this->soap_client;
    }

    /**
     * Získání seznamu dopravců - OPRAVENO
     */
    public function get_carriers()
    {
        try {
            $client = $this->get_soap_client();
            
            // Podle demo kódu, CarrierListGet nepotřebuje parametry
            $response = $client->CarrierListGet();
            
            if ($response && isset($response->result) && $response->result->code == 1000) {
                $this->logger->info('eLogist carriers retrieved successfully', [
                    'carrier_count' => isset($response->carrier) ? (is_array($response->carrier) ? count($response->carrier) : 1) : 0
                ], 'elogist_api');
                
                return $response;
            } else {
                $error_code = isset($response->result) ? $response->result->code : 'unknown';
                $error_desc = isset($response->result) ? $response->result->description : 'unknown error';
                
                $this->logger->error('eLogist CarrierListGet failed', [
                    'code' => $error_code,
                    'description' => $error_desc
                ], 'elogist_api');
                
                return false;
            }
            
        } catch (SoapFault $e) {
            $this->logger->error('eLogist CarrierListGet SOAP fault', [
                'error' => $e->getMessage(),
                'faultcode' => $e->faultcode,
                'faultstring' => $e->faultstring
            ], 'elogist_api');
            return false;
        } catch (Exception $e) {
            $this->logger->error('eLogist CarrierListGet error', [
                'error' => $e->getMessage()
            ], 'elogist_api');
            return false;
        }
    }

    /**
     * Odeslání objednávky doručení - OPRAVENO
     */
    public function send_delivery_order($order_data)
    {
        try {
            $client = $this->get_soap_client();
            
            // Debug - logovat odesílaná data
            $this->logger->debug('Sending DeliveryOrder to eLogist', [
                'order_id' => isset($order_data->orderId) ? $order_data->orderId : 'unknown',
                'data_sample' => [
                    'projectId' => isset($order_data->projectId) ? $order_data->projectId : null,
                    'recipient_name' => isset($order_data->recipient->name) ? $order_data->recipient->name : null,
                    'carrier' => isset($order_data->shipping->carrierId) ? $order_data->shipping->carrierId : null,
                    'items_count' => isset($order_data->orderItems->orderItem) ? count($order_data->orderItems->orderItem) : 0
                ]
            ], 'elogist_api');
            
            // Volání SOAP metody
            $response = $client->DeliveryOrder($order_data);
            
            // Debug - logovat raw response
            if (method_exists($client, '__getLastResponse')) {
                $this->logger->debug('eLogist raw response', [
                    'response' => substr($client->__getLastResponse(), 0, 1000) // První 1000 znaků
                ], 'elogist_api');
            }
            
            if ($response && isset($response->result) && $response->result->code == 1000) {
                $this->logger->info('eLogist delivery order sent successfully', [
                    'order_id' => $order_data->orderId,
                    'sys_order_id' => isset($response->deliveryOrderStatus->sysOrderId) ? $response->deliveryOrderStatus->sysOrderId : 'unknown',
                    'status' => isset($response->deliveryOrderStatus->status) ? $response->deliveryOrderStatus->status : 'unknown'
                ], 'elogist_api');
                
                return $response;
            } else {
                $error_code = isset($response->result) ? $response->result->code : 'unknown';
                $error_desc = isset($response->result) ? $response->result->description : 'unknown error';
                
                $this->logger->error('eLogist DeliveryOrder failed', [
                    'order_id' => $order_data->orderId,
                    'code' => $error_code,
                    'description' => $error_desc,
                    'full_response' => json_encode($response)
                ], 'elogist_api');
                
                // Vrátit response i při chybě pro analýzu
                return $response;
            }
            
        } catch (SoapFault $e) {
            // Detailní logování SOAP chyby
            $error_detail = [
                'order_id' => isset($order_data->orderId) ? $order_data->orderId : 'unknown',
                'error' => $e->getMessage(),
                'faultcode' => $e->faultcode,
                'faultstring' => $e->faultstring
            ];
            
            // Pokusit se získat více informací z SOAP klienta
            if (method_exists($client, '__getLastRequest')) {
                $error_detail['last_request'] = substr($client->__getLastRequest(), 0, 2000);
            }
            if (method_exists($client, '__getLastResponse')) {
                $error_detail['last_response'] = substr($client->__getLastResponse(), 0, 2000);
            }
            
            $this->logger->error('eLogist DeliveryOrder SOAP fault', $error_detail, 'elogist_api');
            throw $e;
        } catch (Exception $e) {
            $this->logger->error('eLogist DeliveryOrder error', [
                'order_id' => isset($order_data->orderId) ? $order_data->orderId : 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'elogist_api');
            throw $e;
        }
    }

    /**
     * Získání stavu objednávky - OPRAVENO
     */
    public function get_order_status($order_id)
    {
        try {
            $client = $this->get_soap_client();
            
            // Vytvořit parametr jako objekt
            $param = new stdClass();
            $param->projectId = $this->project_id;
            $param->orderId = $order_id;
            
            $response = $client->DeliveryOrderStatusGet($param);
            
            if ($response && isset($response->result) && $response->result->code == 1000) {
                $this->logger->debug('eLogist order status retrieved', [
                    'order_id' => $order_id,
                    'status' => isset($response->deliveryOrderStatus->status) ? $response->deliveryOrderStatus->status : 'unknown'
                ], 'elogist_api');
                
                return $response;
            } else {
                $this->logger->warning('eLogist DeliveryOrderStatusGet failed', [
                    'order_id' => $order_id,
                    'code' => isset($response->result) ? $response->result->code : 'unknown'
                ], 'elogist_api');
                
                return false;
            }
            
        } catch (SoapFault $e) {
            $this->logger->error('eLogist DeliveryOrderStatusGet SOAP fault', [
                'order_id' => $order_id,
                'error' => $e->getMessage(),
                'faultcode' => $e->faultcode
            ], 'elogist_api');
            return false;
        } catch (Exception $e) {
            $this->logger->error('eLogist DeliveryOrderStatusGet error', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ], 'elogist_api');
            return false;
        }
    }

    /**
     * Získání nových změn stavů objednávek - OPRAVENO
     */
    public function get_order_status_news($after_datetime)
    {
        try {
            $client = $this->get_soap_client();
            
            // Vytvořit parametr jako objekt
            $param = new stdClass();
            if (!empty($this->project_id)) {
                $param->projectId = $this->project_id;
            }
            $param->afterDateTime = $after_datetime;
            
            $response = $client->DeliveryOrderStatusGetNews($param);
            
            if ($response && isset($response->result) && $response->result->code == 1000) {
                $status_count = 0;
                if (isset($response->deliveryOrderStatus)) {
                    $status_count = is_array($response->deliveryOrderStatus) ? 
                                  count($response->deliveryOrderStatus) : 1;
                }
                
                $this->logger->info('eLogist order status news retrieved', [
                    'after_datetime' => $after_datetime,
                    'status_count' => $status_count
                ], 'elogist_api');
                
                return $response;
            } else {
                $this->logger->info('No order status updates from eLogist', [
                    'after_datetime' => $after_datetime,
                    'code' => isset($response->result) ? $response->result->code : 'unknown'
                ], 'elogist_api');
                
                return false;
            }
            
        } catch (SoapFault $e) {
            $this->logger->error('eLogist DeliveryOrderStatusGetNews SOAP fault', [
                'after_datetime' => $after_datetime,
                'error' => $e->getMessage(),
                'faultcode' => $e->faultcode
            ], 'elogist_api');
            return false;
        } catch (Exception $e) {
            $this->logger->error('eLogist DeliveryOrderStatusGetNews error', [
                'after_datetime' => $after_datetime,
                'error' => $e->getMessage()
            ], 'elogist_api');
            return false;
        }
    }

    /**
     * Test připojení k API - OPRAVENO
     */
    public function test_connection()
    {
        try {
            // Nejprve zkontrolovat, že máme přihlašovací údaje
            if (empty($this->username) || empty($this->password) || empty($this->project_id)) {
                $this->logger->error('eLogist API credentials missing', [
                    'has_username' => !empty($this->username),
                    'has_password' => !empty($this->password),
                    'has_project_id' => !empty($this->project_id)
                ], 'elogist_api');
                return false;
            }

            // Pokusit se získat seznam dopravců jako test
            $carriers = $this->get_carriers();
            $success = $carriers !== false && isset($carriers->carrier);
            
            if ($success) {
                $this->logger->info('eLogist API connection test successful', [
                    'carriers_found' => isset($carriers->carrier) ? (is_array($carriers->carrier) ? count($carriers->carrier) : 1) : 0
                ], 'elogist_api');
            } else {
                $this->logger->error('eLogist API connection test failed', [], 'elogist_api');
            }
            
            return $success;
            
        } catch (Exception $e) {
            $this->logger->error('eLogist API connection test error', [
                'error' => $e->getMessage()
            ], 'elogist_api');
            return false;
        }
    }

    /**
     * Mapování eLogist error kódů na lidsky čitelné zprávy
     */
    public function get_error_message($error_code)
    {
        $error_messages = [
            1001 => 'Neznámé ID projektu - zkontrolujte Project ID v nastavení',
            1002 => 'Neznámý výrobek - produkt není v eLogist systému',
            1004 => 'Neexistující číslo objednávky',
            1005 => 'Duplicitní číslo objednávky - objednávka s tímto číslem již existuje',
            1013 => 'Neznámý způsob dopravy - zkontrolujte ID dopravce',
            1014 => 'Neplatné PSČ',
            1017 => 'Neznámá měna',
            1018 => 'Dopravce nelze použít v cílové zemi',
            1020 => 'Chybí povinné kontaktní údaje (jméno, adresa, telefon nebo email)',
            1028 => 'Dopravce nepodporuje dobírku v cílové zemi',
            1030 => 'Chybná částka dobírky',
            1035 => 'Není uveden telefon příjemce',
            1036 => 'Neplatné telefonní číslo',
            1037 => 'Není uvedena e-mailová adresa',
            1038 => 'Chybí část adresy příjemce',
            1040 => 'Neplatný formát data',
            1041 => 'Chybí položky objednávky',
            1042 => 'Neplatná struktura dat objednávky',
            1050 => 'Chyba autentizace - zkontrolujte přihlašovací údaje',
            2000 => 'Interní chyba eLogist systému - zkuste to později'
        ];
        
        return $error_messages[$error_code] ?? "Neznámá chyba (kód: {$error_code})";
    }

    /**
     * Debug metoda pro získání posledního SOAP požadavku
     */
    public function get_last_request()
    {
        if ($this->soap_client && method_exists($this->soap_client, '__getLastRequest')) {
            return $this->soap_client->__getLastRequest();
        }
        return null;
    }

    /**
     * Debug metoda pro získání poslední SOAP odpovědi
     */
    public function get_last_response()
    {
        if ($this->soap_client && method_exists($this->soap_client, '__getLastResponse')) {
            return $this->soap_client->__getLastResponse();
        }
        return null;
    }
}