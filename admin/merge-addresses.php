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

// Get dynamically selected payout wallet (must have: receipt, mnemonic, not merged, registered)
$payout_wallet = Umbrella_Mines_Merge_Processor::get_registered_payout_wallet($network);

// Check if this is an imported wallet (from payout_wallet table) vs auto-selected (from mining_wallets table)
$is_imported_wallet = false;
if ($payout_wallet) {
    // If it has wallet_name field, it's from payout_wallet table (imported)
    $is_imported_wallet = property_exists($payout_wallet, 'wallet_name');
}

// Get merge statistics with pagination (default page 1)
$stats = Umbrella_Mines_Merge_Processor::get_statistics($network, 1, 10);
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
        font-size: 38px;
        font-weight: 700;
        color: #00ff41;
        margin: 0 0 25px 0;
        letter-spacing: 3px;
        text-transform: uppercase;
        text-shadow: 0 0 20px rgba(0, 255, 65, 0.6);
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
        margin-top: 30px;
        font-size: 22px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
    }

    .coming-soon-tagline .umbrella-float {
        font-size: 32px;
        color: #00ff41;
        text-shadow: 0 0 15px rgba(0, 255, 65, 0.7);
        display: inline-block;
        animation: float 3s ease-in-out infinite;
        margin-right: 8px;
    }

    .coming-soon-tagline .tagline-text {
        color: #00ff41;
        text-shadow: 0 0 12px rgba(0, 255, 65, 0.5);
    }

    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-5px); }
    }

    @keyframes anvil-pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
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

    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 26px;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #333;
        transition: .3s;
        border-radius: 26px;
    }
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .3s;
        border-radius: 50%;
    }
    .toggle-switch input:checked + .toggle-slider {
        background-color: #00d4ff;
    }
    .toggle-switch input:checked + .toggle-slider:before {
        transform: translateX(24px);
    }

    /* Import Wallet Styling */
    #import-mnemonic:focus {
        outline: none;
        border-color: #00d4ff;
        box-shadow: 0 0 20px rgba(0, 212, 255, 0.4), inset 0 2px 8px rgba(0, 0, 0, 0.5);
    }
    #import-wallet-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 212, 255, 0.5);
    }
    #import-wallet-btn:active {
        transform: translateY(0);
    }
    #import-wallet-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
    }
    </style>

    <script>
    // Toggle import wallet section
    jQuery(document).ready(function($) {
        $('#import-wallet-toggle').on('change', function() {
            $('#import-wallet-section').slideToggle(300);
        });

        // Import wallet button
        $('#import-wallet-btn').on('click', function() {
            const mnemonic = $('#import-mnemonic').val().trim();
            const derivationPath = $('#import-derivation-path').val().trim() || '0/0/0';

            if (!mnemonic) {
                $('#import-status').html('<span style="color: #ff0000;">❌ Please enter your mnemonic phrase</span>');
                return;
            }

            // Basic validation: 24 words
            const words = mnemonic.split(/\s+/).filter(w => w.length > 0);
            if (words.length !== 24) {
                $('#import-status').html('<span style="color: #ff0000;">❌ Must be exactly 24 words (found ' + words.length + ')</span>');
                return;
            }

            // Validate derivation path format
            if (!/^\d+\/\d+\/\d+$/.test(derivationPath)) {
                $('#import-status').html('<span style="color: #ff0000;">❌ Invalid derivation path format. Use: 0/0/0</span>');
                return;
            }

            $('#import-wallet-btn').prop('disabled', true);
            $('#import-status').html('<span style="color: #ffaa00;">⏳ Deriving wallet and verifying registration...</span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'umbrella_import_payout_wallet',
                    nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>',
                    mnemonic: mnemonic,
                    derivation_path: derivationPath
                },
                success: function(response) {
                    $('#import-wallet-btn').prop('disabled', false);

                    if (response.success) {
                        $('#import-status').html('<span style="color: #00ff41; text-shadow: 0 0 10px rgba(0, 255, 65, 0.5);">✅ ' + response.data.message + '</span>');

                        var warningHtml = '';
                        if (!response.data.found_in_database) {
                            warningHtml = '<div style="margin-top: 12px; padding: 10px; background: rgba(255, 170, 0, 0.15); border-left: 3px solid #ffaa00; font-size: 12px; color: #ffaa00;">' +
                                '<strong>⚠️ Warning:</strong> Using custom derivation path (not found in database). Please verify this is correct.' +
                                '</div>';
                        }

                        $('#import-result').html(
                            '<div style="background: rgba(0, 255, 65, 0.1); border: 2px solid #00ff41; border-left: 4px solid #00ff41; color: #fff; padding: 16px; box-shadow: 0 0 20px rgba(0, 255, 65, 0.2); word-wrap: break-word; overflow-wrap: break-word;">' +
                            '<div style="font-size: 16px; margin-bottom: 12px; color: #00ff41; font-weight: 600;">🎉 Wallet Imported Successfully!</div>' +
                            '<div style="font-family: monospace; font-size: 13px; line-height: 1.8;">' +
                            '<div><strong style="color: #00ff41;">Address:</strong> <span style="color: #fff; word-break: break-all;">' + response.data.address + '</span></div>' +
                            '<div><strong style="color: #00ff41;">Derivation Path:</strong> <span style="color: #fff;">m/1852\'/1815\'/' + response.data.derivation_path + '</span>' + (response.data.found_in_database ? ' <span style="color: #00ff41;">✓ Found in DB</span>' : '') + '</div>' +
                            '<div><strong style="color: #00ff41;">Network:</strong> <span style="color: #fff;">' + response.data.network + '</span></div>' +
                            '<div><strong style="color: #00ff41;">Registration:</strong> <span style="color: ' + (response.data.is_registered ? '#00ff41' : '#ffaa00') + ';">' + (response.data.is_registered ? '✅ Already Registered' : '⚠️ Not Registered Yet') + '</span></div>' +
                            '</div>' +
                            warningHtml +
                            '<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(0, 255, 65, 0.2); font-size: 12px; color: #aaa;">Reloading page...</div>' +
                            '</div>'
                        ).fadeIn();

                        // Reload page after 2 seconds to show new payout wallet
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#import-status').html('<span style="color: #ff4444; text-shadow: 0 0 10px rgba(255, 68, 68, 0.5);">❌ Import Failed</span>');
                        $('#import-result').html(
                            '<div style="background: rgba(255, 68, 68, 0.1); border: 2px solid #ff4444; border-left: 4px solid #ff4444; color: #fff; padding: 16px; box-shadow: 0 0 20px rgba(255, 68, 68, 0.2); word-wrap: break-word; overflow-wrap: break-word;">' +
                            '<div style="font-size: 16px; margin-bottom: 8px; color: #ff4444; font-weight: 600;">⚠️ Import Error</div>' +
                            '<div style="color: #ffaaaa; font-size: 14px; word-break: break-word;">' + response.data + '</div>' +
                            '</div>'
                        ).fadeIn();
                    }
                },
                error: function() {
                    $('#import-wallet-btn').prop('disabled', false);
                    $('#import-status').html('<span style="color: #ff0000;">❌ Network error</span>');
                }
            });
        });

        // Clear imported wallet button
        $('#clear-imported-wallet-btn').on('click', function() {
            if (!confirm('Remove imported wallet and return to auto-select mode?')) {
                return;
            }

            const btn = $(this);
            btn.prop('disabled', true).html('⏳ Removing...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'umbrella_clear_imported_wallet',
                    nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('✅ Imported wallet removed! Reloading...');
                        location.reload();
                    } else {
                        alert('❌ Error: ' + response.data);
                        btn.prop('disabled', false).html('❌ Remove Imported Wallet');
                    }
                },
                error: function() {
                    alert('❌ Network error');
                    btn.prop('disabled', false).html('❌ Remove Imported Wallet');
                }
            });
        });
    });

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

        // Show progress container
        const progressDiv = document.getElementById('merge-all-progress');
        const btn = document.getElementById('merge-all-btn');
        const originalText = btn.innerHTML;

        // Lock UI
        btn.disabled = true;
        btn.style.opacity = '0.5';
        progressDiv.style.display = 'block';

        // Set initial state
        document.getElementById('merge-total-count').textContent = eligibleCount;
        document.getElementById('merge-current-count').textContent = '0';
        document.getElementById('merge-success-count').textContent = '0';
        document.getElementById('merge-fail-count').textContent = '0';
        document.getElementById('merge-progress-percent').textContent = '0%';
        document.getElementById('merge-progress-bar').style.width = '0%';
        document.getElementById('merge-progress-bar').textContent = '';

        // Simulate progress for better UX (since backend is synchronous)
        let fakeProgress = 0;
        const fakeProgressInterval = setInterval(() => {
            fakeProgress += Math.random() * 15;
            if (fakeProgress > 90) fakeProgress = 90; // Cap at 90% until real completion

            const progressBar = document.getElementById('merge-progress-bar');
            progressBar.style.width = fakeProgress + '%';
            if (fakeProgress > 10) {
                progressBar.textContent = Math.round(fakeProgress) + '%';
            }
            document.getElementById('merge-progress-percent').textContent = Math.round(fakeProgress) + '%';
        }, 300);

        jQuery.post(ajaxurl, {
            action: 'umbrella_merge_all_wallets',
            nonce: '<?php echo wp_create_nonce('umbrella_merge_wallets'); ?>'
        }, function(response) {
            clearInterval(fakeProgressInterval);

            if (response.success) {
                const data = response.data;

                // Show 100% completion
                document.getElementById('merge-progress-bar').style.width = '100%';
                document.getElementById('merge-progress-bar').textContent = '100%';
                document.getElementById('merge-progress-percent').textContent = '100%';
                document.getElementById('merge-current-count').textContent = data.total_wallets || eligibleCount;
                document.getElementById('merge-success-count').textContent = data.successful || 0;
                document.getElementById('merge-fail-count').textContent = data.failed || 0;

                // Show success message after brief delay
                setTimeout(() => {
                    let message = '✅ Merge Complete!\n\n' +
                        'Total Processed: ' + (data.total_wallets || 0) + '\n' +
                        'Successful: ' + (data.successful || 0) + '\n';

                    if (data.already_assigned > 0) {
                        message += 'Already Assigned: ' + data.already_assigned + '\n';
                    }

                    if (data.failed > 0) {
                        message += 'Failed: ' + data.failed + '\n';
                    }

                    message += '\nDuration: ' + (data.duration_seconds || 0) + 's';

                    alert(message);
                    location.reload();
                }, 800);

            } else {
                // Reset UI on error
                progressDiv.style.display = 'none';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.innerHTML = originalText;
                alert('❌ Merge failed: ' + response.data);
            }
        }).fail(function() {
            clearInterval(fakeProgressInterval);
            progressDiv.style.display = 'none';
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerHTML = originalText;
            alert('❌ Request failed. Please try again.');
        });
    }

    // Export all merged wallets
    function exportAllMerged() {
        const btn = document.getElementById('export-merged-btn');
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ Exporting...';

        // Create a form and submit it to trigger download
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ajaxurl + '?action=umbrella_export_all_merged';

        const nonceField = document.createElement('input');
        nonceField.type = 'hidden';
        nonceField.name = 'nonce';
        nonceField.value = '<?php echo wp_create_nonce('umbrella_mining'); ?>';
        form.appendChild(nonceField);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        setTimeout(function() {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }, 2000);
    }

    // View merge details modal
    jQuery(document).ready(function($) {
        $('.view-merge').on('click', function(e) {
            e.preventDefault();
            const mergeId = $(this).data('merge-id');

            // Show modal
            $('#merge-modal').fadeIn(200);
            $('#merge-modal-content').html('<div style="padding: 40px; text-align: center; color: #666;"><div style="font-size: 24px; margin-bottom: 16px;">⏳</div><div>Loading merge details...</div></div>');

            // Load merge details
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_merge_details',
                    merge_id: mergeId
                },
                success: function(html) {
                    $('#merge-modal-content').html(html);
                },
                error: function() {
                    $('#merge-modal-content').html('<div style="padding: 40px; text-align: center; color: #dc3232;">Failed to load merge details</div>');
                }
            });
        });

        // Close modal
        $('.merge-modal-close, #merge-modal-overlay').on('click', function() {
            $('#merge-modal').fadeOut(200);
        });

        // Close button hover effect
        $('.merge-modal-close').hover(
            function() { $(this).css('opacity', '1'); },
            function() { $(this).css('opacity', '0.6'); }
        );

        // ESC key to close modal
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#merge-modal').is(':visible')) {
                $('#merge-modal').fadeOut(200);
            }
        });

        // ===== IMPORT SOLUTIONS FUNCTIONALITY =====

        // Toggle import solutions section
        $('#import-solutions-toggle').on('change', function() {
            $('#import-solutions-section').slideToggle(300);
        });

        // Drag and drop handlers
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('import-file-input');

        if (dropZone && fileInput) {
            // Prevent default drag behaviors
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }, false);
            });

            // Highlight drop zone when dragging over
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, function() {
                    dropZone.style.borderColor = '#ff6b6b';
                    dropZone.style.background = 'rgba(255, 107, 107, 0.15)';
                    dropZone.style.transform = 'scale(1.02)';
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, function() {
                    dropZone.style.borderColor = 'rgba(255, 107, 107, 0.3)';
                    dropZone.style.background = 'rgba(255, 107, 107, 0.05)';
                    dropZone.style.transform = 'scale(1)';
                }, false);
            });

            // Handle dropped files
            dropZone.addEventListener('drop', function(e) {
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleImportFile(files[0]);
                }
            }, false);

            // Handle click to browse
            dropZone.addEventListener('click', function() {
                fileInput.click();
            });

            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    handleImportFile(e.target.files[0]);
                }
            });
        }

        // Handle file upload and parsing
        function handleImportFile(file) {
            console.log('File selected:', file.name);

            // Validate file type
            if (!file.name.endsWith('.zip')) {
                alert('❌ Invalid file type. Please upload a ZIP file.');
                return;
            }

            // Validate file size (50MB)
            if (file.size > 50 * 1024 * 1024) {
                alert('❌ File too large. Maximum size is 50MB.');
                return;
            }

            // Show parsing status
            $('#import-parsing-status').html(`
                <div style="background: rgba(255, 170, 0, 0.1); border-left: 3px solid #ffaa00; padding: 16px; border-radius: 6px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="font-size: 24px;">⏳</div>
                        <div>
                            <div style="color: #ffaa00; font-weight: 600; margin-bottom: 4px;">Parsing ZIP file...</div>
                            <div style="color: #999; font-size: 13px;">Extracting wallets from ${file.name}</div>
                        </div>
                    </div>
                </div>
            `).fadeIn();

            // Hide other sections
            $('#import-preview-container').hide();
            $('#import-progress-container').hide();
            $('#import-complete-container').hide();

            // Upload and parse file
            const formData = new FormData();
            formData.append('action', 'umbrella_parse_import_file');
            formData.append('nonce', '<?php echo wp_create_nonce('umbrella_mining'); ?>');
            formData.append('import_file', file);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showImportPreview(response.data);
                    } else {
                        $('#import-parsing-status').html(`
                            <div style="background: rgba(255, 51, 102, 0.1); border-left: 3px solid #ff3366; padding: 16px; border-radius: 6px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="font-size: 24px;">❌</div>
                                    <div>
                                        <div style="color: #ff3366; font-weight: 600; margin-bottom: 4px;">Parse Failed</div>
                                        <div style="color: #999; font-size: 13px;">${response.data}</div>
                                    </div>
                                </div>
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#import-parsing-status').html(`
                        <div style="background: rgba(255, 51, 102, 0.1); border-left: 3px solid #ff3366; padding: 16px; border-radius: 6px;">
                            <div style="color: #ff3366; font-weight: 600;">❌ Network error. Please try again.</div>
                        </div>
                    `);
                }
            });
        }

        // Show preview summary
        function showImportPreview(data) {
            console.log('Import data:', data);

            // Hide parsing status
            $('#import-parsing-status').hide();

            // Show preview
            const html = `
                <div style="margin-top: 20px;">
                    <!-- Summary Stats -->
                    <div class="stats-grid" style="margin-bottom: 20px;">
                        <div class="stat-card">
                            <div class="stat-label">📦 Wallets Found</div>
                            <div class="stat-value">${data.wallet_count}</div>
                            <div class="stat-meta">Unique addresses</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">✅ With Solutions</div>
                            <div class="stat-value" style="color: #00ff41;">${data.wallets_with_solutions}</div>
                            <div class="stat-meta">Have submitted work</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">💎 Total Solutions</div>
                            <div class="stat-value" style="color: #00d4ff;">${data.total_solutions}</div>
                            <div class="stat-meta">To be consolidated</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">🌙 Est. NIGHT Value</div>
                            <div class="stat-value" style="color: #ffaa00;">${typeof data.night_estimate === 'object' ? data.night_estimate.total : data.night_estimate}</div>
                            <div class="stat-meta">Based on actual rates</div>
                        </div>
                    </div>

                    ${data.night_estimate?.breakdown && Object.keys(data.night_estimate.breakdown).length > 0 ? `
                        <div class="info-box" style="margin-bottom: 20px; background: rgba(255, 170, 0, 0.05); border-color: #ffaa00;">
                            <div style="font-weight: 600; margin-bottom: 12px; color: #ffaa00;">📊 NIGHT Breakdown by Mining Day</div>
                            <div style="font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.8;">
                                ${Object.values(data.night_estimate.breakdown).map(day => `
                                    <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid rgba(255, 170, 0, 0.1);">
                                        <span style="color: #aaa;">Day ${day.day}:</span>
                                        <span style="color: #00ff41;">${day.solutions} solutions × ${day.rate} STAR</span>
                                        <span style="color: #ffaa00; font-weight: 600;">${day.night} NIGHT</span>
                                    </div>
                                `).join('')}
                                <div style="display: flex; justify-content: space-between; padding: 8px 0; margin-top: 8px; font-weight: 600; border-top: 2px solid rgba(255, 170, 0, 0.3);">
                                    <span style="color: #fff;">TOTAL:</span>
                                    <span style="color: #ffaa00; font-size: 16px;">${data.night_estimate.total}</span>
                                </div>
                            </div>
                        </div>
                    ` : ''}

                    ${data.invalid_wallets > 0 ? `
                        <div class="warning-box" style="margin-bottom: 20px;">
                            <div class="warning-box-title">⚠️ ${data.invalid_wallets} Invalid Wallets</div>
                            <div style="color: #999; font-size: 13px; margin-top: 8px;">
                                These will be skipped during merge. Check your export file.
                            </div>
                        </div>
                    ` : ''}

                    <!-- Danger Warning -->
                    <div class="danger-box" style="margin-top: 30px;">
                        <h2 style="margin: 0 0 20px 0; font-size: 20px; line-height: 1.4;">⚠️ CONFIRM MERGE OPERATION</h2>
                        <div style="margin: 20px 0; font-size: 16px; line-height: 1.8;">
                            <p style="margin-bottom: 16px;">
                                All <strong style="color: #ff3366;">${data.wallet_count} wallets</strong>
                                will permanently assign their rewards to:
                            </p>
                            <div class="address-value" style="background: rgba(255, 51, 102, 0.1); border-color: #ff3366;">
                                <div class="address" style="color: #ff3366; word-break: break-all;">${data.payout_address}</div>
                            </div>
                            <p style="margin-top: 16px; color: #ffaa00; font-weight: 600;">
                                ⚠️ This action CANNOT be undone
                            </p>
                        </div>

                        <div style="display: flex; gap: 16px; justify-content: center; margin-top: 25px; flex-wrap: wrap;">
                            <button
                                id="confirm-import-merge-btn"
                                class="button button-primary btn-large"
                                style="background: linear-gradient(135deg, #ff3366 0%, #cc0033 100%); padding: 12px 20px; font-size: 13px; white-space: nowrap; min-width: 200px;"
                                onclick="startImportMerge('${data.session_key}')"
                            >
                                🚀 MERGE ALL ${data.wallet_count} WALLETS
                            </button>
                            <button
                                class="button btn-large"
                                style="padding: 12px 24px; font-size: 14px;"
                                onclick="cancelImport()"
                            >
                                ❌ Cancel
                            </button>
                        </div>
                    </div>

                    <!-- Estimated Time -->
                    <div style="text-align: center; margin-top: 20px; color: #666; font-size: 13px;">
                        <span style="opacity: 0.8;">
                            ⏱️ Estimated processing time: ~${Math.ceil(data.wallet_count * 0.5 / 60)} minutes
                            (${data.wallet_count} wallets × 0.5s/wallet, auto-adjusts based on API speed)
                        </span>
                    </div>
                </div>
            `;

            $('#import-preview-container').html(html).fadeIn();

            // Store session key
            window.currentImportSessionKey = data.session_key;
        }

        // Cancel import
        function cancelImport() {
            if (confirm('Cancel import and clear parsed data?')) {
                $('#import-solutions-section').find('input[type="file"]').val('');
                $('#import-parsing-status').hide();
                $('#import-preview-container').hide();
                $('#import-progress-container').hide();
                $('#import-complete-container').hide();
            }
        }

        // Start batch merge
        window.startImportMerge = function(sessionKey) {
            console.log('Starting batch merge for session:', sessionKey);

            // Hide preview, show progress
            $('#import-preview-container').hide();
            $('#import-progress-container').html(`
                <div style="background: rgba(0, 255, 65, 0.05); border: 2px solid #00ff41; border-radius: 8px; padding: 30px;">
                    <h3 style="color: #00ff41; margin: 0 0 20px 0; text-align: center;">
                        🚀 Merging Wallets...
                    </h3>

                    <!-- Progress Bar -->
                    <div style="background: rgba(0, 0, 0, 0.5); border-radius: 8px; height: 30px; margin-bottom: 20px; overflow: hidden; position: relative;">
                        <div id="import-progress-bar" style="background: linear-gradient(90deg, #00ff41 0%, #00d4ff 100%); height: 100%; width: 0%; transition: width 0.5s ease; display: flex; align-items: center; justify-content: center;">
                            <span id="import-progress-percent" style="color: #000; font-weight: 700; font-size: 14px; position: absolute; left: 50%; transform: translateX(-50%);">0%</span>
                        </div>
                    </div>

                    <!-- Status Text -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <div style="font-size: 16px; color: #e0e0e0; margin-bottom: 8px;">
                            Processing wallet <span id="import-current-wallet">0</span> of <span id="import-total-wallets">0</span>
                        </div>
                        <div style="font-size: 13px; color: #999;">
                            ✅ Successful: <span id="import-success-count" style="color: #00ff41;">0</span> |
                            ❌ Failed: <span id="import-fail-count" style="color: #ff3366;">0</span>
                        </div>
                    </div>

                    <div style="text-align: center; font-size: 12px; color: #666;">
                        ⏳ This may take several minutes. Don't close this page.
                    </div>
                </div>
            `).fadeIn();

            // Start the merge process
            $.post(ajaxurl, {
                action: 'umbrella_start_batch_merge',
                session_key: sessionKey,
                nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
            }, function(response) {
                if (!response.success) {
                    // If immediate error
                    alert('❌ Merge failed: ' + (response.data || 'Unknown error'));
                    $('#import-progress-container').hide();
                    $('#import-preview-container').show();
                    return;
                }
            });

            // Poll for progress
            const progressInterval = setInterval(function() {
                $.post(ajaxurl, {
                    action: 'umbrella_get_merge_progress',
                    session_key: sessionKey,
                    nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
                }, function(response) {
                    if (response.success) {
                        updateImportProgress(response.data);

                        if (response.data.complete) {
                            clearInterval(progressInterval);
                            showImportCompletion(response.data, sessionKey);
                        }
                    }
                });
            }, 2000); // Poll every 2 seconds
        };

        // Update progress UI
        function updateImportProgress(data) {
            const percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;

            $('#import-progress-bar').css('width', percent + '%');
            $('#import-progress-percent').text(percent + '%');
            $('#import-current-wallet').text(data.processed);
            $('#import-total-wallets').text(data.total);
            $('#import-success-count').text(data.successful);
            $('#import-fail-count').text(data.failed);
        }

        // Show completion screen
        function showImportCompletion(data, sessionKey) {
            $('#import-progress-container').hide();

            const html = `
                <div style="background: rgba(0, 255, 65, 0.05); border: 2px solid #00ff41; border-radius: 8px; padding: 40px; text-align: center;">
                    <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
                    <h2 style="color: #00ff41; margin: 0 0 30px 0;">Import Complete!</h2>

                    <div class="stats-grid" style="margin-bottom: 30px;">
                        <div class="stat-card">
                            <div class="stat-label">Total Processed</div>
                            <div class="stat-value">${data.total}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Successful</div>
                            <div class="stat-value" style="color: #00ff41;">${data.successful}</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Failed</div>
                            <div class="stat-value" style="color: ${data.failed > 0 ? '#ff3366' : '#666'};">${data.failed}</div>
                        </div>
                    </div>

                    ${data.failed > 0 ? `
                        <div class="warning-box" style="margin-bottom: 20px;">
                            <div class="warning-box-title">⚠️ Some Merges Failed</div>
                            <div style="color: #999; font-size: 13px; margin-top: 8px;">
                                ${data.failed} wallet(s) could not be merged. Check the receipt for details.
                            </div>
                        </div>
                    ` : ''}

                    <div style="display: flex; gap: 12px; justify-content: center; margin-top: 30px;">
                        <button class="button button-primary" onclick="downloadImportReceipt('${sessionKey}')">
                            📄 Download Receipt
                        </button>
                        <button class="button" onclick="location.reload()">
                            🔄 Reload Page
                        </button>
                    </div>
                </div>
            `;

            $('#import-complete-container').html(html).fadeIn();
        }

        // Download receipt
        window.downloadImportReceipt = function(sessionKey) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = ajaxurl;
            form.target = '_blank';

            const fields = {
                action: 'umbrella_download_import_receipt',
                session_key: sessionKey,
                nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
            };

            for (const key in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        };

        // Check for interrupted sessions on page load
        $.post(ajaxurl, {
            action: 'umbrella_check_interrupted_sessions',
            nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
        }, function(response) {
            if (response.success && response.data.interrupted_session) {
                const session = response.data.interrupted_session;
                showResumePrompt(session);
            }
        });

        // Show resume prompt
        function showResumePrompt(session) {
            const remaining = session.total_wallets - session.processed_wallets;

            const html = `
                <div class="notice notice-warning" style="padding: 20px; margin-bottom: 20px; background: rgba(255, 170, 0, 0.1); border-left: 4px solid #ffaa00;">
                    <h3 style="color: #ffaa00; margin: 0 0 12px 0;">⚠️ Interrupted Import Detected</h3>
                    <p style="margin: 0 0 12px 0;">You have an incomplete import from ${session.started_at}:</p>
                    <ul style="margin: 0 0 16px 0; padding-left: 20px;">
                        <li><strong>Total Wallets:</strong> ${session.total_wallets}</li>
                        <li><strong>Processed:</strong> ${session.processed_wallets}</li>
                        <li><strong>Remaining:</strong> ${remaining}</li>
                        <li><strong>Successful:</strong> ${session.successful_count}</li>
                        <li><strong>Failed:</strong> ${session.failed_count}</li>
                    </ul>
                    <div style="display: flex; gap: 12px;">
                        <button onclick="resumeImport('${session.session_key}')" class="button button-primary">
                            🔄 Resume Import
                        </button>
                        <button onclick="cancelImportSession('${session.session_key}')" class="button">
                            ❌ Cancel & Start Fresh
                        </button>
                    </div>
                </div>
            `;

            $('.page-header').after(html);
        }

        // Resume import
        window.resumeImport = function(sessionKey) {
            // Expand the import section
            $('#import-solutions-toggle').prop('checked', true);
            $('#import-solutions-section').slideDown(300);

            // Hide other sections
            $('#import-parsing-status').hide();
            $('#import-preview-container').hide();
            $('#import-complete-container').hide();

            // Start merging directly
            setTimeout(function() {
                startImportMerge(sessionKey);
            }, 500);
        };

        // Cancel session
        window.cancelImportSession = function(sessionKey) {
            if (!confirm('Cancel this import session? This cannot be undone.')) {
                return;
            }

            $.post(ajaxurl, {
                action: 'umbrella_cancel_import_session',
                session_key: sessionKey,
                nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Failed to cancel session');
                }
            });
        };

    });
    </script>

    <!-- Merge Details Modal -->
    <div id="merge-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 999999;">
        <div id="merge-modal-overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(4px);"></div>
        <div style="position: relative; width: 90%; max-width: 1000px; margin: 40px auto; background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%); border-radius: 12px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); max-height: calc(100vh - 80px); overflow-y: auto; border: 1px solid #2a3f5f;">
            <div style="position: sticky; top: 0; background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%); border-bottom: 1px solid #2a3f5f; padding: 20px 25px; z-index: 10; display: flex; justify-content: space-between; align-items: center; border-radius: 12px 12px 0 0;">
                <h2 style="margin: 0; color: #00ff41; font-size: 18px; text-transform: uppercase; letter-spacing: 2px;">🔀 Merge Details</h2>
                <button class="merge-modal-close" style="background: none; border: none; color: #fff; font-size: 28px; cursor: pointer; padding: 0; line-height: 1; opacity: 0.6; transition: opacity 0.3s;">&times;</button>
            </div>
            <div id="merge-modal-content" style="padding: 25px;">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

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
        <!-- Payout Wallet Active -->
        <div class="info-box" style="margin-bottom: 20px; position: relative;">
            <div class="info-box-title">
                <?php if ($is_imported_wallet): ?>
                    🔑 Using Imported Payout Wallet
                <?php else: ?>
                    ℹ️ Payout Wallet Selected
                <?php endif; ?>
            </div>
            <div class="info-box-content">
                <?php if ($is_imported_wallet): ?>
                    <div style="margin-bottom: 16px;">
                        Using your <strong style="color: #00d4ff;">imported wallet</strong> as the payout destination. All mining wallets will merge their rewards to this address.
                    </div>
                    <button type="button" id="clear-imported-wallet-btn" class="button" style="
                        background: linear-gradient(135deg, #ff4444 0%, #cc0000 100%);
                        color: #fff;
                        border: none;
                        padding: 8px 18px;
                        font-weight: 600;
                        border-radius: 6px;
                        box-shadow: 0 2px 10px rgba(255, 68, 68, 0.3);
                        transition: all 0.3s;
                        cursor: pointer;
                        font-size: 13px;
                    ">
                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                            <span>🗑️</span>
                            <span>Remove Imported Wallet</span>
                        </span>
                    </button>
                <?php else: ?>
                    Using your first registered mining wallet as the payout destination. All other mining wallets will merge their rewards to this address. This wallet is already registered with the Scavenger Mine API.
                <?php endif; ?>
            </div>
        </div>

        <!-- Import Custom Payout Wallet Toggle -->
        <div class="card" style="margin-bottom: 20px; border-left: 4px solid #00d4ff; background: rgba(0, 212, 255, 0.03); box-shadow: 0 0 20px rgba(0, 212, 255, 0.1);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div>
                    <h3 style="margin: 0 0 4px 0; color: #00d4ff; font-size: 18px; letter-spacing: 0.5px; text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);">
                        🔑 Import Your Own Payout Wallet
                    </h3>
                    <p style="margin: 0; font-size: 12px; color: #666; font-style: italic;">
                        Use a wallet from Eternl, Nami, or other platforms
                    </p>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="import-wallet-toggle">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div id="import-wallet-section" style="display: none; margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(0, 212, 255, 0.2);">
                <div style="background: rgba(0, 212, 255, 0.05); border: 1px dashed rgba(0, 212, 255, 0.3); padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: start; gap: 12px;">
                        <div style="font-size: 24px; flex-shrink: 0;">💡</div>
                        <div style="color: #aaa; font-size: 13px; line-height: 1.6;">
                            <strong style="color: #00d4ff; display: block; margin-bottom: 4px;">What This Does:</strong>
                            Import an existing wallet from another platform to use as your payout destination. Your mnemonic will be encrypted and stored securely. This wallet will receive all merged rewards.
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #00ff41; font-size: 14px; letter-spacing: 0.5px; text-transform: uppercase;">
                        24-Word Mnemonic Phrase
                    </label>
                    <textarea id="import-mnemonic" rows="4" style="width: 100%; padding: 14px; background: #0a0a0a; border: 2px solid #333; color: #fff; font-family: 'Courier New', monospace; font-size: 13px; border-radius: 6px; transition: all 0.3s; box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.5);" placeholder="word1 word2 word3 word4 word5 word6 word7 word8 ... word24"></textarea>
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #ffaa00; margin-top: 8px; background: rgba(255, 170, 0, 0.1); padding: 8px 12px; border-radius: 4px; border-left: 3px solid #ffaa00;">
                        <span style="font-size: 16px;">🔒</span>
                        <span>Your mnemonic will be encrypted with AES-256 and stored securely in the database</span>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #00d4ff; font-size: 14px; letter-spacing: 0.5px; text-transform: uppercase;">
                        Derivation Path (Optional)
                    </label>
                    <input type="text" id="import-derivation-path" value="0/0/0" style="width: 200px; padding: 10px 14px; background: #0a0a0a; border: 2px solid #333; color: #fff; font-family: 'Courier New', monospace; font-size: 14px; border-radius: 6px; transition: all 0.3s;" placeholder="0/0/0">
                    <div style="font-size: 12px; color: #666; margin-top: 6px;">
                        Format: <code style="color: #00d4ff;">account/chain/address</code> (e.g., 0/0/1 for second address). Leave as 0/0/0 if unsure.
                    </div>
                </div>

                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button type="button" id="import-wallet-btn" class="button button-primary" style="background: linear-gradient(135deg, #00d4ff 0%, #00a8cc 100%); color: #000; border: none; padding: 10px 24px; font-weight: 600; border-radius: 6px; box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3); transition: all 0.3s; cursor: pointer;">
                        <span style="display: inline-flex; align-items: center; gap: 8px;">
                            <span>🔍</span>
                            <span>Import & Verify</span>
                        </span>
                    </button>
                    <span id="import-status" style="font-size: 14px; font-weight: 500;"></span>
                </div>

                <div id="import-result" style="display: none; margin-top: 20px; padding: 16px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);"></div>
            </div>
        </div>

        <!-- Import Solutions from Other Miners -->
        <div class="card" style="margin-bottom: 30px; border-left: 4px solid #ff6b6b; background: rgba(255, 107, 107, 0.03); box-shadow: 0 0 20px rgba(255, 107, 107, 0.1);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div>
                    <h3 style="margin: 0 0 4px 0; color: #ff6b6b; font-size: 18px; letter-spacing: 0.5px; text-shadow: 0 0 10px rgba(255, 107, 107, 0.5);">
                        📦 Import Solutions from Other Miners
                    </h3>
                    <p style="margin: 0; font-size: 12px; color: #666; font-style: italic;">
                        Drag and drop ZIP exports from Night Miner or other platforms
                    </p>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="import-solutions-toggle">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div id="import-solutions-section" style="display: none; margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(255, 107, 107, 0.2);">

                <!-- Info Box -->
                <div style="background: rgba(255, 107, 107, 0.05); border: 1px dashed rgba(255, 107, 107, 0.3); padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: start; gap: 12px;">
                        <div style="font-size: 24px; flex-shrink: 0;">💡</div>
                        <div style="color: #aaa; font-size: 13px; line-height: 1.6;">
                            <strong style="color: #ff6b6b; display: block; margin-bottom: 4px;">What This Does:</strong>
                            Import wallet exports from Night Miner and other mining platforms. All wallets in the file will be merged to your payout address: <code style="color: #00ff41; word-break: break-all; display: inline-block; max-width: 100%;"><?php echo esc_html($payout_wallet->address); ?></code>
                        </div>
                    </div>
                </div>

                <!-- Drag and Drop Zone -->
                <div id="drop-zone" style="
                    border: 3px dashed rgba(255, 107, 107, 0.3);
                    background: rgba(255, 107, 107, 0.05);
                    border-radius: 12px;
                    padding: 60px 40px;
                    text-align: center;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    margin-bottom: 20px;
                " onmouseover="this.style.borderColor='#ff6b6b'; this.style.background='rgba(255, 107, 107, 0.1)'" onmouseout="this.style.borderColor='rgba(255, 107, 107, 0.3)'; this.style.background='rgba(255, 107, 107, 0.05)'">
                    <div style="font-size: 64px; margin-bottom: 28px; opacity: 0.6; line-height: 1;">📦</div>
                    <div style="font-size: 16px; font-weight: 600; color: #ff6b6b; margin-bottom: 8px;">
                        Drag ZIP file here or click to browse
                    </div>
                    <div style="font-size: 13px; color: #999;">
                        Supported: Night Miner exports (.zip) • Max size: 50MB
                    </div>
                    <input type="file" id="import-file-input" accept=".zip" style="display: none;">
                </div>

                <!-- Parsing Status -->
                <div id="import-parsing-status" style="display: none; margin-bottom: 20px;"></div>

                <!-- Preview Summary -->
                <div id="import-preview-container" style="display: none;"></div>

                <!-- Progress Tracking -->
                <div id="import-progress-container" style="display: none;"></div>

                <!-- Completion Screen -->
                <div id="import-complete-container" style="display: none;"></div>
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
            <?php if ($stats['merged_wallets'] > 0): ?>
                <button type="button" id="export-merged-btn" class="button" onclick="exportAllMerged()" style="background: #00d4ff; color: #000; border: none;">
                    <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export All Merged (<?php echo $stats['merged_wallets']; ?>)
                </button>
            <?php endif; ?>
        </div>

        <!-- Anvil API Promo Banner -->
        <div id="anvil-promo-banner" class="anvil-promo-collapsed" style="margin-bottom: 30px; padding: 12px 20px; background: rgba(0, 212, 255, 0.05); border-radius: 6px; border: 1px solid rgba(0, 212, 255, 0.2); cursor: pointer; transition: all 0.3s ease; overflow: hidden;" onclick="toggleAnvilPromo()">
            <!-- Collapsed State -->
            <div id="anvil-collapsed" style="display: flex; align-items: center; gap: 15px;">
                <div class="anvil-icon" style="font-size: 28px; line-height: 1; animation: anvil-pulse 2s ease-in-out infinite;">🔨</div>
                <div style="flex: 1;">
                    <div style="font-size: 14px; font-weight: 600; color: #00d4ff;">Ready to build on Cardano?</div>
                </div>
                <div style="color: #00d4ff; font-size: 20px; transition: transform 0.3s ease;" id="anvil-arrow">›</div>
            </div>

            <!-- Expanded State -->
            <div id="anvil-expanded" style="display: none; padding-top: 15px; border-top: 1px solid rgba(0, 212, 255, 0.2); margin-top: 15px;">
                <div style="margin-bottom: 15px; color: #b8b8b8; font-size: 14px; line-height: 1.6;">
                    Check out the <strong style="color: #00d4ff;">Anvil API</strong> — Get a free API key and unlock your inner blockchain builder.
                </div>
                <a href="https://ada-anvil.io/services/api" target="_blank" style="display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #00ff41 0%, #00d4ff 100%); color: #000; font-weight: 700; font-size: 13px; border-radius: 6px; text-decoration: none; box-shadow: 0 4px 10px rgba(0, 255, 65, 0.2); transition: all 0.2s ease;" onclick="event.stopPropagation();" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 15px rgba(0, 255, 65, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 10px rgba(0, 255, 65, 0.2)';">
                    Get Started →
                </a>
                <div style="margin-top: 12px; font-size: 11px; color: #666; font-style: italic;">
                    Umbrella Mines is a Pb Project, not an official Anvil product.
                </div>
            </div>
        </div>

        <script>
        function toggleAnvilPromo() {
            const banner = document.getElementById('anvil-promo-banner');
            const collapsed = document.getElementById('anvil-collapsed');
            const expanded = document.getElementById('anvil-expanded');
            const arrow = document.getElementById('anvil-arrow');

            if (banner.classList.contains('anvil-promo-collapsed')) {
                // Expand
                banner.classList.remove('anvil-promo-collapsed');
                banner.classList.add('anvil-promo-expanded');
                banner.style.background = 'linear-gradient(135deg, #1a1a2e 0%, #16213e 100%)';
                banner.style.borderColor = 'rgba(0, 255, 65, 0.3)';
                banner.style.padding = '20px 25px';
                banner.style.boxShadow = '0 4px 15px rgba(0, 255, 65, 0.1)';
                expanded.style.display = 'block';
                arrow.style.transform = 'rotate(90deg)';
            } else {
                // Collapse
                banner.classList.remove('anvil-promo-expanded');
                banner.classList.add('anvil-promo-collapsed');
                banner.style.background = 'rgba(0, 212, 255, 0.05)';
                banner.style.borderColor = 'rgba(0, 212, 255, 0.2)';
                banner.style.padding = '12px 20px';
                banner.style.boxShadow = 'none';
                expanded.style.display = 'none';
                arrow.style.transform = 'rotate(0deg)';
            }
        }

        // Merge pagination
        let currentMergePage = 1;
        const totalMergePages = <?php echo $stats['merge_total_pages']; ?>;
        const totalMerges = <?php echo $stats['total_merges']; ?>;

        function loadMergePage(page) {
            if (page < 1 || page > totalMergePages) return;

            currentMergePage = page;

            // Update UI immediately
            updateMergePaginationUI();

            // Fetch new data
            jQuery.post(ajaxurl, {
                action: 'umbrella_load_merge_page',
                page: page,
                nonce: '<?php echo wp_create_nonce('umbrella_merge_pagination'); ?>'
            }, function(response) {
                if (response.success) {
                    // Update table content
                    updateMergeTable(response.data.merges);
                }
            });
        }

        function updateMergePaginationUI() {
            const perPage = 10;
            const start = ((currentMergePage - 1) * perPage) + 1;
            const end = Math.min(currentMergePage * perPage, totalMerges);

            // Update info text
            jQuery('#merge-page-info').text(`Showing ${start}-${end} of ${totalMerges} merges`);

            // Rebuild page buttons with sliding window (shows 5 pages max, centered)
            const buttonContainer = jQuery('#merge-page-buttons');
            buttonContainer.empty();

            // Previous button
            const prevBtn = jQuery('<button>')
                .addClass('button button-small merge-prev-btn')
                .text('← Previous')
                .prop('disabled', currentMergePage <= 1)
                .css({
                    'background': 'rgba(0, 212, 255, 0.1)',
                    'color': currentMergePage <= 1 ? '#666' : '#00d4ff',
                    'border-color': 'rgba(0, 212, 255, 0.3)',
                    'opacity': currentMergePage <= 1 ? '0.5' : '1'
                })
                .on('click', function() { if (currentMergePage > 1) loadMergePage(currentMergePage - 1); });
            buttonContainer.append(prevBtn);

            // Page number buttons (sliding window)
            const maxButtons = 5;
            let startPage = Math.max(1, currentMergePage - Math.floor(maxButtons / 2));
            let endPage = Math.min(totalMergePages, startPage + maxButtons - 1);

            // Adjust if we're near the end
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }

            for (let i = startPage; i <= endPage; i++) {
                const isActive = i === currentMergePage;
                const pageBtn = jQuery('<button>')
                    .addClass('button button-small merge-page-btn')
                    .text(i)
                    .attr('data-page', i)
                    .css({
                        'background': isActive ? 'linear-gradient(135deg, #00ff41 0%, #00d4ff 100%)' : 'rgba(0, 212, 255, 0.05)',
                        'color': isActive ? '#000' : '#00d4ff',
                        'border': isActive ? 'none' : '1px solid rgba(0, 212, 255, 0.2)',
                        'font-weight': isActive ? '700' : 'normal'
                    })
                    .on('click', function() { loadMergePage(i); });
                buttonContainer.append(pageBtn);
            }

            // Next button
            const nextBtn = jQuery('<button>')
                .addClass('button button-small merge-next-btn')
                .text('Next →')
                .prop('disabled', currentMergePage >= totalMergePages)
                .css({
                    'background': 'rgba(0, 212, 255, 0.1)',
                    'color': currentMergePage >= totalMergePages ? '#666' : '#00d4ff',
                    'border-color': 'rgba(0, 212, 255, 0.3)',
                    'opacity': currentMergePage >= totalMergePages ? '0.5' : '1'
                })
                .on('click', function() { if (currentMergePage < totalMergePages) loadMergePage(currentMergePage + 1); });
            buttonContainer.append(nextBtn);
        }

        function updateMergeTable(merges) {
            const tbody = jQuery('.merge-history-table tbody');
            tbody.empty();

            merges.forEach(function(merge) {
                const row = `
                    <tr>
                        <td><code>${merge.original_address.substring(0, 20)}...</code></td>
                        <td>${merge.solutions_consolidated} solutions</td>
                        <td>
                            <span class="status-badge status-${merge.status}">
                                ${merge.status}
                            </span>
                        </td>
                        <td>${merge.time_ago} ago</td>
                        <td>
                            <a href="#" class="button button-small view-merge" data-merge-id="${merge.id}">View</a>
                        </td>
                    </tr>
                `;
                tbody.append(row);
            });
        }
        </script>

        <!-- Merge Progress Container -->
        <div id="merge-all-progress" style="display: none; margin-bottom: 30px; padding: 24px; background: rgba(0, 255, 65, 0.05); border: 2px solid rgba(0, 255, 65, 0.2); border-radius: 8px;">
            <div style="font-size: 16px; color: #00ff41; font-weight: 600; margin-bottom: 16px; letter-spacing: 1px;">
                ⚡ MERGING WALLETS...
            </div>

            <div style="margin-bottom: 12px;">
                <div style="background: rgba(0, 0, 0, 0.3); height: 24px; border-radius: 12px; overflow: hidden; position: relative;">
                    <div id="merge-progress-bar" style="background: linear-gradient(90deg, #00ff41, #00d4ff); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: #000; font-weight: 700; font-size: 12px;"></div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; color: #aaa; font-size: 13px; margin-bottom: 8px;">
                <span>Processing: <strong id="merge-current-count" style="color: #00ff41;">0</strong> / <strong id="merge-total-count" style="color: #fff;">0</strong></span>
                <span id="merge-progress-percent" style="color: #00d4ff; font-weight: 600;">0%</span>
            </div>

            <div style="display: flex; gap: 20px; font-size: 12px; color: #999; margin-top: 12px; padding-top: 12px; border-top: 1px dashed rgba(255, 255, 255, 0.1);">
                <span>✅ Successful: <strong id="merge-success-count" style="color: #00ff41;">0</strong></span>
                <span>❌ Failed: <strong id="merge-fail-count" style="color: #ff6b6b;">0</strong></span>
            </div>
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['merge_history'] as $merge): ?>
                        <tr>
                            <td><code><?php echo esc_html(substr($merge->original_address, 0, 20)); ?>...</code></td>
                            <td><?php echo (int) $merge->solutions_consolidated; ?> solutions</td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($merge->status); ?>">
                                    <?php echo esc_html($merge->status); ?>
                                </span>
                            </td>
                            <td><?php echo human_time_diff(strtotime($merge->merged_at), current_time('timestamp')); ?> ago</td>
                            <td>
                                <a href="#" class="button button-small view-merge" data-merge-id="<?php echo $merge->id; ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination Controls -->
            <?php if ($stats['merge_total_pages'] > 1): ?>
                <div class="merge-pagination" style="margin-top: 25px; display: flex; align-items: center; justify-content: space-between; padding: 20px; background: rgba(0, 212, 255, 0.03); border-radius: 6px; border: 1px solid rgba(0, 212, 255, 0.1);">
                    <div id="merge-page-info" style="color: #999; font-size: 13px;">
                        Showing 1-<?php echo min(10, $stats['total_merges']); ?> of <?php echo $stats['total_merges']; ?> merges
                    </div>
                    <div id="merge-page-buttons" style="display: flex; gap: 8px; align-items: center;">
                        <!-- Buttons will be populated by JavaScript -->
                    </div>
                </div>

                <script>
                // Initialize pagination on page load
                jQuery(document).ready(function() {
                    updateMergePaginationUI();
                });
                </script>
            <?php endif; ?>
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

                    <details style="margin-top: 20px; padding: 15px; background: rgba(255, 193, 7, 0.1); border-left: 3px solid #ffc107; border-radius: 4px;">
                        <summary style="cursor: pointer; font-weight: bold; color: #ffc107; margin-bottom: 10px;">⚠️ Still not seeing the merge interface? Troubleshoot here</summary>
                        <div style="color: #ccc; line-height: 1.8; margin-top: 10px;">
                            <p style="margin: 0 0 10px 0; font-weight: bold;">The merge page requires ALL of these:</p>
                            <ol style="margin: 0 0 15px 0; padding-left: 20px;">
                                <li>Wallet created with v0.4.20+ (has mnemonic stored)</li>
                                <li>Wallet registered with Scavenger Mine API</li>
                                <li>At least one submitted solution with crypto receipt</li>
                                <li>BCMath PHP extension installed on your server</li>
                            </ol>

                            <p style="margin: 15px 0 10px 0; font-weight: bold;">Quick Checks:</p>
                            <ol style="margin: 0; padding-left: 20px; color: #999;">
                                <li><strong style="color: #e0e0e0;">Check Database:</strong> Go to <a href="<?php echo admin_url('admin.php?page=umbrella-mines-create-table'); ?>" style="color: #00ff41;">Create Table</a> page - ensure all 11 tables exist and critical columns show green checkmarks</li>
                                <li><strong style="color: #e0e0e0;">Check Wallet Mnemonic:</strong> Export a wallet and verify <code style="background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 3px;">mnemonic</code> field is not empty</li>
                                <li><strong style="color: #e0e0e0;">Check BCMath:</strong> SSH into server and run: <code style="background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 3px;">sudo apt-get install php-bcmath && sudo systemctl restart apache2</code></li>
                                <li><strong style="color: #e0e0e0;">Clean Reinstall:</strong> If tables/columns are missing, deactivate plugin, click "Force Update Schema" on Create Table page, then reactivate plugin</li>
                            </ol>

                            <p style="margin: 15px 0 5px 0; padding: 10px; background: rgba(0, 255, 65, 0.1); border-left: 2px solid #00ff41; border-radius: 3px;">
                                <strong style="color: #00ff41;">💡 Quick Fix:</strong> If you have OLD wallets (created before v0.4.20), create a NEW wallet via CLI - it will have mnemonic stored: <code style="background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 3px;">wp umbrella-mines generate-wallet</code>
                            </p>
                        </div>
                    </details>
                </div>
            </div>

            <div style="text-align: center; padding: 40px 20px 20px 20px; color: #666;">
                <div style="font-size: 64px; line-height: 1; margin-bottom: 25px; opacity: 0.3;">🔀</div>
                <div style="font-size: 18px; margin-bottom: 12px; color: #e0e0e0;">Start Mining First</div>
                <div style="font-size: 14px; margin-bottom: 25px;">Create wallets and submit solutions to enable merging</div>
                <a href="<?php echo admin_url('admin.php?page=umbrella-mines'); ?>" class="button button-primary btn-large">
                    ⛏️ Go to Dashboard
                </a>
            </div>

            <!-- Import Custom Wallet Option (Always Available) -->
            <div style="margin-top: 30px; padding: 25px; background: rgba(0, 255, 65, 0.05); border: 1px solid rgba(0, 255, 65, 0.2); border-radius: 8px;">
                <h3 style="margin: 0 0 15px 0; color: #00ff41; font-size: 16px;">💡 Already have a registered wallet?</h3>
                <p style="margin: 0 0 15px 0; color: #999;">If you have an existing wallet from Eternl, Nami, or another platform that's already registered and has submitted solutions, you can import it directly:</p>
                <button type="button" class="button button-secondary" onclick="document.getElementById('import-wallet-section-fallback').style.display='block'; this.style.display='none';">
                    Import Existing Wallet
                </button>

                <div id="import-wallet-section-fallback" style="display: none; margin-top: 20px; padding: 20px; background: rgba(0,0,0,0.3); border-radius: 4px;">
                    <form id="import-wallet-form-fallback" style="max-width: 600px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; color: #e0e0e0; font-weight: bold;">24-Word Mnemonic Phrase</label>
                            <textarea id="fallback-mnemonic" rows="3" style="width: 100%; padding: 10px; background: #1a1a1a; border: 1px solid #333; color: #e0e0e0; border-radius: 4px; font-family: monospace;" placeholder="word1 word2 word3 ... word24" required></textarea>
                            <small style="color: #666;">Enter your 24-word recovery phrase (space-separated)</small>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; color: #e0e0e0; font-weight: bold;">Derivation Path (Optional)</label>
                            <input type="text" id="fallback-derivation" value="0/0/0" style="width: 200px; padding: 8px; background: #1a1a1a; border: 1px solid #333; color: #e0e0e0; border-radius: 4px; font-family: monospace;" />
                            <small style="color: #666; display: block; margin-top: 5px;">Default: 0/0/0. Change only if you know your wallet's derivation path.</small>
                        </div>

                        <button type="submit" id="fallback-import-btn" class="button button-primary">
                            Import & Verify Wallet
                        </button>
                        <div id="fallback-status" style="margin-top: 15px;"></div>
                        <p style="margin: 15px 0 0 0; padding: 10px; background: rgba(255, 193, 7, 0.1); border-left: 2px solid #ffc107; border-radius: 3px; color: #ccc; font-size: 13px;">
                            ⚠️ <strong>Security:</strong> Your mnemonic is encrypted and stored locally. It never leaves your server.
                        </p>
                    </form>

                    <script>
                    jQuery(document).ready(function($) {
                        $('#import-wallet-form-fallback').on('submit', function(e) {
                            e.preventDefault();

                            var mnemonic = $('#fallback-mnemonic').val().trim();
                            var derivationPath = $('#fallback-derivation').val().trim();

                            if (!mnemonic) {
                                $('#fallback-status').html('<span style="color: #dc3232;">Please enter a mnemonic phrase</span>');
                                return;
                            }

                            $('#fallback-import-btn').prop('disabled', true);
                            $('#fallback-status').html('<span style="color: #00ff41;">Importing wallet...</span>');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'umbrella_import_payout_wallet',
                                    nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>',
                                    mnemonic: mnemonic,
                                    derivation_path: derivationPath
                                },
                                success: function(response) {
                                    $('#fallback-import-btn').prop('disabled', false);

                                    if (response.success) {
                                        $('#fallback-status').html('<span style="color: #00ff41;">✅ Success! Reloading page...</span>');
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 1500);
                                    } else {
                                        $('#fallback-status').html('<span style="color: #dc3232;">❌ ' + response.data + '</span>');
                                    }
                                },
                                error: function() {
                                    $('#fallback-import-btn').prop('disabled', false);
                                    $('#fallback-status').html('<span style="color: #dc3232;">❌ Network error. Please try again.</span>');
                                }
                            });
                        });
                    });
                    </script>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
