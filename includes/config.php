<?php
// config.php
if (!defined('UNIFIED_PROTOCOL')) {
    define('UNIFIED_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('UNIFIED_HOST')) {
    define('UNIFIED_HOST', 'pay.unified.xyz');
}

if (!defined('UNIFIED_BASE_URL')) {
	define('UNIFIED_BASE_URL', UNIFIED_PROTOCOL . UNIFIED_HOST);
}
