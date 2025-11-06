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
        <h1>MINING WALLETS</h1>
        <div class="page-actions">
            <span style="color: #666; font-size: 13px; letter-spacing: 1px;">Total: <?php echo number_format($total); ?> | Registered: <?php echo number_format($registered_count); ?></span>
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
<div id="wallet-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; max-width: 800px; max-height: 80vh; overflow-y: auto; border-radius: 4px;">
        <h2>Wallet Details</h2>
        <div id="wallet-details"></div>
        <button class="button" onclick="jQuery('#wallet-modal').hide();">Close</button>
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
});
</script>
