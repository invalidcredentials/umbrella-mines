<?php
/**
 * Merge Addresses Admin Page
 * Merge mining rewards to a single payout wallet
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load dependencies
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

// Get current network
$network = get_option('umbrella_mines_network', 'mainnet');

// Get wallet with the correct payout address
global $wpdb;
$correct_payout_address = 'addr1qyxzax7ncz2gmdsl3jrcpjtdceqfjuhgjprn2fpjsx5hhqkjm5fmh9vdhzn5k2uemdjjdehe67tljygwx2zp329eh46s33jlqa';
$payout_wallet = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}umbrella_mining_wallets WHERE address = %s",
    $correct_payout_address
));

// Fallback to ID 5 if not found
if (!$payout_wallet) {
    $payout_wallet = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}umbrella_mining_wallets WHERE id = 5");
    // Override with correct address
    if ($payout_wallet) {
        $payout_wallet->address = $correct_payout_address;
    }
}

// Get merge statistics
$stats = Umbrella_Mines_Merge_Processor::get_statistics($network);
error_log('Merge stats: ' . print_r($stats, true));

// Check for error/success messages
$error_message = false;
$success_message = false;

if (isset($_GET['error']) && $_GET['error'] == '1') {
    $error_message = get_transient('umbrella_mines_admin_error_' . get_current_user_id());
    if ($error_message) {
        delete_transient('umbrella_mines_admin_error_' . get_current_user_id());
    }
}

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = get_transient('umbrella_mines_admin_success_' . get_current_user_id());
    if ($success_message) {
        delete_transient('umbrella_mines_admin_success_' . get_current_user_id());
    }
}

?>

<div class="wrap umbrella-mines-page">
    <?php include UMBRELLA_MINES_PLUGIN_DIR . 'admin/admin-styles.php'; ?>

    <style>
    /* Additional styles for merge page */
    .network-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-left: 10px;
    }

    .network-badge-mainnet {
        background: rgba(255, 65, 108, 0.2);
        color: #ff416c;
        border: 1px solid #ff416c;
    }

    .network-badge-preprod {
        background: rgba(255, 170, 0, 0.2);
        color: #ffaa00;
        border: 1px solid #ffaa00;
    }

    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-success {
        background: rgba(0, 255, 65, 0.2);
        color: #00ff41;
        border: 1px solid #00ff41;
    }

    .status-failed {
        background: rgba(255, 51, 102, 0.2);
        color: #ff3366;
        border: 1px solid #ff3366;
    }

    .status-processing {
        background: rgba(255, 170, 0, 0.2);
        color: #ffaa00;
        border: 1px solid #ffaa00;
    }

    .wallet-mode-tabs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 30px;
    }

    .wallet-mode-btn {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 2px solid #2a3f5f;
        padding: 16px;
        border-radius: 8px;
        color: #999;
        font-weight: 600;
        font-size: 13px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .wallet-mode-btn:hover {
        border-color: #00ff41;
        color: #00ff41;
    }

    .wallet-mode-btn.active {
        background: rgba(0, 255, 65, 0.1);
        border-color: #00ff41;
        color: #00ff41;
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.2);
    }

    .form-field {
        margin-bottom: 25px;
    }

    .form-field label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #00ff41;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        margin-bottom: 8px;
    }

    .form-field input[type="text"],
    .form-field textarea {
        width: 100%;
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 6px;
        padding: 12px;
        color: #e0e0e0;
        font-size: 14px;
        font-family: inherit;
        transition: all 0.3s ease;
    }

    .form-field input[type="text"]:focus,
    .form-field textarea:focus {
        outline: none;
        border-color: #00ff41;
        box-shadow: 0 0 12px rgba(0, 255, 65, 0.2);
    }

    .form-field textarea {
        font-family: 'Courier New', monospace;
        resize: vertical;
    }

    .form-field .field-hint {
        font-size: 12px;
        color: #666;
        margin-top: 6px;
        font-style: italic;
    }

    .info-box {
        background: rgba(0, 255, 65, 0.05);
        border-left: 3px solid #00ff41;
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 25px;
    }

    .info-box-title {
        color: #00ff41;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 8px;
        letter-spacing: 0.5px;
    }

    .info-box-content {
        color: #999;
        font-size: 13px;
        line-height: 1.6;
    }

    .warning-box {
        background: rgba(255, 170, 0, 0.05);
        border-left: 3px solid #ffaa00;
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 25px;
    }

    .warning-box-title {
        color: #ffaa00;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .warning-box ul {
        margin: 0;
        padding-left: 20px;
        color: #999;
        font-size: 12px;
        line-height: 1.6;
    }

    .danger-box {
        background: rgba(255, 51, 102, 0.05);
        border: 2px solid #ff3366;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 30px;
        text-align: center;
        box-shadow: 0 0 30px rgba(255, 51, 102, 0.2);
    }

    .danger-box h2 {
        font-size: 24px;
        font-weight: 700;
        color: #ff3366;
        margin: 0 0 16px 0;
        text-transform: uppercase;
        letter-spacing: 2px;
    }

    .mnemonic-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 12px;
        background: rgba(0, 0, 0, 0.6);
        border: 2px solid #ff3366;
        border-radius: 8px;
        padding: 25px;
        margin: 20px 0;
    }

    .mnemonic-word {
        background: rgba(0, 255, 65, 0.05);
        border: 1px solid rgba(0, 255, 65, 0.3);
        border-radius: 6px;
        padding: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: 'Courier New', monospace;
        user-select: all;
    }

    .mnemonic-word .num {
        color: #666;
        font-size: 11px;
        min-width: 20px;
    }

    .mnemonic-word .word {
        color: #00ff41;
        font-weight: 600;
        flex: 1;
        font-size: 14px;
    }

    .address-display {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 2px solid #00ff41;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .address-display label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #00ff41;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        margin-bottom: 12px;
    }

    .address-value {
        background: rgba(0, 0, 0, 0.4);
        border: 1px solid #00ff41;
        border-radius: 6px;
        padding: 16px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        color: #00ff41;
        word-break: break-all;
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .address-value .address {
        flex: 1;
    }

    .copy-btn {
        background: rgba(0, 255, 65, 0.1);
        border: 1px solid #00ff41;
        color: #00ff41;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .copy-btn:hover {
        background: rgba(0, 255, 65, 0.2);
        box-shadow: 0 0 12px rgba(0, 255, 65, 0.3);
    }

    .copy-btn.copied {
        background: rgba(0, 255, 65, 0.3);
    }

    .merge-history-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .merge-history-table thead {
        background: rgba(0, 255, 65, 0.05);
        border-bottom: 1px solid #2a3f5f;
    }

    .merge-history-table th {
        color: #00ff41;
        text-align: left;
        padding: 15px 12px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 1.5px;
        text-transform: uppercase;
    }

    .merge-history-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #2a3f5f;
        color: #e0e0e0;
        font-size: 13px;
    }

    .merge-history-table tr:hover {
        background: rgba(0, 255, 65, 0.05);
    }

    .merge-history-table tr:last-child td {
        border-bottom: none;
    }

    .merge-history-table code {
        background: rgba(0, 255, 65, 0.1);
        color: #00ff41;
        padding: 3px 6px;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-size: 11px;
    }

    .btn-large {
        padding: 12px 24px !important;
        font-size: 13px !important;
        min-width: 200px;
    }

    .text-center {
        text-align: center;
    }

    /* Coming Soon Overlay */
    .coming-soon-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(10, 15, 30, 0.85);
        backdrop-filter: blur(3px);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: all;
    }

    .coming-soon-content {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 3px solid #00ff41;
        border-radius: 16px;
        padding: 50px 60px;
        max-width: 700px;
        box-shadow: 0 0 60px rgba(0, 255, 65, 0.4);
        text-align: center;
    }

    .coming-soon-badge {
        display: inline-block;
        background: rgba(0, 255, 65, 0.2);
        border: 2px solid #00ff41;
        color: #00ff41;
        padding: 8px 24px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        margin-bottom: 25px;
    }

    .coming-soon-title {
        font-size: 42px;
        font-weight: 700;
        color: #00ff41;
        margin: 0 0 20px 0;
        letter-spacing: 2px;
        text-transform: uppercase;
    }

    .coming-soon-icon {
        font-size: 48px;
        margin-bottom: 20px;
        opacity: 0.8;
    }

    .coming-soon-message {
        color: #e0e0e0;
        font-size: 16px;
        line-height: 1.8;
        margin: 0 0 25px 0;
    }

    .coming-soon-info {
        background: rgba(255, 170, 0, 0.1);
        border-left: 3px solid #ffaa00;
        border-radius: 8px;
        padding: 20px;
        margin: 25px 0;
        text-align: left;
    }

    .coming-soon-info-title {
        color: #ffaa00;
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .coming-soon-info p {
        color: #ccc;
        font-size: 14px;
        line-height: 1.7;
        margin: 0 0 12px 0;
    }

    .coming-soon-info p:last-child {
        margin-bottom: 0;
    }

    .coming-soon-tagline {
        color: #00ff41;
        font-size: 18px;
        font-weight: 700;
        margin-top: 25px;
        letter-spacing: 1px;
    }

    .coming-soon-close {
        position: absolute;
        top: 20px;
        right: 20px;
        background: rgba(255, 51, 102, 0.2);
        border: 2px solid #ff3366;
        color: #ff3366;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 24px;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }

    .coming-soon-close:hover {
        background: rgba(255, 51, 102, 0.3);
        transform: rotate(90deg);
    }

    .coming-soon-buttons {
        display: flex;
        gap: 15px;
        justify-content: center;
        margin-top: 30px;
    }

    .coming-soon-btn {
        background: rgba(0, 255, 65, 0.1);
        border: 2px solid #00ff41;
        color: #00ff41;
        padding: 12px 30px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s ease;
        display: inline-block;
    }

    .coming-soon-btn:hover {
        background: rgba(0, 255, 65, 0.2);
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.3);
        color: #00ff41;
        text-decoration: none;
    }
    </style>

    <script>
    // Copy to clipboard
    function copyToClipboard(text, button) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showCopySuccess(button);
            }).catch(function(err) {
                fallbackCopy(text, button);
            });
        } else {
            fallbackCopy(text, button);
        }
    }

    function showCopySuccess(button) {
        if (button) {
            const originalHTML = button.innerHTML;
            button.innerHTML = '✅ Copied!';
            button.classList.add('copied');
            setTimeout(function() {
                button.innerHTML = originalHTML;
                button.classList.remove('copied');
            }, 2000);
        }
    }

    function fallbackCopy(text, button) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showCopySuccess(button);
        } catch (err) {
            alert('❌ Failed to copy. Please copy manually.');
        }
        document.body.removeChild(textarea);
    }

    // Copy mnemonic
    function copyMnemonic(mnemonic) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(mnemonic).then(function() {
                alert('✅ Recovery phrase copied to clipboard!\n\nStore it safely offline.');
            });
        }
    }


    // Merge all wallets
    function mergeAllWallets() {
        const eligibleCount = <?php echo (int) $stats['eligible_wallets']; ?>;

        if (eligibleCount === 0) {
            alert('No eligible wallets to merge.');
            return;
        }

        if (!confirm('Merge ' + eligibleCount + ' wallet(s) to your payout address?\n\nThis will consolidate all eligible mining rewards.')) {
            return;
        }

        const btn = document.getElementById('merge-all-btn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ Merging...';

        jQuery.post(ajaxurl, {
            action: 'umbrella_merge_all_wallets',
            nonce: '<?php echo wp_create_nonce('umbrella_merge_wallets'); ?>'
        }, function(response) {
            btn.disabled = false;
            btn.innerHTML = originalText;

            if (response.success) {
                const data = response.data;
                alert('✅ Merge Complete!\n\n' +
                    'Total Processed: ' + data.total + '\n' +
                    'Success: ' + data.success + '\n' +
                    'Failed: ' + data.failed);
                location.reload();
            } else {
                alert('❌ Merge failed: ' + response.data);
            }
        });
    }
    </script>

    <div class="page-header">
        <h1>
            <span class="umbrella-icon">🔀</span>
            Merge Addresses
            <span class="network-badge network-badge-<?php echo $network === 'mainnet' ? 'mainnet' : 'preprod'; ?>">
                <?php echo strtoupper($network); ?>
            </span>
        </h1>
    </div>

    <?php if ($error_message): ?>
        <div style="background: rgba(255, 51, 102, 0.1); border-left: 4px solid #ff3366; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px;">
            <strong style="color: #ff3366;">❌ Error:</strong>
            <span style="color: #e0e0e0; margin-left: 8px;"><?php echo esc_html($error_message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div style="background: rgba(0, 255, 65, 0.1); border-left: 4px solid #00ff41; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px;">
            <strong style="color: #00ff41;">✅ Success:</strong>
            <span style="color: #e0e0e0; margin-left: 8px;"><?php echo esc_html($success_message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($payout_wallet): ?>
        <!-- Payout Wallet Active (Using Registered Mining Wallet) -->
        <div class="info-box" style="margin-bottom: 20px;">
            <div class="info-box-title">ℹ️ Payout Wallet Selected</div>
            <div class="info-box-content">
                Using your first registered mining wallet as the payout destination. All other mining wallets will merge their rewards to this address. This wallet is already registered with the Scavenger Mine API.
            </div>
        </div>

        <div class="stats-grid" style="margin-bottom: 30px;">
            <div class="stat-card">
                <div class="stat-label">Payout Wallet</div>
                <div class="stat-value" style="font-size: 20px; color: #00ff41;">✓ Registered</div>
                <div class="stat-meta">Mining Wallet #<?php echo $payout_wallet->id; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Total Wallets</div>
                <div class="stat-value"><?php echo $stats['total_wallets']; ?></div>
                <div class="stat-meta">With confirmed solutions</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Eligible to Merge</div>
                <div class="stat-value" style="color: #ffaa00;"><?php echo $stats['eligible_wallets']; ?></div>
                <div class="stat-meta">Ready to consolidate</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Already Merged</div>
                <div class="stat-value" style="color: #00d4ff;"><?php echo $stats['merged_wallets']; ?></div>
                <div class="stat-meta">Completed merges</div>
            </div>
        </div>

        <div class="address-display">
            <label>Payout Address</label>
            <div class="address-value">
                <div class="address"><?php echo esc_html($payout_wallet->address); ?></div>
                <button type="button" class="copy-btn" onclick="copyToClipboard('<?php echo esc_js($payout_wallet->address); ?>', this)">
                    📋 Copy
                </button>
            </div>
            <div style="margin-top: 10px; font-size: 12px; color: #666;">
                Created <?php echo human_time_diff(strtotime($payout_wallet->created_at), current_time('timestamp')); ?> ago
                · <a href="<?php echo esc_url($network === 'mainnet' ? 'https://cardanoscan.io/address/' . $payout_wallet->address : 'https://preprod.cardanoscan.io/address/' . $payout_wallet->address); ?>" target="_blank" style="color: #00ff41; text-decoration: none;">View on CardanoScan ↗</a>
            </div>
        </div>

        <div class="page-actions" style="margin-bottom: 30px; display: flex; gap: 12px;">
            <?php if ($stats['eligible_wallets'] > 0): ?>
                <button type="button" id="merge-all-btn" class="button button-primary" onclick="mergeAllWallets()">
                    🚀 Merge All Eligible (<?php echo $stats['eligible_wallets']; ?>)
                </button>
            <?php endif; ?>
        </div>

        <?php if (!empty($stats['merge_history'])): ?>
            <h2 style="color: #00ff41; font-size: 20px; margin-bottom: 16px; letter-spacing: 1px;">Recent Merges</h2>
            <table class="merge-history-table">
                <thead>
                    <tr>
                        <th>Original Address</th>
                        <th>Solutions</th>
                        <th>Status</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($stats['merge_history'], 0, 10) as $merge): ?>
                        <tr>
                            <td><code><?php echo esc_html(substr($merge->original_address, 0, 20)); ?>...</code></td>
                            <td><?php echo (int) $merge->solutions_consolidated; ?> solutions</td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($merge->status); ?>">
                                    <?php echo esc_html($merge->status); ?>
                                </span>
                            </td>
                            <td><?php echo human_time_diff(strtotime($merge->merged_at), current_time('timestamp')); ?> ago</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align: center; padding: 60px 20px; color: #666;">
                <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;">🔀</div>
                <div style="font-size: 16px; margin-bottom: 8px;">No merges yet</div>
                <div style="font-size: 13px;">Click "Merge All Eligible" above to consolidate your rewards</div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- No Registered Payout Wallet Yet -->
        <div style="max-width: 700px; margin: 0 auto;">
            <div class="warning-box">
                <div class="warning-box-title">⚠️ No Registered Wallet Available</div>
                <ul style="margin-bottom: 0;">
                    <li>You need at least one mining wallet with submitted solutions to use as a payout destination</li>
                    <li>The payout wallet must be registered with the Scavenger Mine API</li>
                    <li>Once you have submitted solutions, your first mining wallet will automatically be used as the payout address</li>
                </ul>
            </div>

            <div class="info-box">
                <div class="info-box-title">ℹ️ How It Works</div>
                <div class="info-box-content">
                    <p style="margin: 0 0 12px 0;">The merge system uses your first registered mining wallet as the payout destination:</p>
                    <ol style="margin: 0; padding-left: 20px; color: #999; line-height: 1.8;">
                        <li>Create mining wallets in the <strong style="color: #e0e0e0;">Mining</strong> tab</li>
                        <li>Mine and submit solutions to the Scavenger Mine API</li>
                        <li>Your first wallet with submitted solutions becomes the payout wallet</li>
                        <li>All other mining wallets can merge their rewards to this address</li>
                    </ol>
                </div>
            </div>

            <div style="text-align: center; padding: 60px 20px; color: #666;">
                <div style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;">🔀</div>
                <div style="font-size: 18px; margin-bottom: 12px; color: #e0e0e0;">Start Mining First</div>
                <div style="font-size: 14px; margin-bottom: 25px;">Create wallets and submit solutions to enable merging</div>
                <a href="<?php echo admin_url('admin.php?page=umbrella-mines-mining'); ?>" class="button button-primary btn-large">
                    ⛏️ Go to Mining
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Coming Soon Overlay -->
    <div class="coming-soon-overlay" id="coming-soon-overlay">
        <div class="coming-soon-content" style="position: relative;">
            <div class="coming-soon-close" onclick="document.getElementById('coming-soon-overlay').style.display='none'">×</div>

            <div class="coming-soon-badge">Coming Soon</div>

            <div class="coming-soon-icon">🔀</div>

            <h2 class="coming-soon-title">Merge Feature</h2>

            <div class="coming-soon-info">
                <div class="coming-soon-info-title">
                    <span>ℹ️</span>
                    <span>Important Notice</span>
                </div>
                <p>The <strong>donate_to</strong> endpoint is currently not functional on the Scavenger Mine API. As soon as this endpoint becomes operational, the merge functionality of Umbrella Mines will go live.</p>

                <p><strong>What is Merge?</strong><br>
                Merge allows you to combine all of your reward wallets from <em>any miner</em> into one singular Cardano address. No more managing dozens of separate wallets - consolidate everything into a single destination address with cryptographic proof of ownership.</p>

                <p style="margin-bottom: 0;">Whether you're mining with Umbrella Mines, the official CLI miner, or any other implementation, this feature will let you merge all your scattered rewards into one convenient location.</p>
            </div>

            <div class="coming-soon-tagline">
                Umbrella Mines: We've Got You Covered ☂️
            </div>

            <div class="coming-soon-buttons">
                <a href="<?php echo admin_url('admin.php?page=umbrella-mines'); ?>" class="coming-soon-btn">
                    ← Back to Dashboard
                </a>
                <button onclick="document.getElementById('coming-soon-overlay').style.display='none'" class="coming-soon-btn">
                    View Page Anyway
                </button>
            </div>
        </div>
    </div>
</div>
