<?php
/**
 * Umbrella Mines - Simple CLI Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Get statistics
$stats = array(
    'total_wallets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets"),
    'total_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions"),
    'submitted_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE submission_status = 'submitted'"),
);

// Get site path for commands
$site_path = ABSPATH;
?>

<div class="wrap">
    <h1>⛏️ Umbrella Mines - Mining Dashboard</h1>

    <div class="notice notice-info" style="padding: 20px; margin-top: 20px;">
        <h2>📋 Quick Start - Copy & Paste Commands</h2>
        <p><strong>Open your Site Shell in Local by Flywheel</strong>, then run these commands:</p>

        <h3>🚀 Start Mining (Recommended)</h3>
        <div style="background: #1e1e1e; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <code style="color: #00ff00; font-size: 14px;">wp umbrella-mines start --max-attempts=500000 --derive=0/0/0</code>
        </div>
        <p style="color: #666; font-size: 13px;">
            → Mines continuously with 500,000 attempts per wallet<br>
            → Uses derivation path 0/0/0 (you can change this)<br>
            → Press Ctrl+C to stop mining
        </p>

        <h3>📊 Other Useful Commands</h3>

        <p><strong>Test wallet generation:</strong></p>
        <div style="background: #1e1e1e; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <code style="color: #00ff00; font-size: 14px;">wp umbrella-mines test-wallet</code>
        </div>

        <p><strong>Fetch current challenge:</strong></p>
        <div style="background: #1e1e1e; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <code style="color: #00ff00; font-size: 14px;">wp umbrella-mines test-challenge</code>
        </div>

        <p><strong>View mining statistics:</strong></p>
        <div style="background: #1e1e1e; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <code style="color: #00ff00; font-size: 14px;">wp umbrella-mines stats</code>
        </div>

        <h3>⚙️ Advanced Options</h3>
        <p><strong>Custom attempts per wallet:</strong></p>
        <div style="background: #1e1e1e; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <code style="color: #00ff00; font-size: 14px;">wp umbrella-mines start --max-attempts=1000000</code>
        </div>

        <p><strong>Custom derivation path:</strong></p>
        <div style="background: #1e1e1e; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <code style="color: #00ff00; font-size: 14px;">wp umbrella-mines start --max-attempts=500000 --derive=5/1/100</code>
        </div>
        <p style="color: #666; font-size: 13px;">Format: account/chain/address (e.g., 0/0/0, 5/1/100, etc.)</p>
    </div>

    <div class="card" style="margin-top: 30px; padding: 20px;">
        <h2>📈 Current Stats</h2>
        <table class="widefat" style="margin-top: 15px;">
            <tr>
                <th style="width: 40%;">Total Wallets Generated</th>
                <td><strong><?php echo number_format($stats['total_wallets']); ?></strong></td>
            </tr>
            <tr>
                <th>Solutions Found</th>
                <td><strong><?php echo number_format($stats['total_solutions']); ?></strong></td>
            </tr>
            <tr>
                <th>Solutions Submitted</th>
                <td><strong><?php echo number_format($stats['submitted_solutions']); ?></strong></td>
            </tr>
        </table>

        <p style="margin-top: 20px;">
            <a href="<?php echo admin_url('admin.php?page=umbrella-mines-solutions'); ?>" class="button button-primary">
                View Solutions →
            </a>
            <a href="<?php echo admin_url('admin.php?page=umbrella-mines-wallets'); ?>" class="button button-secondary">
                View Wallets →
            </a>
        </p>
    </div>

    <div class="notice notice-warning" style="margin-top: 30px; padding: 15px;">
        <h3>💡 Pro Tips</h3>
        <ul>
            <li><strong>Run in background:</strong> Open multiple Site Shell windows to mine with multiple derivation paths simultaneously</li>
            <li><strong>Monitor progress:</strong> The CLI shows real-time hashrate, attempts, and progress</li>
            <li><strong>Auto-submit:</strong> Solutions are automatically queued for submission (check Manual Submit page)</li>
            <li><strong>Backup:</strong> All wallets and solutions are saved to your database and backed up to files</li>
        </ul>
    </div>
</div>

<style>
    code {
        font-family: 'Courier New', monospace;
        user-select: all;
    }
    .card {
        background: white;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }
</style>
