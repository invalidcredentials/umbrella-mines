<?php
/**
 * Solutions Page - View all mined solutions
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Handle direct submission
if (isset($_GET['submit_now']) && isset($_GET['_wpnonce'])) {
    $solution_id = intval($_GET['submit_now']);

    if (!wp_verify_nonce($_GET['_wpnonce'], 'submit_solution_' . $solution_id)) {
        wp_die('Invalid nonce');
    }

    // Get solution
    $solution = $wpdb->get_row($wpdb->prepare("
        SELECT s.*, w.address
        FROM {$wpdb->prefix}umbrella_mining_solutions s
        JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
        WHERE s.id = %d
    ", $solution_id));

    if ($solution) {
        // Submit to API
        $url = "https://scavenger.prod.gd.midnighttge.io/solution/{$solution->address}/{$solution->challenge_id}/{$solution->nonce}";

        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8'
            ),
            'body' => '{}',
            'timeout' => 180,  // 3 minutes - API is slow!
            'sslverify' => false  // Use false for -k equivalent
        ));

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            echo '<div class="notice notice-error"><p><strong>Submission Failed:</strong> ' . esc_html($error) . '</p></div>';
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status_code == 200 || $status_code == 201) {
                // Success - parse the receipt
                $response_data = json_decode($body, true);

                // Update solution status
                $wpdb->update(
                    $wpdb->prefix . 'umbrella_mining_solutions',
                    array(
                        'submission_status' => 'submitted',
                        'submitted_at' => current_time('mysql')
                    ),
                    array('id' => $solution_id),
                    array('%s', '%s'),
                    array('%d')
                );

                // Save the crypto receipt
                if (isset($response_data['crypto_receipt'])) {
                    $receipt = $response_data['crypto_receipt'];
                    $wpdb->insert(
                        $wpdb->prefix . 'umbrella_mining_receipts',
                        array(
                            'solution_id' => $solution_id,
                            'crypto_receipt' => wp_json_encode($receipt),
                            'preimage' => $receipt['preimage'] ?? '',
                            'signature' => $receipt['signature'] ?? '',
                            'timestamp' => $receipt['timestamp'] ?? current_time('mysql')
                        ),
                        array('%d', '%s', '%s', '%s', '%s')
                    );
                }

                echo '<div class="notice notice-success"><p><strong>SUCCESS!</strong> Solution submitted and accepted by API!</p><pre>' . esc_html($body) . '</pre></div>';

                // Redirect to clear URL params and prevent re-submission on refresh
                echo '<script>setTimeout(function(){ window.location.href = "?page=umbrella-mines-solutions"; }, 2000);</script>';
            } else {
                // Failed
                $wpdb->update(
                    $wpdb->prefix . 'umbrella_mining_solutions',
                    array(
                        'submission_status' => 'failed',
                        'submission_error' => $body
                    ),
                    array('id' => $solution_id),
                    array('%s', '%s'),
                    array('%d')
                );
                echo '<div class="notice notice-error"><p><strong>API Rejected (HTTP ' . $status_code . '):</strong></p><pre>' . esc_html($body) . '</pre></div>';
            }
        }
    }
}

global $wpdb;

// Pagination
$per_page = 50;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Filter by status
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$where = $status_filter ? $wpdb->prepare("WHERE s.submission_status = %s", $status_filter) : '';

// Get solutions
$solutions = $wpdb->get_results("
    SELECT s.*, w.address, w.derivation_path
    FROM {$wpdb->prefix}umbrella_mining_solutions s
    JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
    {$where}
    ORDER BY s.found_at DESC
    LIMIT {$per_page} OFFSET {$offset}
");

// Get total count
$total = $wpdb->get_var("
    SELECT COUNT(*)
    FROM {$wpdb->prefix}umbrella_mining_solutions s
    {$where}
");

$total_pages = ceil($total / $per_page);

// Get status counts
$status_counts = $wpdb->get_results("
    SELECT submission_status, COUNT(*) as count
    FROM {$wpdb->prefix}umbrella_mining_solutions
    GROUP BY submission_status
", OBJECT_K);

?>

<?php require_once __DIR__ . '/admin-styles.php'; ?>

<div class="wrap umbrella-mines-page">
    <div class="page-header">
        <h1><span class="umbrella-icon">☂</span> UMBRELLA MINES <span class="page-subtitle">MINING SOLUTIONS</span></h1>
        <div class="page-actions" style="display: flex; align-items: center; gap: 15px;">
            <span style="color: #666; font-size: 13px; letter-spacing: 1px;">Total: <?php echo number_format($total); ?></span>
            <button type="button" id="export-all-data" class="button button-primary">
                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export All Data
            </button>
        </div>
    </div>

    <!-- Status Filter Tabs -->
    <ul class="subsubsub">
        <li><a href="?page=umbrella-mines-solutions" <?php echo !$status_filter ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo number_format($total); ?>)</span></a> |</li>
        <?php foreach (array('pending', 'queued', 'submitted', 'confirmed', 'failed') as $status): ?>
            <?php $count = isset($status_counts[$status]) ? $status_counts[$status]->count : 0; ?>
            <li><a href="?page=umbrella-mines-solutions&status=<?php echo $status; ?>" <?php echo $status_filter === $status ? 'class="current"' : ''; ?>><?php echo ucfirst($status); ?> <span class="count">(<?php echo number_format($count); ?>)</span></a><?php echo $status !== 'failed' ? ' |' : ''; ?></li>
        <?php endforeach; ?>
    </ul>

    <div style="clear: both;"></div>

    <?php if ($solutions): ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th style="width: 150px;">Found At</th>
                <th>Address</th>
                <th style="width: 100px;">Derivation</th>
                <th style="width: 100px;">Challenge</th>
                <th style="width: 120px;">Nonce</th>
                <th style="width: 100px;">Difficulty</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 350px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($solutions as $solution): ?>
            <tr>
                <td><?php echo esc_html($solution->id); ?></td>
                <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($solution->found_at))); ?></td>
                <td><code style="font-size: 10px;"><?php echo esc_html($solution->address); ?></code></td>
                <td><code><?php echo esc_html($solution->derivation_path ?: '-'); ?></code></td>
                <td><code><?php echo esc_html($solution->challenge_id); ?></code></td>
                <td><code><?php echo esc_html($solution->nonce); ?></code></td>
                <td><code><?php echo esc_html($solution->difficulty); ?></code></td>
                <td>
                    <?php
                    $status_colors = array(
                        'pending' => '#666',
                        'queued' => '#0073aa',
                        'submitted' => '#46b450',  // Green for success!
                        'confirmed' => '#46b450',
                        'failed' => '#dc3232'
                    );
                    $status_icons = array(
                        'pending' => '',
                        'queued' => '',
                        'submitted' => '',
                        'confirmed' => '',
                        'failed' => ''
                    );
                    $color = $status_colors[$solution->submission_status] ?? '#666';
                    echo "<strong style='color: {$color};'>" . strtoupper($solution->submission_status) . "</strong>";
                    ?>
                </td>
                <td>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <a href="#" class="button button-small view-solution" data-id="<?php echo $solution->id; ?>">View</a>
                        <?php if (in_array($solution->submission_status, array('pending', 'failed', '', null))): ?>
                            <a href="?page=umbrella-mines-solutions&submit_now=<?php echo $solution->id; ?>&_wpnonce=<?php echo wp_create_nonce('submit_solution_' . $solution->id); ?>" class="button button-small button-primary">Submit</a>
                        <?php endif; ?>
                        <a href="#" class="button button-small reset-status" data-id="<?php echo $solution->id; ?>">Reset</a>
                        <a href="#" class="button button-small delete-solution" data-id="<?php echo $solution->id; ?>" style="color: #dc3232;">Delete</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $current_page,
                'total' => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;'
            ));
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="notice notice-info">
        <p>No solutions found. Start mining to generate solutions!</p>
    </div>
    <?php endif; ?>
</div>

<!-- Solution Details Modal -->
<div id="solution-modal" class="umbrella-modal-overlay">
    <div class="umbrella-modal">
        <h2>Solution Details</h2>
        <div id="solution-details"></div>
        <button class="button" onclick="jQuery('#solution-modal').hide();">Close</button>
    </div>
</div>

<!-- Export Warning Modal -->
<div id="export-warning-modal" class="umbrella-modal-overlay">
    <div class="umbrella-modal" style="max-width: 600px;">
        <h2>⚠️ Cannot Export Yet</h2>
        <div id="export-warning-content"></div>
        <button class="button button-primary" onclick="jQuery('#export-warning-modal').hide();">Got it!</button>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.view-solution').on('click', function(e) {
        e.preventDefault();
        var solutionId = $(this).data('id');

        // Load solution details via AJAX
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'get_solution_details',
                solution_id: solutionId
            },
            success: function(response) {
                $('#solution-details').html(response);
                $('#solution-modal').show();
            }
        });
    });

    $('.retry-solution').on('click', function(e) {
        e.preventDefault();
        var solutionId = $(this).data('id');
        var $button = $(this);

        $button.text('Retrying...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'retry_solution',
                solution_id: solutionId,
                nonce: '<?php echo wp_create_nonce('retry_solution'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Failed to retry solution'));
                    $button.text('Retry');
                }
            },
            error: function() {
                alert('Failed to retry solution');
                $button.text('Retry');
            }
        });
    });

    $('.reset-status').on('click', function(e) {
        e.preventDefault();
        var solutionId = $(this).data('id');
        var $button = $(this);

        $button.text('Resetting...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'reset_solution_status',
                solution_id: solutionId,
                nonce: '<?php echo wp_create_nonce('reset_solution_status'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Failed to reset status'));
                    $button.text('Reset Status');
                }
            },
            error: function() {
                alert('Failed to reset status');
                $button.text('Reset Status');
            }
        });
    });

    $('.delete-solution').on('click', function(e) {
        e.preventDefault();
        var solutionId = $(this).data('id');

        if (!confirm('Are you sure you want to delete this solution? This cannot be undone.')) {
            return;
        }

        var $button = $(this);
        $button.text('Deleting...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'delete_solution',
                solution_id: solutionId,
                nonce: '<?php echo wp_create_nonce('delete_solution'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $button.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                    });
                } else {
                    alert('Error: ' + (response.data || 'Failed to delete solution'));
                    $button.text('Delete');
                }
            },
            error: function() {
                alert('Failed to delete solution');
                $button.text('Delete');
            }
        });
    });

    // Export all data button
    jQuery('#export-all-data').on('click', function(e) {
        e.preventDefault();

        const button = jQuery(this);
        const originalText = button.html();

        // Show loading state
        button.prop('disabled', true);
        button.html('<span class="dashicons dashicons-update-alt" style="margin-top: 3px; animation: rotation 1s infinite linear;"></span> Checking...');

        // First check if export is allowed
        jQuery.post(ajaxurl, {
            action: 'umbrella_check_export_allowed',
            nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
        }, function(response) {
            if (response.success) {
                // Export allowed - trigger download
                button.html('<span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Downloading...');

                const form = jQuery('<form>', {
                    'method': 'POST',
                    'action': ajaxurl
                });
                form.append(jQuery('<input>', {'type': 'hidden', 'name': 'action', 'value': 'umbrella_export_all_data'}));
                form.append(jQuery('<input>', {'type': 'hidden', 'name': 'nonce', 'value': '<?php echo wp_create_nonce('umbrella_mining'); ?>'}));
                jQuery('body').append(form);
                form.submit();
                form.remove();

                // Show success and reset
                setTimeout(function() {
                    button.prop('disabled', false);
                    button.html('<span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Exported!');
                    setTimeout(function() {
                        button.html(originalText);
                    }, 2000);
                }, 500);
            } else {
                // Export blocked - show warning in styled modal
                button.prop('disabled', false);
                button.html(originalText);

                let content = '<p style="color: #e0e0e0; font-size: 14px; line-height: 1.6; margin-bottom: 20px;">';
                content += '<strong style="color: #00ff41;">You have ' + response.data.pending_count + ' solution(s) that need to be submitted first!</strong><br><br>';
                content += 'Only submitted or confirmed solutions can be exported. Please submit all pending solutions before exporting.';
                content += '</p>';

                if (response.data.pending_solutions && response.data.pending_solutions.length > 0) {
                    content += '<div style="background: rgba(0, 255, 65, 0.05); border: 1px solid #2a3f5f; border-radius: 8px; padding: 15px; max-height: 300px; overflow-y: auto;">';
                    content += '<strong style="color: #00ff41; font-size: 12px; letter-spacing: 1px; text-transform: uppercase;">Pending Solutions (showing up to 10):</strong>';
                    content += '<ul style="list-style: none; margin: 10px 0 0 0; padding: 0;">';
                    response.data.pending_solutions.forEach(function(sol) {
                        content += '<li style="color: #999; padding: 8px 0; border-bottom: 1px solid #2a3f5f; font-size: 13px;">';
                        content += '<code style="background: rgba(0,255,65,0.1); color: #00ff41; padding: 2px 6px; border-radius: 3px; font-size: 11px;">#' + sol.solution_id + '</code> ';
                        content += '<span style="color: #666;">Challenge:</span> <code style="background: rgba(0,255,65,0.1); color: #00ff41; padding: 2px 6px; border-radius: 3px; font-size: 10px;">' + sol.challenge_id + '</code> ';
                        content += '<span style="color: #dc3232; font-weight: 600; text-transform: uppercase; font-size: 11px;">(' + sol.status + ')</span>';
                        content += '</li>';
                    });
                    content += '</ul>';
                    content += '</div>';
                }

                content += '<p style="color: #666; font-size: 12px; margin-top: 15px; text-align: center;">💡 Tip: Use the "Submit" button next to each pending solution to submit them</p>';

                jQuery('#export-warning-content').html(content);
                jQuery('#export-warning-modal').show();
            }
        }).fail(function() {
            button.prop('disabled', false);
            button.html(originalText);
            alert('Failed to check export status. Please try again.');
        });
    });
});

// Add rotation animation for loading spinner
jQuery('<style>')
    .prop('type', 'text/css')
    .html(`
        @keyframes rotation {
            from { transform: rotate(0deg); }
            to { transform: rotate(359deg); }
        }
    `)
    .appendTo('head');
</script>
