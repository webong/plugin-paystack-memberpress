<?php
/*
Plugin Name: MemberPress Paystack
Plugin URI: https://wordpress.org/plugins/memberpress-paystack/
Description: Paystack integration for MemberPress.
Version: 0.0.1
Author: Paystack
Author URI: https://paystack.com/
Text Domain: memberpress-paystack
License: GPLv2 or later
Copyright: 2019, Paystack, LLC
*/

if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

if (is_plugin_active('memberpress/memberpress.php')) {
    define('MP_PAYSTACK_PLUGIN_SLUG', 'memberpress-paystack/main.php');
    define('MP_PAYSTACK_PLUGIN_NAME', 'memberpress-paystack');
    define('MP_PAYSTACK_EDITION', MP_PAYSTACK_PLUGIN_NAME);
    define('MP_PAYSTACK_PATH', WP_PLUGIN_DIR . '/' . MP_PAYSTACK_PLUGIN_NAME);

    $mp_paystack_url_protocol = (is_ssl()) ? 'https' : 'http'; // Make all of our URLS protocol agnostic
    define('MP_PAYSTACK_URL', preg_replace('/^https?:/', "{$mp_paystack_url_protocol}:", plugins_url('/' . MP_PAYSTACK_PLUGIN_NAME)));
    define('MP_PAYSTACK_JS_URL', MP_PAYSTACK_URL . '/js');
    define('MP_PAYSTACK_IMAGES_URL', MP_PAYSTACK_URL . '/images');

    // Load Memberpress Base Gateway
    require_once(MP_PAYSTACK_PATH . '/../memberpress/app/lib/MeprBaseGateway.php');
    require_once(MP_PAYSTACK_PATH . '/../memberpress/app/lib/MeprBaseRealGateway.php');

    // Load Memberpress Paystack API
    require_once(MP_PAYSTACK_PATH . '/MeprPaystackAPI.php');

    // Load Memberpress Paystack Addon
    require_once(MP_PAYSTACK_PATH . '/MpPaystack.php');
    new MpPaystack;

    // // Load Memberpress Hooks Mechanism
    // require_once(MP_PAYSTACK_PATH . '/../memberpress/app/lib/MeprHooks.php');

    // MeprHooks::apply_filters('mepr-gateway-paths',  array(MP_PAYSTACK_PATH, MP_PAYSTACK_PATH . '/../memberpress/gateways') );
}
