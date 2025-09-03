<?php
/**
 * Plugin Name: WP Travel Map
 * Plugin URI: https://anotherdayu.com/wp-travel-map
 * Description: 极简风格的旅行地图插件，记录你去过的地方
 * Version: 1.0.4
 * Author: Dayu
 * Author URI: https://anotherdayu.com/
 * License: GPL v2 or later
 * Text Domain: wp-travel-map
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_TRAVEL_MAP_VERSION', '1.0.3');
define('WP_TRAVEL_MAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_TRAVEL_MAP_PLUGIN_URL', plugin_dir_url(__FILE__));

class WPTravelMap {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wptm_save_location', array($this, 'ajax_save_location'));
        add_action('wp_ajax_wptm_delete_location', array($this, 'ajax_delete_location'));
        add_action('wp_ajax_wptm_get_locations', array($this, 'ajax_get_locations'));
        add_action('wp_ajax_nopriv_wptm_get_locations', array($this, 'ajax_get_locations'));
        add_action('wp_ajax_wptm_export_excel', array($this, 'ajax_export_excel'));
        add_action('wp_ajax_wptm_import_excel', array($this, 'ajax_import_excel'));
        add_action('wp_ajax_wptm_geocode_search', array($this, 'ajax_geocode_search'));
        add_action('wp_ajax_wptm_search_suggestions', array($this, 'ajax_search_suggestions'));
        add_action('wp_ajax_wptm_save_quick_token', array($this, 'ajax_save_quick_token'));
        
        add_shortcode('travel_map', array($this, 'render_map_shortcode'));
    }
    
    public function activate() {
        $this->create_database_table();
    }
    
    public function deactivate() {
    }
    
    private function create_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'travel_locations';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            description text,
            latitude decimal(10, 8) NOT NULL,
            longitude decimal(11, 8) NOT NULL,
            visit_date date,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function init() {
    }
    
    public function register_settings() {
        register_setting('wptm_settings_group', 'wptm_mapbox_token');
        register_setting('wptm_settings_group', 'wptm_default_map_style');
        register_setting('wptm_settings_group', 'wptm_map_projection');
    }
    
    public function add_admin_menu() {
        add_menu_page(
            '旅行地图',
            '旅行地图',
            'manage_options',
            'wp-travel-map',
            array($this, 'render_admin_page'),
            'dashicons-location-alt',
            30
        );
        
        add_submenu_page(
            'wp-travel-map',
            '地图设置',
            '设置',
            'manage_options',
            'wp-travel-map-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function render_admin_page() {
        include WP_TRAVEL_MAP_PLUGIN_DIR . 'admin/admin-page.php';
    }
    
    public function render_settings_page() {
        include WP_TRAVEL_MAP_PLUGIN_DIR . 'admin/settings-page.php';
    }
    
    public function enqueue_frontend_scripts() {
        if (!is_admin()) {
            wp_enqueue_style('mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css');
            wp_enqueue_script('mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js', array(), null, true);
            
            wp_enqueue_style('wp-travel-map-frontend', WP_TRAVEL_MAP_PLUGIN_URL . 'assets/css/frontend.css', array(), WP_TRAVEL_MAP_VERSION);
            wp_enqueue_script('wp-travel-map-frontend', WP_TRAVEL_MAP_PLUGIN_URL . 'assets/js/frontend.js', array('jquery', 'mapbox-gl'), WP_TRAVEL_MAP_VERSION, true);
            
            $mapbox_token = get_option('wptm_mapbox_token', '');
            $map_style = get_option('wptm_default_map_style', 'mapbox://styles/mapbox/light-v11');
            $map_projection = get_option('wptm_map_projection', 'globe');
            
            wp_localize_script('wp-travel-map-frontend', 'wptm_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wptm_nonce'),
                'mapbox_token' => $mapbox_token,
                'map_style' => $map_style,
                'map_projection' => $map_projection
            ));
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wp-travel-map') === false) {
            return;
        }
        
        wp_enqueue_style('mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css');
        wp_enqueue_script('mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js', array(), null, true);
        
        if ('toplevel_page_wp-travel-map' === $hook) {
            wp_enqueue_script('sheetjs', 'https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js', array(), null, true);
        }
        
        wp_enqueue_style('wp-travel-map-admin', WP_TRAVEL_MAP_PLUGIN_URL . 'assets/css/admin.css', array(), WP_TRAVEL_MAP_VERSION);
        wp_enqueue_script('wp-travel-map-admin', WP_TRAVEL_MAP_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'mapbox-gl'), WP_TRAVEL_MAP_VERSION, true);
        
        $mapbox_token = get_option('wptm_mapbox_token', '');
        $map_projection = get_option('wptm_map_projection', 'globe');
        
        wp_localize_script('wp-travel-map-admin', 'wptm_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wptm_admin_nonce'),
            'mapbox_token' => $mapbox_token,
            'map_projection' => $map_projection
        ));
    }
    
    public function ajax_save_location() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'travel_locations';
        
        $name = sanitize_text_field($_POST['name']);
        $description = sanitize_textarea_field($_POST['description']);
        $latitude = floatval($_POST['latitude']);
        $longitude = floatval($_POST['longitude']);
        $visit_date = sanitize_text_field($_POST['visit_date']);
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id > 0) {
            $result = $wpdb->update(
                $table_name,
                array(
                    'name' => $name,
                    'description' => $description,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'visit_date' => $visit_date
                ),
                array('id' => $id)
            );
        } else {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'name' => $name,
                    'description' => $description,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'visit_date' => $visit_date
                )
            );
        }
        
        if ($result !== false) {
            wp_send_json_success(array('message' => '保存成功'));
        } else {
            wp_send_json_error(array('message' => '保存失败'));
        }
    }
    
    public function ajax_delete_location() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'travel_locations';
        
        $id = intval($_POST['id']);
        
        $result = $wpdb->delete($table_name, array('id' => $id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => '删除成功'));
        } else {
            wp_send_json_error(array('message' => '删除失败'));
        }
    }
    
    public function ajax_get_locations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'travel_locations';
        
        $locations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY visit_date DESC");
        
        wp_send_json_success($locations);
    }
    
    public function ajax_export_excel() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'travel_locations';
        
        $locations = $wpdb->get_results("SELECT name, description, latitude, longitude, visit_date FROM $table_name ORDER BY visit_date DESC");
        
        wp_send_json_success($locations);
    }
    
    public function ajax_import_excel() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'travel_locations';
        
        $locations = json_decode(stripslashes($_POST['locations']), true);
        
        if (!is_array($locations)) {
            wp_send_json_error(array('message' => '数据格式错误'));
            return;
        }
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($locations as $location) {
            if (empty($location['name']) || !isset($location['latitude']) || !isset($location['longitude'])) {
                $error_count++;
                continue;
            }
            
            $result = $wpdb->insert(
                $table_name,
                array(
                    'name' => sanitize_text_field($location['name']),
                    'description' => sanitize_textarea_field($location['description'] ?? ''),
                    'latitude' => floatval($location['latitude']),
                    'longitude' => floatval($location['longitude']),
                    'visit_date' => sanitize_text_field($location['visit_date'] ?? '')
                )
            );
            
            if ($result !== false) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => "导入完成：成功 {$success_count} 条，失败 {$error_count} 条",
            'success_count' => $success_count,
            'error_count' => $error_count
        ));
    }
    
    public function ajax_geocode_search() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $query = sanitize_text_field($_POST['query']);
        if (empty($query)) {
            wp_send_json_error(array('message' => '搜索关键词不能为空'));
            return;
        }
        
        $mapbox_token = get_option('wptm_mapbox_token', '');
        
        if (empty($mapbox_token)) {
            wp_send_json_error(array('message' => '请先配置Mapbox访问令牌'));
            return;
        }
        
        $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' . urlencode($query) . '.json';
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WP Travel Map Plugin'
            )
        );
        
        $url .= '?access_token=' . $mapbox_token;
        $url .= '&limit=8';
        $url .= '&language=zh-Hans';
        $url .= '&autocomplete=true';
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            error_log('WP Travel Map Geocoding Error: ' . $response->get_error_message());
            wp_send_json_error(array('message' => '网络请求失败: ' . $response->get_error_message()));
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $error_message = '搜索失败';
            if ($response_code === 401) {
                $error_message = 'Mapbox访问令牌无效';
            } elseif ($response_code === 403) {
                $error_message = 'Mapbox访问被拒绝';
            }
            
            error_log('WP Travel Map Geocoding HTTP Error: ' . $response_code);
            wp_send_json_error(array('message' => $error_message, 'code' => $response_code));
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['features'])) {
            wp_send_json_error(array('message' => '响应格式错误'));
            return;
        }
        
        if (empty($data['features'])) {
            wp_send_json_error(array('message' => '未找到匹配的地点'));
            return;
        }
        
        $results = array();
        foreach ($data['features'] as $feature) {
            if (isset($feature['center']) && isset($feature['place_name'])) {
                $results[] = array(
                    'name' => $feature['place_name'],
                    'latitude' => $feature['center'][1],
                    'longitude' => $feature['center'][0],
                    'type' => isset($feature['place_type']) ? $feature['place_type'][0] : 'place'
                );
            }
        }
        
        wp_send_json_success($results);
    }
    
    public function ajax_search_suggestions() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'travel_locations';
        
        $query = sanitize_text_field($_POST['query']);
        
        if (strlen($query) < 2) {
            wp_send_json_success(array());
            return;
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT name, latitude, longitude FROM $table_name WHERE name LIKE %s ORDER BY name LIMIT 5",
            '%' . $wpdb->esc_like($query) . '%'
        ));
        
        wp_send_json_success($results);
    }
    
    public function ajax_save_quick_token() {
        check_ajax_referer('wptm_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $token = sanitize_text_field($_POST['token']);
        
        if (empty($token)) {
            wp_send_json_error(array('message' => '令牌不能为空'));
            return;
        }
        
        if (!preg_match('/^pk\./', $token)) {
            wp_send_json_error(array('message' => '令牌格式不正确，应该以"pk."开头'));
            return;
        }
        
        update_option('wptm_mapbox_token', $token);
        
        wp_send_json_success(array('message' => '令牌保存成功！现在可以开始使用地图功能了'));
    }
    
    public function render_map_shortcode($atts) {
        $atts = shortcode_atts(array(
            'height' => '500px',
            'mapbox_token' => ''
        ), $atts);
        
        if (empty($atts['mapbox_token'])) {
            $atts['mapbox_token'] = get_option('wptm_mapbox_token', '');
        }
        
        ob_start();
        ?>
        <div class="wptm-map-container" style="height: <?php echo esc_attr($atts['height']); ?>">
            <div id="wptm-frontend-map" data-mapbox-token="<?php echo esc_attr($atts['mapbox_token']); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}

WPTravelMap::get_instance();