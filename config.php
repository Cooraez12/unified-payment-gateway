<?php
// config.php
if (!defined('ABSPATH')) {
    exit;
}

return [
    'id'   => 'unified',
    'name' => 'Unified Payment Gateway',
    'version' => '1.1.5',
	'title' => 'Unified Payment Gateway',
    'description' => 'This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security',
    'icon' => '',

    'protocol' => is_ssl() ? 'https://' : 'http://',
    'host'     => 'sell.dfin.ai',

    'requirements' => [
        'php' => '8.0',
        'wc'  => '6.5.4',
    ],

    'paths' => [
		'file'   => __FILE__,                       // Full path to this file
        'dir'    => plugin_dir_path(__FILE__),     // Absolute dir path
        'url'    => plugin_dir_url(__FILE__),      // Base URL
        'assets' => plugin_dir_url(__FILE__) . 'assets/', // Assets URL
    ],
];
