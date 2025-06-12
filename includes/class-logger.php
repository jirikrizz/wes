<?php
/**
 * WSE Logger Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSE_Logger {
    
    private static $instance = null;
    private $log_table;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'wse_logs';
    }
    
    /**
     * Log zprávy do vlastní tabulky
     */
    public function log($level, $message, $context = [], $source = 'general') {
        global $wpdb;
        
        $wpdb->insert($this->log_table, [
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context),
            'source' => $source,
            'timestamp' => current_time('mysql')
        ]);
        
        // Také do WooCommerce logu pro debugování
        if (function_exists('wc_get_logger')) {
            $wc_logger = wc_get_logger();
            $wc_logger->log($level, $message, ['source' => 'wse-' . $source]);
        }
        
        // Pro kritické chyby také do error_log
        if ($level === 'error') {
            error_log("WSE {$level}: {$message} in {$source}");
        }
    }
    
    public function error($message, $context = [], $source = 'general') {
        $this->log('error', $message, $context, $source);
    }
    
    public function warning($message, $context = [], $source = 'general') {
        $this->log('warning', $message, $context, $source);
    }
    
    public function info($message, $context = [], $source = 'general') {
        $this->log('info', $message, $context, $source);
    }
    
    public function debug($message, $context = [], $source = 'general') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log('debug', $message, $context, $source);
        }
    }
    
    /**
     * Získání logů z databáze
     */
    public function get_logs($level = null, $source = null, $limit = 100) {
        global $wpdb;
        
        $where_conditions = ['1=1'];
        $values = [];
        
        if ($level) {
            $where_conditions[] = 'level = %s';
            $values[] = $level;
        }
        
        if ($source) {
            $where_conditions[] = 'source = %s';
            $values[] = $source;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $values[] = intval($limit);
        
        $query = "SELECT * FROM {$this->log_table} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Vyčištění starých logů
     */
    public function cleanup_old_logs($days = 30) {
        global $wpdb;
        
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->log_table} WHERE timestamp < %s",
            $date_threshold
        ));
        
        $this->info("Vyčištěno {$deleted} starých logů", ['days' => $days], 'cleanup');
        
        return $deleted;
    }
    
    /**
     * Vyčištění všech logů
     */
    public function clear_all_logs() {
        global $wpdb;
        return $wpdb->query("DELETE FROM {$this->log_table}");
    }
    
    /**
     * Získání statistik logů
     */
    public function get_log_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_results("
            SELECT level, COUNT(*) as count 
            FROM {$this->log_table} 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY level
        ");
        
        $result = [
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'debug' => 0
        ];
        
        foreach ($stats as $stat) {
            $result[$stat->level] = (int)$stat->count;
        }
        
        return $result;
    }
}