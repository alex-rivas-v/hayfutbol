<?php
namespace HayFutbol;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core logic: resolves the site's public IP, compares it against the
 * hayahora.futbol blocklist, and toggles the Cloudflare proxy accordingly.
 *
 *  - IP blocked + proxy active  -> disable proxy, set flag.
 *  - IP not blocked + flag set  -> re-enable proxy, clear flag.
 *  - Anything else              -> do nothing.
 */
class BlockCheck {

    const STATUS_URL         = 'https://hayahora.futbol/estado/data.json';
    const GOOGLE_DNS         = 'https://dns.google/resolve';
    const MATCH_WINDOW_HOURS = 2;

    public function run(): void {
        $hostname = parse_url( home_url(), PHP_URL_HOST );
        if ( ! $hostname ) {
            return;
        }

        $ips = $this->resolve_ips( $hostname );
        if ( empty( $ips ) ) {
            return;
        }

        $blocked_entries = $this->fetch_blocked_ips();
        if ( null === $blocked_entries ) {
            // Fetch failed; skip to avoid false positives (e.g. re-enabling proxy during an outage).
            return;
        }

        $blocked_ips = array_column( $blocked_entries, 'ip' );

        // "Hay partido" only when a block started within the last MATCH_WINDOW_HOURS hours.
        $cutoff     = time() - self::MATCH_WINDOW_HOURS * HOUR_IN_SECONDS;
        $hay_partido = ! empty( array_filter( $blocked_entries, static function ( $e ) use ( $cutoff ) {
            return $e['since'] >= $cutoff;
        } ) );

        $is_blocked = false;
        foreach ( $ips as $ip ) {
            if ( in_array( $ip, $blocked_ips, true ) ) {
                $is_blocked = true;
                break;
            }
        }

        update_option( 'hayfutbol_last_check',         current_time( 'mysql' ), false );
        update_option( 'hayfutbol_last_check_ip',      implode( ', ', $ips ), false );
        update_option( 'hayfutbol_last_check_blocked', $is_blocked ? '1' : '0', false );
        update_option( 'hayfutbol_hay_partido',        $hay_partido ? '1' : '0', false );

        $proxy_paused = '1' === get_option( 'hayfutbol_proxy_paused', '0' );

        if ( '1' !== get_option( 'hayfutbol_pinger_registered', '' ) ) {
            return;
        }

        if ( false !== get_transient( 'hayfutbol_toggle_lock' ) ) {
            return;
        }

        if ( $is_blocked && ! $proxy_paused ) {
            set_transient( 'hayfutbol_toggle_lock', 1, 30 );
            $this->toggle_proxy( false );
            delete_transient( 'hayfutbol_toggle_lock' );
        } elseif ( ! $is_blocked && $proxy_paused ) {
            set_transient( 'hayfutbol_toggle_lock', 1, 30 );
            $this->toggle_proxy( true );
            delete_transient( 'hayfutbol_toggle_lock' );
        }
    }

    /**
     * @param  string   $hostname
     * @return string[]
     */
    private function resolve_ips( string $hostname ): array {
        $url = add_query_arg(
            array( 'name' => $hostname, 'type' => 'A' ),
            self::GOOGLE_DNS
        );

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/dns-json' ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return array();
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['Answer'] ) || ! is_array( $data['Answer'] ) ) {
            return array();
        }

        $ips = array();
        foreach ( $data['Answer'] as $answer ) {
            if ( isset( $answer['type'], $answer['data'] ) && 1 === (int) $answer['type'] ) {
                $ip = trim( $answer['data'] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values( array_unique( $ips ) );
    }

    /**
     * Returns IPs whose most recent stateChange has state=true, along with
     * the Unix timestamp of that change. Returns null if the fetch failed.
     *
     * @return array<array{ip: string, since: int}>|null
     */
    private function fetch_blocked_ips(): ?array {
        $response = wp_remote_get( self::STATUS_URL, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            return null;
        }

        $blocked = array();

        foreach ( $body['data'] as $entry ) {
            if ( empty( $entry['ip'] ) || empty( $entry['stateChanges'] ) || ! is_array( $entry['stateChanges'] ) ) {
                continue;
            }

            $changes = $entry['stateChanges'];

            usort( $changes, static function ( $a, $b ) {
                return strcmp( $b['timestamp'], $a['timestamp'] );
            } );

            $latest = $changes[0];

            if ( ! empty( $latest['state'] ) ) {
                $blocked[] = array(
                    'ip'    => $entry['ip'],
                    'since' => strtotime( $latest['timestamp'] ) ?: 0,
                );
            }
        }

        return $blocked;
    }

    /**
     * On failure the flag is NOT updated so the next check can retry.
     *
     * @param bool $enable
     */
    private function toggle_proxy( bool $enable ): void {
        $token     = Encryption::decrypt( get_option( 'hayfutbol_cf_api_token', '' ) );
        $zone_id   = get_option( 'hayfutbol_cf_zone_id', '' );
        $record_id = get_option( 'hayfutbol_cf_record_id', '' );

        if ( ! $token || ! $zone_id || ! $record_id ) {
            return;
        }

        $api    = new Cloudflare\Api( $token, $zone_id );
        $result = $api->set_proxy( $record_id, $enable );

        if ( is_wp_error( $result ) ) {
            update_option( 'hayfutbol_last_cf_error', $result->get_error_message() );
            return;
        }

        if ( empty( $result['success'] ) ) {
            $errors = isset( $result['errors'] ) ? wp_json_encode( $result['errors'] ) : 'Unknown error';
            update_option( 'hayfutbol_last_cf_error', $errors );
            return;
        }

        delete_option( 'hayfutbol_last_cf_error' );
        update_option( 'hayfutbol_proxy_paused', $enable ? '0' : '1' );
        $this->notify( $enable );
    }

    private function notify( bool $enable ): void {
        $to = get_option( 'hayfutbol_notification_email', '' );
        if ( ! $to ) {
            $to = get_option( 'admin_email' );
        }
        if ( ! is_email( $to ) ) {
            return;
        }

        $site    = get_bloginfo( 'name' );
        $ip      = get_option( 'hayfutbol_last_check_ip', '?' );
        $subject = $enable
            ? sprintf( '[%s] Proxy Cloudflare reactivado', $site )
            : sprintf( '[%s] Proxy Cloudflare desactivado — posible bloqueo detectado', $site );
        $body = $enable
            ? sprintf( "El proxy de Cloudflare en %s ha sido reactivado automaticamente porque la IP ya no aparece en el listado de bloqueos.\n\nSitio: %s", $site, home_url() )
            : sprintf( "El proxy de Cloudflare en %s ha sido desactivado automaticamente porque la IP %s aparece en el listado de bloqueos.\n\nSitio: %s", $site, $ip, home_url() );

        wp_mail( $to, $subject, $body );
    }
}
