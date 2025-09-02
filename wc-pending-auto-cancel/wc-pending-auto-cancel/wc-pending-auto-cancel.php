<?php
/**
 * Plugin Name: WooCommerce Pending Auto-Cancel
 * Description: Automatically cancels unpaid orders after a configurable number of hours per status (e.g., Pending payment, On hold).
 * Version: 1.0.0
 * Author: Muhammad Ahmed
 * License: GPL-2.0-or-later
 * Text Domain: wc-pending-auto-cancel
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WCPAC_Plugin' ) ) :
final class WCPAC_Plugin {
    const OPTION_KEY = 'wcpac_options';
    const CRON_HOOK  = 'wcpac_cron_event';

    public static function instance() {
        static $inst = null;
        if ( null === $inst ) { $inst = new self(); }
        return $inst;
    }

    private function __construct() {
        // Admin
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
        add_action( 'admin_menu',  [ $this, 'add_settings_page' ] );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'settings_link' ] );

        // Cron
        add_action( self::CRON_HOOK, [ $this, 'run_cron' ] );
        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
    }

    /** Default options */
    public static function defaults() {
        return [
            'enabled'  => 1,
            'statuses' => [ 'pending', 'on-hold' ],
            'hours'    => [
                'pending' => 24,
                'on-hold' => 72,
            ],
            'add_note'   => 1,
            'order_note' => 'Order auto-cancelled after {hours} hours in status "{status}" with no payment (WCPAC).',
        ];
    }

    /** Activation: schedule cron */
    public static function activate() {
        $opts = get_option( self::OPTION_KEY, [] );
        $opts = wp_parse_args( $opts, self::defaults() );
        update_option( self::OPTION_KEY, $opts );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // schedule hourly
            wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
        }
    }

    /** Deactivation: clear cron */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /** Settings API */
    public function register_settings() {
        register_setting( self::OPTION_KEY, self::OPTION_KEY, [ $this, 'sanitize' ] );

        add_settings_section( 'wcpac_main', __( 'Auto-Cancel Settings', 'wc-pending-auto-cancel' ), function() {
            echo '<p>' . esc_html__( 'Automatically cancel WooCommerce orders that remain unpaid beyond your thresholds.', 'wc-pending-auto-cancel' ) . '</p>';
        }, self::OPTION_KEY );

        add_settings_field( 'enabled', __( 'Enable auto-cancel', 'wc-pending-auto-cancel' ), function() {
            $o = $this->options();
            printf( '<label><input type="checkbox" name="%1$s[enabled]" %2$s> %3$s</label>',
                esc_attr( self::OPTION_KEY ),
                checked( ! empty( $o['enabled'] ), true, false ),
                esc_html__( 'Run scheduled cancellations (hourly).', 'wc-pending-auto-cancel' )
            );
        }, self::OPTION_KEY, 'wcpac_main' );

        add_settings_field( 'statuses', __( 'Target statuses', 'wc-pending-auto-cancel' ), function() {
            $o = $this->options();
            $targets = [ 'pending' => __( 'Pending payment', 'wc-pending-auto-cancel' ), 'on-hold' => __( 'On hold', 'wc-pending-auto-cancel' ) ];
            foreach ( $targets as $key => $label ) {
                printf(
                    '<label style="display:block;margin:.25rem 0;"><input type="checkbox" name="%1$s[statuses][]" value="%2$s" %3$s> %4$s</label>',
                    esc_attr( self::OPTION_KEY ),
                    esc_attr( $key ),
                    checked( in_array( $key, (array) $o['statuses'], true ), true, false ),
                    esc_html( $label )
                );
            }
        }, self::OPTION_KEY, 'wcpac_main' );

        add_settings_field( 'hours', __( 'Hours before cancel', 'wc-pending-auto-cancel' ), function() {
            $o = $this->options();
            ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Pending payment','wc-pending-auto-cancel'); ?></th>
                    <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hours][pending]" value="<?php echo esc_attr( absint( $o['hours']['pending'] ?? 24 ) ); ?>"> <?php esc_html_e('hours','wc-pending-auto-cancel'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('On hold','wc-pending-auto-cancel'); ?></th>
                    <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hours][on-hold]" value="<?php echo esc_attr( absint( $o['hours']['on-hold'] ?? 72 ) ); ?>"> <?php esc_html_e('hours','wc-pending-auto-cancel'); ?></td>
                </tr>
            </table>
            <?php
        }, self::OPTION_KEY, 'wcpac_main' );

        add_settings_field( 'note', __( 'Order note', 'wc-pending-auto-cancel' ), function() {
            $o = $this->options();
            printf(
                '<label><input type="checkbox" name="%1$s[add_note]" %2$s> %3$s</label><br><input type="text" class="regular-text" name="%1$s[order_note]" value="%4$s" placeholder="%5$s">',
                esc_attr( self::OPTION_KEY ),
                checked( ! empty( $o['add_note'] ), true, false ),
                esc_html__( 'Add a private order note when auto-cancelled', 'wc-pending-auto-cancel' ),
                esc_attr( $o['order_note'] ),
                esc_attr__( 'Order auto-cancelled after {hours} hours in status "{status}" with no payment (WCPAC).', 'wc-pending-auto-cancel' )
            );
        }, self::OPTION_KEY, 'wcpac_main' );
    }

    public function sanitize( $input ) {
        $out = self::defaults();
        $out['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;

        $out['statuses'] = [];
        if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
            foreach ( $input['statuses'] as $s ) {
                $s = sanitize_text_field( $s );
                if ( in_array( $s, [ 'pending', 'on-hold' ], true ) ) {
                    $out['statuses'][] = $s;
                }
            }
        }

        $out['hours']['pending'] = isset( $input['hours']['pending'] ) ? max( 1, absint( $input['hours']['pending'] ) ) : $out['hours']['pending'];
        $out['hours']['on-hold'] = isset( $input['hours']['on-hold'] ) ? max( 1, absint( $input['hours']['on-hold'] ) ) : $out['hours']['on-hold'];

        $out['add_note']   = ! empty( $input['add_note'] ) ? 1 : 0;
        $out['order_note'] = sanitize_text_field( $input['order_note'] ?? $out['order_note'] );
        return $out;
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Pending Auto-Cancel', 'wc-pending-auto-cancel' ),
            __( 'Pending Auto-Cancel', 'wc-pending-auto-cancel' ),
            'manage_woocommerce',
            self::OPTION_KEY,
            [ $this, 'render_settings_page' ]
        );
    }

    public function settings_link( $links ) {
        $url = admin_url( 'admin.php?page=' . self::OPTION_KEY );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wc-pending-auto-cancel' ) . '</a>';
        return $links;
    }

    public function render_settings_page() {
        if ( isset($_POST['wcpac_run_now']) && check_admin_referer('wcpac_run_now_action', 'wcpac_run_now_nonce') ) {
            $this->run_cron();
            echo '<div class="updated notice"><p>' . esc_html__( 'Auto-cancel task executed now.', 'wc-pending-auto-cancel' ) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WooCommerce Pending Auto-Cancel', 'wc-pending-auto-cancel'); ?></h1>
            <form action="options.php" method="post">
                <?php
                    settings_fields( self::OPTION_KEY );
                    do_settings_sections( self::OPTION_KEY );
                    submit_button();
                ?>
            </form>
            <form method="post" style="margin-top:1rem;">
                <?php wp_nonce_field('wcpac_run_now_action', 'wcpac_run_now_nonce'); ?>
                <input type="hidden" name="wcpac_run_now" value="1">
                <?php submit_button( __( 'Run now (test)', 'wc-pending-auto-cancel' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    /** Get merged options */
    public function options() {
        $opts = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $opts, self::defaults() );
    }

    /** Cron callback */
    public function run_cron() {
        $o = $this->options();
        if ( empty( $o['enabled'] ) ) { return; }
        if ( ! class_exists( 'WooCommerce' ) ) { return; }

        foreach ( (array) $o['statuses'] as $status_key ) {
            $hours = absint( $o['hours'][ $status_key ] ?? 0 );
            if ( $hours < 1 ) continue;

            $cutoff_ts  = current_time( 'timestamp', true ) - ( $hours * HOUR_IN_SECONDS ); // UTC
            $cutoff_iso = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

            $wc_status = 'wc-' . $status_key;

            $page = 1;
            $per_page = 200;

            do {
                $query_args = [
                    'status'       => $wc_status,
                    'limit'        => $per_page,
                    'page'         => $page,
                    'date_created' => [ 'before' => $cutoff_iso ],
                    'return'       => 'objects',
                ];
                $orders = wc_get_orders( $query_args );

                if ( empty( $orders ) ) { break; }

                foreach ( $orders as $order ) {
                    if ( ! $order instanceof WC_Order ) continue;
                    if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
                        continue; // Safety: do not touch paid orders
                    }
                    // Cancel
                    $note = '';
                    if ( ! empty( $o['add_note'] ) ) {
                        $note_tpl = $o['order_note'];
                        $repl = [
                            '{hours}'    => $hours,
                            '{status}'   => $status_key,
                            '{order_id}' => $order->get_id(),
                        ];
                        $note = strtr( $note_tpl, $repl );
                    }
                    $order->update_status( 'cancelled', $note );
                }

                $page++;
            } while ( count( $orders ) === $per_page );
        }
    }
}
endif;

WCPAC_Plugin::instance();
