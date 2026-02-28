<?php
namespace HayFutbol;

if ( ! defined( 'ABSPATH' ) ) exit;

class HayFutbol {

    protected static $_instance = null;

    public $version;
    public $plugin_url;
    public $plugin_path;
    public $main_plugin_file_name;

    /** @var BlockCheck */
    public $block_check;

    /** @var Cron */
    public $cron;

    /** @var PingerClient */
    public $pinger;

    private function __construct( $main_plugin_file_name, $args = array() ) {
        $this->main_plugin_file_name = $main_plugin_file_name;
        $this->plugin_url            = trailingslashit( plugins_url( '', $this->main_plugin_file_name ) );
        $this->plugin_path           = trailingslashit( dirname( $this->main_plugin_file_name ) );
        $this->version               = isset( $args['version'] ) ? $args['version'] : null;

        $this->initialize_global_objects();

        register_deactivation_hook( $this->main_plugin_file_name, array( $this, 'deactivate' ) );

        do_action( 'hayfutbol_loaded', $this );
    }

    public static function instance( $args = array() ) {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self( HAYFUTBOL_PLUGIN_FILE, $args );
        }
        return self::$_instance;
    }

    protected function initialize_global_objects() {
        $this->block_check = new BlockCheck();
        $this->cron        = new Cron( $this->block_check );
        $this->cron->register();

        $this->pinger = new PingerClient();
        $this->pinger->register_hooks();

        if ( is_admin() ) {
            $settings = new Admin\Settings();
            $settings->register();
            add_action( 'admin_notices', array( $this, 'security_notices' ) );
        }
    }

    public function security_notices(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! extension_loaded( 'openssl' ) ) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Hay Futbol:</strong> ';
            echo esc_html__( 'The openssl PHP extension is required but not loaded. Encryption will not work.', 'hayfutbol' );
            echo '</p></div>';
            return;
        }

        if ( get_option( 'hayfutbol_cf_api_token', '' ) && ! Encryption::key_is_secure() ) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>Hay Futbol:</strong> ';
            echo esc_html__( 'The encryption key is stored in the database instead of wp-config.php. This is less secure. Make wp-config.php writable, deactivate and reactivate the plugin.', 'hayfutbol' );
            echo '</p></div>';
        }
    }

    public function activate() {
        Encryption::generate_key();
        $this->cron->schedule();
        $this->pinger->register();
    }

    public function deactivate() {
        $this->cron->unschedule();
        $this->pinger->unregister();
    }

    public function get_version() {
        return $this->version;
    }

    public function get_plugin_url() {
        return $this->plugin_url;
    }

    public function get_plugin_path() {
        return $this->plugin_path;
    }

    public function __clone() {
        _doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'hayfutbol' ), '1.0.0' );
    }

    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'hayfutbol' ), '1.0.0' );
    }
}
