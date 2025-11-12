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

// Get wallets with merge status
$wallets = $wpdb->get_results("
    SELECT w.*,
           (SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE wallet_id = w.id) as solution_count,
           (SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE wallet_id = w.id AND submission_status = 'confirmed') as confirmed_count,
           m.id as merge_id,
           m.status as merge_status,
           m.payout_address as merge_payout_address,
           m.merged_at as merge_merged_at
    FROM {$wpdb->prefix}umbrella_mining_wallets w
    LEFT JOIN {$wpdb->prefix}umbrella_mining_merges m ON w.id = m.original_wallet_id AND m.status = 'success'
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
                <th style="width: 100px;">Merge Status</th>
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
                    <?php if ($wallet->merge_id): ?>
                        <span style="display: inline-block; padding: 4px 8px; background: rgba(0, 255, 65, 0.2); color: #00ff41; border: 1px solid #00ff41; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase;">✅ Merged</span>
                        <br><span style="color: #666; font-size: 10px;"><?php echo date('Y-m-d H:i', strtotime($wallet->merge_merged_at)); ?></span>
                    <?php else: ?>
                        <span style="color: #666;">-</span>
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
    <div class="umbrella-modal" style="position: relative;">
        <button onclick="jQuery('#wallet-modal').hide();" style="position: absolute; top: 20px; right: 20px; background: rgba(255, 51, 102, 0.2); border: 2px solid #ff3366; color: #ff3366; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; font-size: 24px; font-weight: bold; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">×</button>
        <h2>Wallet Details</h2>
        <div id="wallet-details"></div>
        <div style="text-align: center; margin-top: 30px;">
            <button class="button button-primary" onclick="jQuery('#wallet-modal').hide();" style="background: linear-gradient(135deg, #00ff41 0%, #00d435 100%) !important; border: none !important; color: #000 !important; font-size: 14px !important; font-weight: 700 !important; padding: 12px 30px !important; border-radius: 8px !important; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 15px rgba(0, 255, 65, 0.3); transition: all 0.3s ease;">Close</button>
        </div>
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

<style>
#wallet-modal .umbrella-modal > button:first-child:hover {
    background: rgba(255, 51, 102, 0.3) !important;
    transform: rotate(90deg);
}
</style>

<script>
jQuery(document).ready(function($) {
    $('.view-wallet').on('click', function(e) {
        e.preventDefault();
        var walletId = $(this).data('id');

        // Load wallet details via AJAX
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'get_wallet_details',
                wallet_id: walletId
            },
            success: function(response) {
                $('#wallet-details').html(response);
                $('#wallet-modal').show();
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
