<?php
/**
 * Bema_CRM_Notifier
 *
 * A static class for managing and displaying WordPress admin notices.
 * Supports multiple notice types and queues multiple notices in a single request.
 *
 * @package Bema_CRM
 */

if ( ! class_exists( 'Bema_CRM_Notifier' ) ) {

	class Bema_CRM_Notifier {

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
		private static $allowed_types = array( 'success', 'error', 'warning', 'info' );

		/**
		 * Ensures the render method is only hooked once.
		 */
		private static function maybe_hook() {
			// Static variable to prevent multiple hook registrations.
			static $hooked = false;

			if ( ! $hooked ) {
				// Hook into WordPress admin_notices to output notices.
				add_action( 'admin_notices', array( __CLASS__, 'render' ) );
				$hooked = true;
			}
		}

		/**
		 * Adds a new notice to the internal queue.
		 *
		 * @param string $type    The type of notice: success, error, warning, info.
		 * @param string $title   The title of the notice.
		 * @param string $message The message body (can contain safe HTML).
		 */
		private static function add_notice( $type, $title, $message ) {
			// Validate the type; fallback to 'info' if invalid.
			if ( ! in_array( $type, self::$allowed_types, true ) ) {
				$type = 'info';
			}

			// Sanitize and store the notice data.
			self::$notices[] = array(
				'type'    => $type,
				'title'   => sanitize_text_field( $title ),     // Escaped later in output.
				'message' => wp_kses_post( $message ),          // Allows safe HTML in message.
			);

			// Hook the render method (once).
			self::maybe_hook();
		}

		/**
		 * Outputs all stored admin notices.
		 */
		public static function render() {
			foreach ( self::$notices as $notice ) {
				// Escape all output to prevent XSS.
				$type    = esc_attr( $notice['type'] );
				$title   = esc_html( $notice['title'] );
				$message = $notice['message']; // Already sanitized with wp_kses_post()

				// Output the notice using proper WordPress CSS classes.
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s:</strong> %3$s</p></div>',
					$type,
					$title,
					$message
				);
			}

			// Clear notices after displaying to avoid repeat output.
			self::$notices = array();
		}

		/**
		 * Adds a success notice.
		 *
		 * @param string $title   The title of the success notice.
		 * @param string $message The message content.
		 */
		public static function showSuccess( $title, $message ) {
			self::add_notice( 'success', $title, $message );
		}

		/**
		 * Adds a warning notice.
		 *
		 * @param string $title   The title of the warning notice.
		 * @param string $message The message content.
		 */
		public static function showWarning( $title, $message ) {
			self::add_notice( 'warning', $title, $message );
		}

		/**
		 * Adds an error notice.
		 *
		 * @param string $title   The title of the error notice.
		 * @param string $message The message content.
		 */
		public static function showError( $title, $message ) {
			self::add_notice( 'error', $title, $message );
		}

		/**
		 * Adds an informational notice.
		 *
		 * @param string $title   The title of the info notice.
		 * @param string $message The message content.
		 */
		public static function showInfo( $title, $message ) {
			self::add_notice( 'info', $title, $message );
		}
	}
}
