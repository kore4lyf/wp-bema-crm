window.BemaDatabase = {
    init: function () {
        this.bindEvents();
        this.initializeDataTables();
    },

    bindEvents: function () {
        const self = this;
        const $ = jQuery;

        // View subscriber details
        $('.view-subscriber-details').on('click', function() {
            const subscriberId = $(this).data('subscriber-id');
            
            $.ajax({
                url: bemaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bema_get_subscriber_details',
                    nonce: bemaAdmin.nonce,
                    id: subscriberId
                },
                beforeSend: function() {
                    // Show loading state
                    BemaAdmin.showNotification('Loading subscriber details...', 'info');
                },
                success: function(response) {
                    if (response.success) {
                        // Clear loading notification
                        $('#bema-notifications').empty();
                        
                        // Format and display the data
                        const formattedDetails = formatSubscriberDetails(response.data);
                        $('#modal-title').text('Subscriber Details');
                        $('#modal-content').html(formattedDetails);
                        $('#subscriber-modal').fadeIn(300);
                    } else {
                        BemaAdmin.showNotification(response.data.message || 'Error loading subscriber details', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    BemaAdmin.showNotification('Failed to load subscriber details: ' + error, 'error');
                }
            });
        });

        // View log details
        $(document).on('click', '.view-log-details', function () {
            const id = $(this).data('id');
            self.loadLogDetails(id);
        });

        // Close modal
        $('.modal .close, .modal').on('click', function(e) {
            if (e.target === this || $(e.target).hasClass('close')) {
                $(this).closest('.modal').fadeOut(300);
            }
        });

        // Filter form submission
        $('#subscriber-filter-form').on('submit', function (e) {
            e.preventDefault();
            self.filterSubscribers($(this));
        });

        // Bulk actions
        $('#bulk-action-form').on('submit', function (e) {
            e.preventDefault();
            self.handleBulkAction($(this));
        });

        // Real-time filter updates
        $('#status-filter, #campaign-filter').on('change', function () {
            $('#subscriber-filter-form').submit();
        });
    },

    initializeDataTables: function () {
        if ($.fn.DataTable) {
            $('.bema-table').DataTable({
                pageLength: 20,
                responsive: true,
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'copy',
                        exportOptions: {
                            columns: ':not(.no-export)'
                        }
                    },
                    {
                        extend: 'csv',
                        exportOptions: {
                            columns: ':not(.no-export)'
                        }
                    },
                    {
                        extend: 'excel',
                        exportOptions: {
                            columns: ':not(.no-export)'
                        }
                    },
                    {
                        extend: 'pdf',
                        exportOptions: {
                            columns: ':not(.no-export)'
                        }
                    }
                ]
            });
        }
    },

    loadSubscriberDetails: function (id) {
        const self = this;
        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_get_subscriber_details',
                nonce: bemaAdmin.nonce,
                id: id
            },
            success: function (response) {
                if (response.success) {
                    self.displayModal('Subscriber Details', self.formatSubscriberDetails(response.data));
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error loading subscriber details', 'error');
                }
            },
            error: function () {
                BemaAdmin.showNotification('Failed to load subscriber details', 'error');
            }
        });
    },

    loadLogDetails: function (id) {
        const self = this;
        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_get_log_details',
                nonce: bemaAdmin.nonce,
                id: id
            },
            success: function (response) {
                if (response.success) {
                    self.displayModal('Log Details', self.formatLogDetails(response.data));
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error loading log details', 'error');
                }
            },
            error: function () {
                BemaAdmin.showNotification('Failed to load log details', 'error');
            }
        });
    },

    displayModal: function (title, content) {
        const $ = jQuery;
        $('#modal-title').text(title);
        $('#modal-content').html(content);
        $('#details-modal').show();
    },

    formatSubscriberDetails: function (data) {
        const subscriber = data.subscriber;
        const additionalData = data.additional_data || {};
        
        let html = '<div class="subscriber-details">';
        
        // Basic Information
        html += `
            <div class="details-section">
                <h3>Basic Information</h3>
                <table class="widefat">
                    <tr>
                        <th>Email</th>
                        <td>${subscriber.subscriber || ''}</td>
                    </tr>
                    <tr>
                        <th>Name</th>
                        <td>${(subscriber.first_name || '') + ' ' + (subscriber.last_name || '')}</td>
                    </tr>
                    <tr>
                        <th>Tier</th>
                        <td><span class="tier-badge tier-${subscriber.tier || ''}">${subscriber.tier || 'Unassigned'}</span></td>
                    </tr>
                    <tr>
                        <th>Campaign</th>
                        <td>${subscriber.campaign || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Added Date</th>
                        <td>${subscriber.formatted_date || subscriber.date_added || 'N/A'}</td>
                    </tr>
                </table>
            </div>
        `;
    
        // MailerLite Status
        if (additionalData.mailerlite_status) {
            const mlStatus = additionalData.mailerlite_status;
            html += `
                <div class="details-section">
                    <h3>MailerLite Status</h3>
                    <table class="widefat">
                        <tr>
                            <th>Status</th>
                            <td><span class="status-badge status-${mlStatus.status.toLowerCase()}">${mlStatus.status || 'Unknown'}</span></td>
                        </tr>
                        <tr>
                            <th>Last Updated</th>
                            <td>${mlStatus.last_updated ? new Date(mlStatus.last_updated).toLocaleString() : 'N/A'}</td>
                        </tr>
                        ${mlStatus.groups && mlStatus.groups.length > 0 ? `
                            <tr>
                                <th>Groups</th>
                                <td>
                                    ${mlStatus.groups.map(group => `
                                        <span class="group-badge">${group.name}</span>
                                    `).join('')}
                                </td>
                            </tr>
                        ` : ''}
                    </table>
                </div>
            `;
        }
    
        // Purchase History
        if (additionalData.purchase_history) {
            const history = additionalData.purchase_history;
            html += `
                <div class="details-section">
                    <h3>Purchase History</h3>
                    <table class="widefat">
                        <tr>
                            <th>Total Spent</th>
                            <td>$${parseFloat(history.total_spent || 0).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <th>Last Purchase</th>
                            <td>${history.last_purchase ? new Date(history.last_purchase).toLocaleString() : 'N/A'}</td>
                        </tr>
                    </table>
                </div>
            `;
    
            if (history.purchases && history.purchases.length > 0) {
                html += `
                    <div class="details-section">
                        <h3>Purchase Details</h3>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>ID</th>
                                    <th>Amount</th>
                                    <th>Products</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${history.purchases.map(purchase => `
                                    <tr>
                                        <td>${new Date(purchase.date).toLocaleString()}</td>
                                        <td>${purchase.id}</td>
                                        <td>$${parseFloat(purchase.amount).toFixed(2)}</td>
                                        <td>${Array.isArray(purchase.products) ? purchase.products.join(', ') : purchase.products || 'N/A'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
        }
    
        // Campaign Data
        if (additionalData.campaign_data) {
            html += `
                <div class="details-section">
                    <h3>Campaign Status</h3>
                    <table class="widefat">
                        <tr>
                            <th>Product ID</th>
                            <td>${additionalData.campaign_data.product_id}</td>
                        </tr>
                        <tr>
                            <th>Purchase Status</th>
                            <td>
                                <span class="status-badge status-${additionalData.campaign_data.has_purchased ? 'completed' : 'pending'}">
                                    ${additionalData.campaign_data.has_purchased ? 'Purchased' : 'Not Purchased'}
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            `;
        }
    
        // Sync Status (if available)
        if (additionalData.sync_status) {
            html += `
                <div class="details-section">
                    <h3>Sync Information</h3>
                    <table class="widefat">
                        ${additionalData.sync_status.last_sync ? `
                            <tr>
                                <th>Last Sync</th>
                                <td>${new Date(additionalData.sync_status.last_sync).toLocaleString()}</td>
                            </tr>
                        ` : ''}
                        ${additionalData.sync_status.sync_errors && additionalData.sync_status.sync_errors.length > 0 ? `
                            <tr>
                                <th>Sync Errors</th>
                                <td>
                                    <ul class="sync-errors">
                                        ${additionalData.sync_status.sync_errors.map(error => `
                                            <li class="error-message">${error}</li>
                                        `).join('')}
                                    </ul>
                                </td>
                            </tr>
                        ` : ''}
                    </table>
                </div>
            `;
        }
    
        html += '</div>';
        return html;
    },

    formatLogDetails: function (data) {
        let html = '<div class="log-details">';

        for (const [key, value] of Object.entries(data)) {
            if (key === 'data' && typeof value === 'object') {
                html += `<h3>Additional Data</h3><pre>${JSON.stringify(value, null, 2)}</pre>`;
            } else {
                html += `<p><strong>${this.formatLabel(key)}:</strong> ${value}</p>`;
            }
        }

        html += '</div>';
        return html;
    },

    formatLabel: function (key) {
        return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    },

    formatMetaValue: function (value) {
        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }
        if (typeof value === 'object') {
            return `<pre>${JSON.stringify(value, null, 2)}</pre>`;
        }
        return value;
    },

    showError: function (message) {
        BemaAdmin.showNotification(message, 'error');
    },

    filterSubscribers: function ($form) {
        // Add loading state
        const $submitButton = $form.find('button[type="submit"]');
        const originalText = $submitButton.text();
        $submitButton.prop('disabled', true).text('Filtering...');

        // Submit form normally since we're using WP's built-in pagination
        $form.submit();

        // Reset button after short delay
        setTimeout(() => {
            $submitButton.prop('disabled', false).text(originalText);
        }, 1000);
    },

    handleBulkAction: function ($form) {
        const action = $form.find('select[name="bulk_action"]').val();
        const selected = $form.find('input[name="subscriber[]"]:checked');

        if (!action || selected.length === 0) {
            BemaAdmin.showNotification('Please select an action and at least one subscriber', 'warning');
            return;
        }

        if (confirm(`Are you sure you want to ${action} the selected subscribers?`)) {
            this.processBulkAction(action, selected.map(function () {
                return this.value;
            }).get());
        }
    },

    processBulkAction: function (action, ids) {
        const $bulkActionSelect = jQuery('select[name="bulk_action"]');
        const originalText = $bulkActionSelect.find(':selected').text();

        $bulkActionSelect.prop('disabled', true);

        jQuery.ajax({
            url: bemaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'bema_bulk_action',
                nonce: bemaAdmin.nonce,
                bulk_action: action,
                ids: ids
            },
            success: function (response) {
                if (response.success) {
                    BemaAdmin.showNotification('Bulk action completed successfully', 'success');
                    window.location.reload();
                } else {
                    BemaAdmin.showNotification(response.data.message || 'Error processing bulk action', 'error');
                }
            },
            error: function () {
                BemaAdmin.showNotification('Failed to process bulk action', 'error');
            },
            complete: function () {
                $bulkActionSelect.prop('disabled', false);
            }
        });
    }
};
