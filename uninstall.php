<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$options = array(
    'hayfutbol_cf_api_token',
    'hayfutbol_cf_zone_id',
    'hayfutbol_cf_record_id',
    'hayfutbol_check_interval',
    'hayfutbol_notification_email',
    'hayfutbol_encryption_key',
    'hayfutbol_last_check',
    'hayfutbol_last_check_ip',
    'hayfutbol_last_check_blocked',
    'hayfutbol_hay_partido',
    'hayfutbol_proxy_paused',
    'hayfutbol_last_cf_error',
    'hayfutbol_ping_token',
    'hayfutbol_pinger_registered',
    'hayfutbol_pinger_last_error',
    'hayfutbol_verify_debug',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

$timestamp = wp_next_scheduled( 'hayfutbol_check' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'hayfutbol_check' );
}

// Remove the encryption key constant from wp-config.php when possible.
$wpconfig = ABSPATH . 'wp-config.php';
if ( ! file_exists( $wpconfig ) ) {
    $wpconfig = dirname( ABSPATH ) . '/wp-config.php';
}

if ( file_exists( $wpconfig ) && is_writable( $wpconfig ) ) {
    $contents = file_get_contents( $wpconfig );
    if ( false !== $contents ) {
        $cleaned = preg_replace(
            '/\s*define\(\s*[\'"]HAYFUTBOL_ENCRYPTION_KEY[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);\s*\n?/',
            '',
            $contents
        );
        if ( $cleaned !== $contents ) {
            file_put_contents( $wpconfig, $cleaned, LOCK_EX );
        }
    }
}
