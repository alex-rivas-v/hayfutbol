<?php
namespace HayFutbol;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages the WP Cron schedule for the block check.
 * The interval is read from the hayfutbol_check_interval option (minutes).
 */
class Cron {

    const HOOK      = 'hayfutbol_check';
    const INTERVALS = array( 5, 10, 15, 30, 60 );

    /** @var BlockCheck */
    private $block_check;

    public function __construct( BlockCheck $block_check ) {
        $this->block_check = $block_check;
    }

    public function register(): void {
        add_filter( 'cron_schedules',                          array( $this, 'add_intervals' ) );
        add_action( self::HOOK,                                array( $this->block_check, 'run' ) );
        add_action( 'update_option_hayfutbol_check_interval',  array( $this, 'reschedule' ) );
    }

    public function add_intervals( array $schedules ): array {
        $minutes = (int) get_option( 'hayfutbol_check_interval', 5 );
        if ( ! in_array( $minutes, self::INTERVALS, true ) ) {
            $minutes = 5;
        }
        $schedules[ $this->schedule_name( $minutes ) ] = array(
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf( 'Hay Futbol: cada %d minutos', $minutes ),
        );
        return $schedules;
    }

    public function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), $this->current_schedule_name(), self::HOOK );
        }
    }

    public function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public function reschedule(): void {
        $this->unschedule();
        $this->schedule();
    }

    public function current_schedule_name(): string {
        $minutes = (int) get_option( 'hayfutbol_check_interval', 5 );
        if ( ! in_array( $minutes, self::INTERVALS, true ) ) {
            $minutes = 5;
        }
        return $this->schedule_name( $minutes );
    }

    private function schedule_name( int $minutes ): string {
        return 'hayfutbol_' . $minutes . 'min';
    }
}
