(function ($) {
    'use strict';

    // Add global debug function
    window.debugLog = function (data, label, skipAjax = false) {
        console.log(`${label || ''}: `, data);

        if (!window.bemaAdmin?.debug?.enabled || skipAjax) return;

        $.post(bemaAdmin.ajaxUrl, {
            action: 'bema_debug_log',
            nonce: bemaAdmin.nonce,
            data: JSON.stringify(data),
            label: label
        });
    };

    window.BemaAdmin = {
        init: function () {
            debugLog('Initializing BemaAdmin', 'ADMIN_INIT');

            this.bindGlobalEvents();
            this.initializeNotifications();

            // Initialize sync if available
            if (typeof BemaSync !== 'undefined') {
                BemaSync.init();
            }
        },

        debug: window.debugLog, // Share the debug function

        bindGlobalEvents: function () {
            const self = this;

            // Select all checkboxes functionality
            $(document).on('change', '#select-all-subscribers', function() {
                const isChecked = $(this).prop('checked');
                $('input[name="subscriber_ids[]"]').prop('checked', isChecked);
            });

            // Update header checkbox when individual checkboxes change
            $(document).on('change', 'input[name="subscriber_ids[]"]', function() {
                const totalCheckboxes = $('input[name="subscriber_ids[]"]').length;
                const checkedCheckboxes = $('input[name="subscriber_ids[]"]:checked').length;
                $('#select-all-subscribers').prop('checked', totalCheckboxes === checkedCheckboxes);
            });

            // Modal handling
            $(document).on('click', '.modal .close, .modal', function (e) {
                if (e.target === this || $(e.target).hasClass('close')) {
                    $(this).closest('.modal').fadeOut(200);
                }
            });

            // Global AJAX error handler
            $(document).ajaxError(function (event, xhr, settings, error) {
                debugLog({
                    event: event.type,
                    xhr: xhr.responseText,
                    settings: settings,
                    error: error
                }, 'AJAX_ERROR', true); // Skip AJAX logging to prevent loops

                self.showNotification(
                    error || 'An error occurred during the request.',
                    'error'
                );
            });

            // ESC key to close modals
            $(document).keyup(function (e) {
                if (e.key === "Escape") {
                    $('.modal:visible').fadeOut(200);
                }
            });

            debugLog('Global events bound', 'ADMIN_INIT');
        },

        initializeNotifications: function () {
            if (!$('#bema-notifications').length) {
                $('body').append('<div id="bema-notifications"></div>');
            }
            debugLog('Notifications initialized', 'ADMIN_INIT');
        },

        showNotification: function (message, type = 'info', duration = 5000) {
            const notificationId = 'notification-' + Date.now();
            const $notification = $(
                `<div id="${notificationId}" class="bema-notification notice notice-${type} is-dismissible">
                    <p>${message}</p>
                </div>`
            );

            $('#bema-notifications').append($notification);
            $notification.fadeIn(300);

            debugLog({
                type: 'notification',
                message: message,
                notificationType: type,
                duration: duration
            }, 'ADMIN_NOTIFICATION');

            if (duration) {
                setTimeout(() => {
                    this.removeNotification($notification);
                }, duration);
            }

            $notification.find('.notice-dismiss').on('click', () => {
                this.removeNotification($notification);
            });
        },

        removeNotification: function ($notification) {
            $notification.fadeOut(300, function () {
                $(this).remove();
            });
        },

        showModal: function (title, content) {
            debugLog({
                type: 'modal',
                title: title
            }, 'ADMIN_MODAL');

            const $modal = $(
                `<div class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h2>${title}</h2>
                        <div class="modal-body">${content}</div>
                    </div>
                </div>`
            );

            $('body').append($modal);
            $modal.fadeIn(200);

            return $modal;
        },

        formatDate: function (date) {
            return new Date(date).toLocaleString();
        },

        formatBytes: function (bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) {
                bytes /= 1024;
                i++;
            }
            return `${bytes.toFixed(2)} ${units[i]}`;
        }
    };

    // Make BemaAdmin globally available
    window.BemaAdmin = BemaAdmin;

    // Initialize on document ready
    $(document).ready(function () {
        BemaAdmin.init();
    });
})(jQuery);
