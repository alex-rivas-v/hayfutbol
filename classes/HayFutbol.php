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
