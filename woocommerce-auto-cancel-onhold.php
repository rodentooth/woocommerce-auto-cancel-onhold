<?php




/**
 * Plugin Name:       Auto Cancel WC On Hold Orders
 * Plugin URI:        https://wp-plugins.emanuelgraf.me/woocommerce-auto-cancel-onhold/(opens in a new tab)
 * Description:       Cancels WooCommerce on hold orders after X days.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Emanuel Graf
 * Author URI:        https://wp-plugins.emanuelgraf.me/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        todo
 * Text Domain:       woocommerce-auto-cancel-onhold
 */



register_activation_hook( __FILE__, 'onActivate' );

add_filter( 'cron_schedules', 'add_cron_interval_hundred_seconds' );
add_action( 'bl_wc-auto-cancel-onhold-cron', 'main' );


function onActivate(){


    if ( ! wp_next_scheduled( 'bl_wc-auto-cancel-onhold-cron' ) ) {
        wp_schedule_event( time(), 'hundred_seconds', 'bl_wc-auto-cancel-onhold-cron' );
    }

}

register_deactivation_hook( __FILE__, 'onDeactivate' );

function onDeactivate(){

    $timestamp = wp_next_scheduled( 'bl_wc-auto-cancel-onhold-cron' );
    wp_unschedule_event( $timestamp, 'bl_wc-auto-cancel-onhold-cron' );

}





//hook for five minute schedule
function add_cron_interval_hundred_seconds( $schedules ) {
    $schedules['hundred_seconds'] = array(
        'interval' => 100,
        'display'  => esc_html__( 'Every 100 Seconds' ), );

    return $schedules;
}



/**
 * Create the section beneath the products tab
 **/
add_filter( 'woocommerce_get_sections_general', 'wcslider_add_section' );
function wcslider_add_section( $sections ) {

    $sections['auto-cancel-onhold'] = __( 'Auto Cancel On Hold Orders', 'text-domain' );
    return $sections;

}

/**
 * Add settings to the specific section we created before
 */
add_filter( 'woocommerce_get_settings_general', 'wcOnHold_all_settings', 10, 2 );
function wcOnHold_all_settings( $settings, $current_section ) {
    /**
     * Check the current section is what we want
     **/
    if ( $current_section == 'auto-cancel-onhold' ) {
        $settings_autocancel = array();
        // Add Title to the Settings
        $settings_autocancel[] = array( 'name' => __( 'WC Auto Cancel On Hold Orders Settings', 'text-domain' ), 'type' => 'title', 'desc' => __( 'The following options are used to configure WC auto cancel on hold orders', 'text-domain' ), 'id' => 'auto-cancel-onhold' );
        // Add first  option
        $settings_autocancel[] = array(
            'name'     => __( 'Auto Cancel Orders after how many days?', 'text-domain' ),
            'desc_tip' => __( 'Leave empty = no auto cancel orders', 'text-domain' ),
            'id'       => 'wcautocancel_days_until_cancel',
            'type'     => 'number',
            'placeholder'=> 'insert days here to activate auto cancel',
            'css'      => 'min-width:300px;',
            'desc'     => __( 'Order will be canceled after this amount of days since its creation', 'text-domain' ),
        );

        // Add second checkbox option
        $settings_autocancel[] = array(
            'name'     => __( 'Send Email to Customer on cancelled order', 'text-domain' ),
            'id'       => 'wcautocancel_send_email_to_customer',
            'type'     => 'checkbox',
            'css'      => 'min-width:300px;',
            'desc'     => __( 'if checked, we will send the standard order cancellation email to the customer (this Email notification must be enabled in woocommerce email settings)', 'text-domain' ),
        );

        // Add second text field option

        $available_payment_methods_for_settings = array();
        $available_payment_methods = WC()->payment_gateways->get_available_payment_gateways();
        foreach( $available_payment_methods as $method ) {
            //echo $method->title . '<br />';
            $available_payment_methods_for_settings[$method->id] = $method->title;
        }
        $settings_autocancel[] = array(
            'name'     => __( 'Only Autocancel for the following payment methods', 'text-domain' ),
            'id'       => 'wcautocancel_allowed_payments_for_autocancel',
            'type'     => 'multiselect',
            'desc'     => __( 'If you want to restrict the plugin to cancel only orders with specific payment choices, you can define them here. (CTRL/STRG + Click to deselect & select multiple)', 'text-domain' ),
            'options' => $available_payment_methods_for_settings, // array of options for select/multiselects only
        );



        $settings_autocancel[] = array( 'type' => 'sectionend', 'id' => 'wcslider' );
        return $settings_autocancel;

        /**
         * If not, return the standard settings
         **/
    } else {
        return $settings;
    }
}


add_filter( 'woocommerce_email_recipient_cancelled_order', 'wc_cancelled_order_add_customer_email', 10, 2 );
add_filter( 'woocommerce_email_recipient_failed_order', 'wc_cancelled_order_add_customer_email', 10, 2 );
function wc_cancelled_order_add_customer_email( $recipient, $order )
{

    if (get_option('wcautocancel_send_email_to_customer')=="yes") {


        // Avoiding errors in backend (mandatory when using $order argument)
        if ( ! is_a( $order, 'WC_Order' ) ) return $recipient;
        // Avoiding errors in backend (mandatory when using $order argument)
        if (!method_exists($order, 'get_billing_email')) return $recipient;

        return $recipient .= (strlen($recipient)>0?",":"") . $order->get_billing_email();
    }
    return $recipient;
}



//main function of the plugin. everything is done in here
function main(){
    $DEBUG = false;

    global $wpdb;

    $currentDate = new DateTime();
    $currentDate= $currentDate->format('Y-m-d H:i:s');

    $LOG = "---execution: $currentDate \n\n";

    if(get_option( 'wcautocancel_days_until_cancel' )===""){
        $LOG.="function not enabled due to no days until cancel set";
    }else {

        $endDate = strftime("%Y-%m-%d H:i:s", strtotime("$currentDate -" . get_option('wcautocancel_days_until_cancel') . " days"));


        $result = $wpdb->get_results("
        SELECT * FROM $wpdb->posts
        WHERE post_type = 'shop_order'
        AND (post_status = 'wc-on-hold')
        AND post_date < '$endDate'
    ");

        $LOG .= "found " . count($result) . " orders that might be cancelled\n";


        foreach ($result as $value) {

            // Getting WC order object
            $order = wc_get_order($value->ID);

            //check if there are payment restrictions
            $payment_gateways_filter = get_option('wcautocancel_allowed_payments_for_autocancel');
            if (count($payment_gateways_filter) > 0) {
                $hasCorrectPaymentGateway = false;
                foreach ($payment_gateways_filter as $key=>$payment_gateway) {

                    if ($order->get_payment_method() == $payment_gateway) {
                        //this order meets the required criteria in terms of selected payment method.
                        $hasCorrectPaymentGateway = true;

                    }

                }
                if(!$hasCorrectPaymentGateway){
                    $LOG .= "\n".$order->get_order_number() . " does not meet the criteria, has ". $order->get_payment_method_title() . " as payment gateway";
                    continue;
                }
            }
            $LOG .= "\n".$order->get_order_number() . " meets the criteria, has ". $order->get_payment_method_title() . " as payment gateway and can be deleted";

            if($DEBUG == false){
                $order->update_status( 'cancelled');
            }





        }
    }




    if($DEBUG){
        file_put_contents(__DIR__."/log.txt",$LOG."\n\n",FILE_APPEND);

    }



}
