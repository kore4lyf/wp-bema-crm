(function ($) {
    $(document).ready(function () {
        // Edit button click
        $(document).on('click', '.edit-btn', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            
            // Show edit inputs, hide display values
            $row.find('.display-value').hide();
            $row.find('.edit-input').show();
            
            // Show submit/cancel buttons, hide edit button
            $(this).hide();
            $row.find('.submit-btn, .cancel-btn').show();
        });

        // Cancel button click
        $(document).on('click', '.cancel-btn', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');

            // Restore original values from display
            var startText = $row.find('[data-field="start_date"] .display-value').text().trim();
            var endText = $row.find('[data-field="end_date"] .display-value').text().trim();
            var statusText = $row.find('[data-field="status"] .display-value .status-badge').text().trim().toLowerCase();

            // Convert formatted dates back to yyyy-mm-dd when possible
            var startInput = $row.find('[data-field="start_date"] .edit-input');
            var endInput = $row.find('[data-field="end_date"] .edit-input');
            startInput.val(parseDateToInput(startText));
            endInput.val(parseDateToInput(endText));

            // Restore status select
            var statusInput = $row.find('[data-field="status"] .edit-input');
            if (statusText) {
                statusInput.val(statusText);
            }
            
            // Hide edit inputs, show display values
            $row.find('.edit-input').hide();
            $row.find('.display-value').show();
            
            // Show edit button, hide submit/cancel buttons
            $row.find('.edit-btn').show();
            $(this).hide();
            $row.find('.submit-btn').hide();
        });

        function parseDateToInput(formatted) {
            // Expect formats like 'January 1, 2025' or '—'
            if (!formatted || formatted === '—') return '';
            var date = new Date(formatted);
            if (isNaN(date.getTime())) return '';
            var m = (date.getMonth() + 1).toString().padStart(2, '0');
            var d = date.getDate().toString().padStart(2, '0');
            return date.getFullYear() + '-' + m + '-' + d;
        }

        // Submit button click
        $(document).on('click', '.submit-btn', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            var $button = $(this);
            
            var campaignId = $row.data('campaign-id');
            var campaignName = $row.data('campaign-name');
            var startDate = $row.find('[data-field="start_date"] .edit-input').val();
            var endDate = $row.find('[data-field="end_date"] .edit-input').val();
            var status = $row.find('[data-field="status"] .edit-input').val();

            // Client-side validation for dates
            if (startDate && endDate) {
                var sd = new Date(startDate);
                var ed = new Date(endDate);
                if (ed < sd) {
                    showNotice('End date cannot be earlier than start date', 'error');
                    return;
                }
            }
            
            $button.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_campaign',
                    nonce: $('#bema_campaign_nonce').val(),
                    campaign_id: campaignId,
                    campaign_name: campaignName,
                    start_date: startDate,
                    end_date: endDate,
                    status: status
                },
                success: function(response) {
                    if (response.success) {
                        // Update display values
                        updateDisplayValues($row, startDate, endDate, status);
                        
                        // Switch back to display mode
                        $row.find('.edit-input').hide();
                        $row.find('.display-value').show();
                        $row.find('.edit-btn').show();
                        $row.find('.submit-btn, .cancel-btn').hide();
                        
                        // Show success message
                        showNotice('Campaign updated successfully', 'success');
                    } else {
                        showNotice(response.data.message || 'Failed to update campaign', 'error');
                    }
                },
                error: function() {
                    showNotice('Error updating campaign', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Submit');
                }
            });
        });

        function updateDisplayValues($row, startDate, endDate, status) {
            // Update start date display
            var startDisplay = startDate ? formatDate(startDate) : '—';
            $row.find('[data-field="start_date"] .display-value').text(startDisplay);
            
            // Update end date display
            var endDisplay = endDate ? formatDate(endDate) : '—';
            $row.find('[data-field="end_date"] .display-value').text(endDisplay);
            
            // Update status display
            var statusClass = getStatusClass(status);
            var statusText = status.charAt(0).toUpperCase() + status.slice(1);
            $row.find('[data-field="status"] .display-value').html(
                '<span class="status-badge ' + statusClass + '">' + statusText + '</span>'
            );
        }

        function formatDate(dateString) {
            var date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }

        function getStatusClass(status) {
            var classes = {
                'active': 'status-active',
                'draft': 'status-draft',
                'completed': 'status-completed',
                'pending': 'status-pending'
            };
            return classes[status] || 'status-unknown';
        }

        function showNotice(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);
            
            setTimeout(function() {
                $notice.fadeOut();
            }, 6000);
        }
    });
})(jQuery);
