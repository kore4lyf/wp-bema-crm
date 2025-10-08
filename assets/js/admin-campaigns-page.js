(function($) {
    $(document).ready(function() {
        // Edit button click
        $(document).on('click', '.edit-btn', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            
            // Hide display values and show edit inputs
            $row.find('.display-value').hide();
            $row.find('.edit-input').show();
            
            // Hide edit button and show submit/cancel buttons
            $(this).hide();
            $row.find('.submit-btn, .cancel-btn').show();
        });

        // Cancel button click
        $(document).on('click', '.cancel-btn', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            
            // Hide edit inputs and show display values
            $row.find('.edit-input').hide();
            $row.find('.display-value').show();
            
            // Hide submit/cancel buttons and show edit button
            $(this).hide();
            $row.find('.submit-btn').hide();
            $row.find('.edit-btn').show();
        });

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
            
            // Get nonce value correctly
            var nonce = $('input[name="bema_campaign_nonce"]').val();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_campaign',
                    nonce: nonce,
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
                error: function(xhr, status, error) {
                    console.log('AJAX Error:', {xhr: xhr, status: status, error: error});
                    showNotice('Error updating campaign: ' + error, 'error');
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