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
                let message = '✅ Merge Complete!\n\n' +
                    'Total Processed: ' + data.total_wallets + '\n' +
                    'Successful: ' + data.successful + '\n';

                if (data.already_assigned > 0) {
                    message += 'Already Assigned: ' + data.already_assigned + '\n';
                }

                if (data.failed > 0) {
                    message += 'Failed: ' + data.failed + '\n';
                }

                message += '\nDuration: ' + data.duration_seconds + 's';

                alert(message);
                location.reload();
            } else {
                alert('❌ Merge failed: ' + response.data);
            }
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
                            <td>
                                <a href="#" class="button button-small view-merge" data-merge-id="<?php echo $merge->id; ?>">View</a>
                            </td>
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
</div>
