<?php
/**
 * Wallets Page - View all generated wallets
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Pagination
$per_page = 50;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Get wallets
$wallets = $wpdb->get_results("
    SELECT w.*,
           (SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE wallet_id = w.id) as solution_count,
           (SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE wallet_id = w.id AND submission_status = 'confirmed') as confirmed_count
    FROM {$wpdb->prefix}umbrella_mining_wallets w
    ORDER BY w.created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
");

// Get total count
$total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets");
$total_pages = ceil($total / $per_page);

// Get stats
$registered_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets WHERE registered_at IS NOT NULL");

?>

<?php require_once __DIR__ . '/admin-styles.php'; ?>

<div class="wrap umbrella-mines-page">
    <div class="page-header">
        <h1><span class="umbrella-icon">☂</span> UMBRELLA MINES <span class="page-subtitle">MINING WALLETS</span></h1>
        <div class="page-actions" style="display: flex; align-items: center; gap: 15px;">
            <span style="color: #666; font-size: 13px; letter-spacing: 1px;">Total: <?php echo number_format($total); ?> | Registered: <?php echo number_format($registered_count); ?></span>
            <button type="button" id="export-all-data" class="button button-primary">
                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export All Data
            </button>
        </div>
    </div>

    <?php if ($wallets): ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 50px;">ID</th>
                <th style="width: 150px;">Created At</th>
                <th>Address</th>
                <th style="width: 100px;">Network</th>
                <th style="width: 100px;">Registered</th>
                <th style="width: 100px;">Solutions</th>
                <th style="width: 80px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($wallets as $wallet): ?>
            <tr>
                <td><?php echo esc_html($wallet->id); ?></td>
                <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($wallet->created_at))); ?></td>
                <td><code style="font-size: 10px;"><?php echo esc_html($wallet->address); ?></code></td>
                <td><?php echo esc_html($wallet->network); ?></td>
                <td>
                    <?php if ($wallet->registered_at): ?>
                        <span style="color: #00ff41;"><?php echo date('Y-m-d H:i', strtotime($wallet->registered_at)); ?></span>
                    <?php else: ?>
                        <span style="color: #666;">NOT REGISTERED</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo number_format($wallet->solution_count); ?> total
                    <?php if ($wallet->confirmed_count > 0): ?>
                        <br><strong style="color: #00ff41;"><?php echo number_format($wallet->confirmed_count); ?> confirmed</strong>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="#" class="button button-small view-wallet" data-id="<?php echo $wallet->id; ?>">View</a>
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
        <p>No wallets generated yet. Start mining to create ephemeral wallets!</p>
    </div>
    <?php endif; ?>
</div>

<!-- Wallet Details Modal -->
<div id="wallet-modal" class="umbrella-modal-overlay">
    <div class="umbrella-modal">
        <h2>Wallet Details</h2>
        <div id="wallet-details"></div>
        <button class="button" onclick="jQuery('#wallet-modal').hide();">Close</button>
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
    $('.view-wallet').on('click', function(e) {
        e.preventDefault();
        var walletId = $(this).data('id');

        // For now, just show basic info
        // TODO: Add AJAX handler to load full wallet details
        alert('Wallet details view coming soon! Wallet ID: ' + walletId);
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
                content += 'Only submitted or confirmed solutions can be exported. Please go to the Solutions page and submit all pending solutions.';
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

                content += '<p style="color: #666; font-size: 12px; margin-top: 15px; text-align: center;">💡 Tip: Go to <a href="?page=umbrella-mines-solutions" style="color: #00ff41; text-decoration: none;">Solutions page</a> to submit pending solutions</p>';

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
