<?php
namespace Bema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple notice function - outputs immediately
 */
function bema_notice($message, $type = 'success', $title = '') {
    $title_html = $title ? '<strong>' . esc_html($title) . '</strong> ' : '';
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . $title_html . esc_html($message) . '</p></div>';
}
