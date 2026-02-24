<?php
/**
 * Plugin Name: Hay Fútbol
 * Description: Verifica si hay partidos de LaLiga y tu web tiene bloqueos en Cloudflare
 * Version: 1.0.0
 * Author: Alex Rivas.
 * Author URI: https://alexrivas.net
 * Text Domain: hayfutbol
 * Domain Path: /languages
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 5.0
 * Requires PHP: 7.1
 */
defined( 'ABSPATH' ) || die();

if ( ! defined( 'HAYFUTBOL_VERSION' ) ) {
    define( 'HAYFUTBOL_VERSION', '1.0.0' );
}

if ( ! defined( 'HAYFUTBOL_PLUGIN_PATH' ) ) {
    define( 'HAYFUTBOL_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'HAYFUTBOL_PLUGIN_FILE' ) ) {
    define( 'HAYFUTBOL_PLUGIN_FILE', __FILE__ );
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

if ( ! function_exists( 'HayFutbol' ) ) {
    function HayFutbol() {
        return HayFutbol\HayFutbol::instance( array( 'version' => HAYFUTBOL_VERSION ) );
    }
}

global $hayfutbol;
$hayfutbol = HayFutbol();

register_activation_hook( HAYFUTBOL_PLUGIN_FILE, 'activate_hayfutbol' );

if ( ! function_exists( 'activate_hayfutbol' ) ) {
    function activate_hayfutbol() {
        HayFutbol()->activate();
    }
}
