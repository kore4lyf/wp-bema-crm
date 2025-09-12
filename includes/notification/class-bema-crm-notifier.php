<?php
namespace Bema;/**
 * Bema_CRM_Notifier
 *
 * A static class for managing and displaying WordPress admin notices.
 * Supports multiple notice types and queues multiple notices in a single request.
 *
 * @package Bema_CRM
 */

if (!class_exists('Bema\Bema_CRM_Notifier')) {

	class Bema_CRM_Notifier
	{

		/**
		 * Holds all queued admin notices.
		 *
		 * Each notice is an associative array with keys: type, title, message.
		 *
		 * @var array
		 */
		private static $notices = array();

		/**
		 * Allowed WordPress notice types.
		 *
		 * @var array
		 */
		private static $allowed_types = array('success', 'error', 'warning', 'info');

		/**
		 * Add a notice to the queue.
		 */
		public static function add($message, $type = 'success', $title = '') {
			if (!in_array($type, self::$allowed_types)) {
				$type = 'info';
			}
			self::$notices[] = array('type' => $type, 'title' => $title, 'message' => $message);
		}

		/**
		 * Display all queued notices.
		 */
		public static function display() {
			if (empty(self::$notices)) return;
			
			foreach (self::$notices as $notice) {
				$title = $notice['title'] ? '<strong>' . esc_html($notice['title']) . '</strong> ' : '';
				printf('<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>', 
					esc_attr($notice['type']), $title, esc_html($notice['message']));
			}
			self::$notices = array();
		}

		/**
		 * Initialize the notifier by hooking into WordPress.
		 */
		public static function init() {
			add_action('admin_notices', array(__CLASS__, 'display'));
		}

	}
}
