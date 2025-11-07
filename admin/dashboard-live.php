<?php
/**
 * Umbrella Mines - Live Mining Dashboard
 *
 * Runs WP-CLI mining commands directly and displays live output
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Load system requirements checker - PASSIVE CHECKS ONLY (no shell exec)
$system_checks = array();
$all_requirements_met = true;
try {
    require_once(UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-system-requirements.php');
    $system_checks = Umbrella_Mines_System_Requirements::check_all();
    $all_requirements_met = Umbrella_Mines_System_Requirements::are_critical_requirements_met();
} catch (Exception $e) {
    // If system check fails, default to showing no errors
    error_log('Umbrella Mines: System requirements check failed: ' . $e->getMessage());
}

// Handle Start/Stop actions
if (isset($_POST['action']) && isset($_POST['umbrella_mining_nonce']) && wp_verify_nonce($_POST['umbrella_mining_nonce'], 'umbrella_mining')) {
    $action = sanitize_text_field($_POST['action']);

    // Start the action in background
    require_once(dirname(__FILE__) . '/../includes/start-stop-handler.php');
    $log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';
    umbrella_mines_handle_action($action, $log_file);

    // This line should never be reached because handle_action exits
    die('Handler failed to exit');
}

global $wpdb;

// Get configuration
$config_table = $wpdb->prefix . 'umbrella_mining_config';
$config = array();
$rows = $wpdb->get_results("SELECT config_key, config_value FROM {$config_table}");
foreach ($rows as $row) {
    $config[$row->config_key] = $row->config_value;
}

// Get statistics
$stats = array(
    'total_wallets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets"),
    'registered_wallets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets WHERE registered_at IS NOT NULL"),
    'total_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions"),
    'pending_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE submission_status IN ('pending', 'queued')"),
    'submitted_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE submission_status = 'submitted'"),
    'total_receipts' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_receipts"),
);

// Get current challenge
$current_challenge = $wpdb->get_row("
    SELECT * FROM {$wpdb->prefix}umbrella_mining_challenges
    ORDER BY fetched_at DESC
    LIMIT 1
");

// Fetch NIGHT rates from API (cached for 1 hour)
$night_rates_cache = get_transient('umbrella_night_rates');
if ($night_rates_cache === false) {
    $response = wp_remote_get('https://scavenger.prod.gd.midnighttge.io/work_to_star_rate', array('timeout' => 10));
    if (!is_wp_error($response)) {
        $night_rates_cache = json_decode(wp_remote_retrieve_body($response), true);
        set_transient('umbrella_night_rates', $night_rates_cache, 3600);
    } else {
        $night_rates_cache = array();
    }
}

// Calculate total NIGHT earned
$total_star = 0;
if ($night_rates_cache && $current_challenge) {
    $current_day = (int)$current_challenge->day;
    if ($current_day > 0 && isset($night_rates_cache[$current_day - 1])) {
        $star_per_receipt = $night_rates_cache[$current_day - 1];
        $total_star = $stats['total_receipts'] * $star_per_receipt;
    }
}
$total_night = $total_star / 1000000;

// Check if mining is currently running
$log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';
$is_mining = false;
$mining_output = '';

// Check if mining is active by checking if log file was recently modified
$is_mining = false;
if (file_exists($log_file) && filesize($log_file) > 0) {
    $last_modified = filemtime($log_file);
    $is_mining = (time() - $last_modified) < 60;
}

if (file_exists($log_file)) {
    // Only show last 100 lines to prevent page freeze
    $lines = file($log_file);
    if ($lines !== false) {
        $total_lines = count($lines);
        if ($total_lines > 100) {
            $mining_output = "... [showing last 100 lines of {$total_lines} total]\n\n";
            $mining_output .= implode('', array_slice($lines, -100));
        } else {
            $mining_output = implode('', $lines);
        }
    }
}
?>

<div class="wrap umbrella-mines-dashboard">
    <div class="dashboard-header">
        <h1><span class="umbrella-icon">☂</span> UMBRELLA MINES <span class="night-token">$NIGHT</span> MINER</h1>
        <div class="status-indicator <?php echo $is_mining ? 'mining' : 'idle'; ?>">
            <span class="pulse"></span>
            <?php echo $is_mining ? 'MINING ACTIVE' : 'IDLE'; ?>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">WALLETS</div>
            <div class="stat-value"><?php echo number_format($stats['total_wallets']); ?></div>
            <div class="stat-meta"><?php echo number_format($stats['registered_wallets']); ?> registered</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">SOLUTIONS</div>
            <div class="stat-value"><?php echo number_format($stats['total_solutions']); ?></div>
            <div class="stat-meta"><?php echo number_format($stats['submitted_solutions']); ?> submitted</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">PENDING</div>
            <div class="stat-value"><?php echo number_format($stats['pending_solutions']); ?></div>
            <div class="stat-meta">awaiting submission</div>
        </div>

        <div class="stat-card night">
            <div class="stat-label">NIGHT EARNED</div>
            <div class="stat-value"><?php echo number_format($total_night, 6); ?></div>
            <div class="stat-meta"><?php echo number_format($stats['total_receipts']); ?> receipts</div>
        </div>
    </div>

    <!-- System Requirements Check -->
    <?php if (!empty($system_checks) && !$all_requirements_met): ?>
    <div class="system-requirements-alert">
        <div class="alert-header">
            <span class="alert-icon">⚠️</span>
            <span class="alert-title">SYSTEM REQUIREMENTS CHECK FAILED</span>
        </div>
        <div class="alert-body">
            <p>Some critical requirements are missing. Mining will not work until these are resolved:</p>
            <ul class="requirements-list">
                <?php foreach ($system_checks as $key => $check): ?>
                    <?php if ($check['critical'] && !$check['passed']): ?>
                        <li class="requirement-failed">
                            <strong><?php echo esc_html($check['name']); ?>:</strong> <?php echo esc_html($check['message']); ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- System Requirements Details -->
    <?php if (!empty($system_checks)): ?>
    <div class="system-requirements">
        <div class="requirements-header" onclick="jQuery('#requirements-details').slideToggle(200); jQuery('.requirements-toggle').toggleClass('open');" style="cursor: pointer;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span class="requirements-toggle">▶</span>
                <span class="requirements-title">SYSTEM REQUIREMENTS</span>
            </div>
            <span class="requirements-status <?php echo $all_requirements_met ? 'passed' : 'failed'; ?>">
                <?php echo $all_requirements_met ? '✓ ALL PASSED' : '✗ SOME FAILED'; ?>
            </span>
        </div>
        <div id="requirements-details" class="requirements-grid" style="display: none;">
            <?php foreach ($system_checks as $key => $check): ?>
                <div class="requirement-item <?php echo $check['passed'] ? 'passed' : 'failed'; ?>">
                    <div class="requirement-icon"><?php echo $check['passed'] ? '✓' : '✗'; ?></div>
                    <div class="requirement-content">
                        <div class="requirement-name"><?php echo esc_html($check['name']); ?></div>
                        <div class="requirement-status">
                            <span class="requirement-label">Required:</span>
                            <span class="requirement-value"><?php echo esc_html($check['required']); ?></span>
                            <span class="requirement-separator">|</span>
                            <span class="requirement-label">Current:</span>
                            <span class="requirement-value"><?php echo esc_html($check['current']); ?></span>
                        </div>
                        <?php if (!$check['passed']): ?>
                            <div class="requirement-help"><?php echo esc_html($check['message']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Challenge Info -->
    <?php if ($current_challenge && $night_rates_cache): ?>
    <div class="challenge-info">
        <div class="challenge-header">
            <span class="challenge-title">CURRENT CHALLENGE</span>
            <span class="challenge-day">DAY <?php echo (int)$current_challenge->day; ?> / 21</span>
        </div>
        <div class="challenge-details">
            <div class="detail-item">
                <span class="detail-label">Difficulty</span>
                <span class="detail-value"><code><?php echo esc_html($current_challenge->difficulty); ?></code></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Reward</span>
                <span class="detail-value">
                <?php
                $current_day = (int)$current_challenge->day;
                if ($current_day > 0 && isset($night_rates_cache[$current_day - 1])) {
                    $star_rate = number_format($night_rates_cache[$current_day - 1]);
                    $night_rate = number_format($night_rates_cache[$current_day - 1] / 1000000, 6);
                    echo "{$star_rate} STAR ({$night_rate} NIGHT)";
                } else {
                    echo "N/A";
                }
                ?>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Mining Ends</span>
                <span class="detail-value"><?php echo esc_html($current_challenge->mining_period_ends ?? 'N/A'); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Control Panel -->
    <div class="control-panel">
        <div class="panel-header">MINING CONTROL</div>
        <form method="post" action="">
            <?php wp_nonce_field('umbrella_mining', 'umbrella_mining_nonce'); ?>

            <div class="control-grid">
                <div class="control-group">
                    <label for="max_attempts">Max Attempts per Wallet</label>
                    <input type="number" name="max_attempts" id="max_attempts" value="500000">
                    <span class="control-hint">Nonce attempts before generating new wallet</span>
                </div>

                <div class="control-group">
                    <label for="derivation_path">Derivation Path</label>
                    <input type="text" name="derivation_path" id="derivation_path" value="0/0/0" pattern="[0-9]+/[0-9]+/[0-9]+">
                    <span class="control-hint">Format: account/chain/address (e.g., 0/0/0, 5/1/100)</span>
                </div>
            </div>

            <div class="control-actions">
                <div class="button-group">
                    <button type="button" id="start-btn" class="btn btn-start">START MINING</button>
                    <button type="button" id="stop-btn" class="btn btn-stop">STOP MINING</button>
                </div>
                <div class="auto-submit-toggle">
                    <label>
                        <input type="checkbox" id="auto-submit-toggle" <?php echo (!empty($config['submission_enabled']) && $config['submission_enabled'] === '1') ? 'checked' : ''; ?>>
                        <span>Auto-submit solutions</span>
                    </label>
                </div>
            </div>
        </form>
    </div>

    <!-- Terminal Output -->
    <div class="terminal-container">
        <div class="terminal-header">
            <span class="terminal-title">LIVE MINING OUTPUT</span>
            <div class="terminal-meta">
                <span id="terminal-pid" class="terminal-pid"></span>
                <span id="terminal-status" class="terminal-status"><?php echo $is_mining ? 'ACTIVE' : 'IDLE'; ?></span>
                <span id="terminal-countdown" class="terminal-countdown"></span>
            </div>
        </div>
        <div id="mining-terminal" class="terminal-output">
<?php if (!empty($mining_output)): ?>
<?php echo esc_html($mining_output); ?>
<?php else: ?>
No mining activity. Start mining to see live output.
<?php endif; ?>
        </div>
        <div class="terminal-footer">
            Auto-refresh: <?php echo $is_mining ? 'ON (5s)' : 'OFF'; ?> | Showing last 100 lines
        </div>
    </div>

</div>

<script>
jQuery(document).ready(function($) {
    // Start button
    $('#start-btn').on('click', function() {
        $('#start-btn, #stop-btn').prop('disabled', true);
        $('#start-btn').text('STARTING...');

        var $form = $(this).closest('form');
        $('<input>').attr({
            type: 'hidden',
            name: 'action',
            value: 'start'
        }).appendTo($form);
        $form.submit();
    });

    // Stop button
    $('#stop-btn').on('click', function() {
        $('#start-btn, #stop-btn').prop('disabled', true);
        $('#stop-btn').text('STOPPING...');

        var $form = $(this).closest('form');
        $('<input>').attr({
            type: 'hidden',
            name: 'action',
            value: 'stop'
        }).appendTo($form);
        $form.submit();
    });

    // Auto-submit toggle
    $('#auto-submit-toggle').on('change', function() {
        var enabled = $(this).is(':checked') ? '1' : '0';

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'umbrella_toggle_autosubmit',
                enabled: enabled,
                nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    console.log('Auto-submit toggled:', enabled);
                }
            },
            error: function() {
                console.log('Failed to toggle auto-submit');
            }
        });
    });

    // Auto-refresh terminal output when mining is active
    <?php if ($is_mining): ?>
    setInterval(function() {
        $.ajax({
            url: window.location.href,
            timeout: 2000,
            success: function(data) {
                try {
                    var newOutput = $(data).find('#mining-terminal').html();
                    if (newOutput) {
                        $('#mining-terminal').html(newOutput);
                        var terminal = document.getElementById('mining-terminal');
                        terminal.scrollTop = terminal.scrollHeight;
                    }
                } catch(e) {
                    console.log('Error updating terminal:', e);
                }
            },
            error: function() {
                console.log('Failed to refresh terminal output');
            }
        });
    }, 5000);
    <?php endif; ?>

    // Scroll terminal to bottom on load
    var terminal = document.getElementById('mining-terminal');
    if (terminal) {
        terminal.scrollTop = terminal.scrollHeight;
    }
});
</script>

<style>
    /* Matrix Theme - Dark & Sleek */

    /* Remove WordPress admin padding and extend background */
    #wpbody-content {
        padding-bottom: 0 !important;
    }

    .umbrella-mines-dashboard {
        background: #0a0e27;
        margin: -20px -20px 0 -20px;
        padding: 30px;
        min-height: calc(100vh - 32px);
        color: #e0e0e0;
        font-family: 'Segoe UI', system-ui, sans-serif;
    }

    /* Header */
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #00ff41;
    }

    .dashboard-header h1 {
        margin: 0;
        font-size: 32px;
        font-weight: 700;
        letter-spacing: 3px;
        color: #00ff41;
        text-shadow: 0 0 10px rgba(0, 255, 65, 0.5);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .umbrella-icon {
        font-size: 36px;
        color: #00ff41;
        text-shadow: 0 0 15px rgba(0, 255, 65, 0.7);
        display: inline-block;
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-5px); }
    }

    .night-token {
        color: #00d4ff;
        text-shadow: 0 0 12px rgba(0, 212, 255, 0.6);
        font-weight: 800;
    }

    .status-indicator {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 20px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
    }

    .status-indicator.mining {
        background: rgba(0, 255, 65, 0.15);
        color: #00ff41;
        border: 1px solid #00ff41;
    }

    .status-indicator.idle {
        background: rgba(255, 255, 255, 0.05);
        color: #666;
        border: 1px solid #333;
    }

    .pulse {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor;
        animation: pulse 2s ease-in-out infinite;
    }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        padding: 25px;
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        border-color: #00ff41;
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.1);
        transform: translateY(-2px);
    }

    .stat-card.night {
        background: linear-gradient(145deg, #2d1f3a 0%, #1a0f29 100%);
        border-color: #764ba2;
    }

    .stat-card.night:hover {
        border-color: #9b6bc7;
        box-shadow: 0 0 20px rgba(123, 75, 162, 0.2);
    }

    .stat-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #00ff41;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    .stat-card.night .stat-label {
        color: #9b6bc7;
    }

    .stat-value {
        font-size: 36px;
        font-weight: 700;
        color: #fff;
        margin-bottom: 8px;
        line-height: 1;
    }

    .stat-meta {
        font-size: 12px;
        color: #666;
        letter-spacing: 0.5px;
    }

    /* System Requirements Alert */
    .system-requirements-alert {
        background: linear-gradient(145deg, #3a1a1f 0%, #291014 100%);
        border: 2px solid #ff4444;
        border-radius: 8px;
        margin-bottom: 30px;
        overflow: hidden;
        box-shadow: 0 0 30px rgba(255, 68, 68, 0.3);
    }

    .alert-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 15px 25px;
        background: rgba(255, 68, 68, 0.1);
        border-bottom: 1px solid rgba(255, 68, 68, 0.3);
    }

    .alert-icon {
        font-size: 24px;
    }

    .alert-title {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 2px;
        color: #ff4444;
    }

    .alert-body {
        padding: 20px 25px;
    }

    .alert-body p {
        color: #e0e0e0;
        margin: 0 0 15px 0;
        font-size: 14px;
    }

    .requirements-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .requirement-failed {
        background: rgba(255, 68, 68, 0.05);
        border-left: 3px solid #ff4444;
        padding: 12px 15px;
        margin: 8px 0;
        border-radius: 4px;
        color: #e0e0e0;
        font-size: 13px;
        line-height: 1.6;
    }

    .requirement-failed strong {
        color: #ff4444;
    }

    /* System Requirements */
    .system-requirements {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        margin-bottom: 30px;
        overflow: hidden;
    }

    .requirements-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 25px;
        background: rgba(0, 255, 65, 0.05);
        border-bottom: 1px solid #2a3f5f;
        transition: all 0.2s ease;
    }

    .requirements-header:hover {
        background: rgba(0, 255, 65, 0.08);
    }

    .requirements-toggle {
        font-size: 12px;
        color: #00ff41;
        transition: transform 0.2s ease;
        display: inline-block;
    }

    .requirements-toggle.open {
        transform: rotate(90deg);
    }

    .requirements-title {
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #00ff41;
        user-select: none;
    }

    .requirements-status {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 1px;
        padding: 6px 15px;
        border-radius: 4px;
    }

    .requirements-status.passed {
        background: rgba(0, 255, 65, 0.15);
        color: #00ff41;
        border: 1px solid #00ff41;
    }

    .requirements-status.failed {
        background: rgba(255, 68, 68, 0.15);
        color: #ff4444;
        border: 1px solid #ff4444;
    }

    .requirements-grid {
        padding: 20px 25px;
        display: grid;
        gap: 15px;
    }

    .requirement-item {
        display: flex;
        gap: 15px;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #2a3f5f;
        background: rgba(0, 0, 0, 0.2);
        transition: all 0.3s ease;
    }

    .requirement-item:hover {
        transform: translateX(3px);
        border-color: #00ff41;
    }

    .requirement-item.failed {
        border-color: rgba(255, 68, 68, 0.3);
        background: rgba(255, 68, 68, 0.05);
    }

    .requirement-item.failed:hover {
        border-color: #ff4444;
    }

    .requirement-icon {
        font-size: 24px;
        font-weight: 700;
        line-height: 1;
        flex-shrink: 0;
    }

    .requirement-item.passed .requirement-icon {
        color: #00ff41;
    }

    .requirement-item.failed .requirement-icon {
        color: #ff4444;
    }

    .requirement-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .requirement-name {
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        letter-spacing: 0.5px;
    }

    .requirement-status {
        font-size: 12px;
        color: #999;
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .requirement-label {
        color: #666;
        text-transform: uppercase;
        font-size: 10px;
        letter-spacing: 1px;
    }

    .requirement-value {
        color: #00ff41;
        font-weight: 600;
        font-family: 'Courier New', monospace;
        font-size: 11px;
    }

    .requirement-item.failed .requirement-value {
        color: #ff4444;
    }

    .requirement-separator {
        color: #666;
    }

    .requirement-help {
        font-size: 12px;
        color: #999;
        background: rgba(255, 68, 68, 0.1);
        padding: 10px 12px;
        border-radius: 4px;
        border-left: 2px solid #ff4444;
        line-height: 1.5;
    }

    /* Challenge Info */
    .challenge-info {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        margin-bottom: 30px;
        overflow: hidden;
    }

    .challenge-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 25px;
        background: rgba(0, 255, 65, 0.05);
        border-bottom: 1px solid #2a3f5f;
    }

    .challenge-title {
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #00ff41;
    }

    .challenge-day {
        font-size: 13px;
        font-weight: 700;
        color: #fff;
    }

    .challenge-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        padding: 20px 25px;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .detail-label {
        font-size: 11px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .detail-value {
        font-size: 14px;
        color: #fff;
        font-weight: 500;
    }

    .detail-value code {
        background: rgba(0, 255, 65, 0.1);
        color: #00ff41;
        padding: 4px 8px;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
    }

    /* Control Panel */
    .control-panel {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        margin-bottom: 30px;
        overflow: hidden;
    }

    .panel-header {
        padding: 15px 25px;
        background: rgba(0, 255, 65, 0.05);
        border-bottom: 1px solid #2a3f5f;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #00ff41;
    }

    .control-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        padding: 25px;
    }

    .control-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .control-group label {
        font-size: 12px;
        font-weight: 600;
        color: #00ff41;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .control-group input {
        background: #0a0e27;
        border: 1px solid #2a3f5f;
        color: #fff;
        padding: 12px 15px;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .control-group input:focus {
        outline: none;
        border-color: #00ff41;
        box-shadow: 0 0 0 3px rgba(0, 255, 65, 0.1);
    }

    .control-hint {
        font-size: 11px;
        color: #666;
        letter-spacing: 0.5px;
    }

    .control-actions {
        padding: 0 25px 25px 25px;
    }

    .button-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 5px;
    }

    .btn {
        padding: 18px 35px;
        border: 2px solid transparent;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
    }

    .btn-start {
        background: linear-gradient(135deg, #00ff41 0%, #00cc33 100%);
        color: #0a0e27;
        border-color: #00ff41;
        box-shadow: 0 4px 15px rgba(0, 255, 65, 0.2);
    }

    .btn-start:hover:not(:disabled) {
        box-shadow: 0 0 40px rgba(0, 255, 65, 0.6), 0 4px 20px rgba(0, 255, 65, 0.3);
        transform: translateY(-2px);
        border-color: #00ff41;
    }

    .btn-start:active:not(:disabled) {
        transform: translateY(0px);
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.4);
    }

    .btn-stop {
        background: linear-gradient(135deg, #ff4444 0%, #cc0000 100%);
        color: #fff;
        border-color: #ff4444;
        box-shadow: 0 4px 15px rgba(255, 68, 68, 0.2);
    }

    .btn-stop:hover:not(:disabled) {
        box-shadow: 0 0 40px rgba(255, 68, 68, 0.6), 0 4px 20px rgba(255, 68, 68, 0.3);
        transform: translateY(-2px);
        border-color: #ff6666;
    }

    .btn-stop:active:not(:disabled) {
        transform: translateY(0px);
        box-shadow: 0 0 20px rgba(255, 68, 68, 0.4);
    }

    /* Auto-submit toggle */
    .auto-submit-toggle {
        margin-top: 15px;
        padding: 12px 15px;
        background: rgba(0, 255, 65, 0.05);
        border: 1px solid rgba(0, 255, 65, 0.2);
        border-radius: 6px;
    }

    .auto-submit-toggle label {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        margin: 0;
    }

    .auto-submit-toggle input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #00ff41;
    }

    .auto-submit-toggle span {
        color: #00ff41;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
    }

    /* Terminal */
    .terminal-container {
        background: linear-gradient(145deg, #0d1117 0%, #000000 100%);
        border: 1px solid #00ff41;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 0 30px rgba(0, 255, 65, 0.2);
    }

    .terminal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 20px;
        background: rgba(0, 255, 65, 0.05);
        border-bottom: 1px solid #00ff41;
    }

    .terminal-title {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #00ff41;
    }

    .terminal-meta {
        display: flex;
        gap: 15px;
        align-items: center;
    }

    .terminal-pid {
        font-size: 10px;
        font-weight: 600;
        color: #9b6bc7;
        padding: 4px 10px;
        background: rgba(155, 107, 199, 0.1);
        border-radius: 4px;
        letter-spacing: 1px;
        display: none;
    }

    .terminal-status {
        font-size: 10px;
        font-weight: 700;
        color: #00ff41;
        padding: 4px 10px;
        background: rgba(0, 255, 65, 0.1);
        border-radius: 4px;
        letter-spacing: 1px;
    }

    .terminal-countdown {
        font-size: 10px;
        font-weight: 600;
        color: #666;
        padding: 4px 10px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        letter-spacing: 1px;
    }

    .terminal-output {
        font-family: 'Courier New', 'Consolas', monospace;
        font-size: 13px;
        line-height: 1.6;
        height: 700px;
        overflow-y: auto;
        padding: 20px;
        background: #000;
        color: #00ff41;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    .terminal-output::-webkit-scrollbar {
        width: 12px;
    }

    .terminal-output::-webkit-scrollbar-track {
        background: #0a0e27;
    }

    .terminal-output::-webkit-scrollbar-thumb {
        background: #00ff41;
        border-radius: 6px;
    }

    .terminal-output::-webkit-scrollbar-thumb:hover {
        background: #00cc33;
    }

    .terminal-footer {
        padding: 10px 20px;
        background: rgba(0, 255, 65, 0.03);
        border-top: 1px solid rgba(0, 255, 65, 0.1);
        font-size: 10px;
        color: #666;
        letter-spacing: 1px;
    }
</style>
