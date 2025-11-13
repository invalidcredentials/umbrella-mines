<?php
/**
 * Umbrella Mines Dashboard - Mining Control Center
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Handle mining start/stop actions
$log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';
$is_mining = false;
$mining_output = '';

if (file_exists($log_file)) {
    $mining_output = file_get_contents($log_file);
    $last_modified = filemtime($log_file);
    $is_mining = (time() - $last_modified) < 60; // Active if updated in last 60 seconds
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mining_action'])) {
    check_admin_referer('umbrella_mining_control', 'mining_nonce');

    $action = $_POST['mining_action'];

    if ($action === 'start') {
        $max_attempts = intval($_POST['max_attempts'] ?? 500000);
        $derivation_path = sanitize_text_field($_POST['derivation_path'] ?? '0/0/0');

        // Clear old log
        file_put_contents($log_file, "=== MINING STARTED ===\n");

        // Find PHP CLI
        $php_cli = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        if (strpos($php_cli, 'php-cgi.exe') !== false) {
            $php_cli = str_replace('php-cgi.exe', 'php.exe', $php_cli);
        }

        // Find WP-CLI
        $wp_cli = '';
        $possible_paths = array(
            'C:/Users/' . (getenv('USERNAME') ?: getenv('USER') ?: get_current_user()) . '/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar',
            dirname(ABSPATH) . '/vendor/bin/wp',
            '/usr/local/bin/wp',
        );
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $wp_cli = $path;
                break;
            }
        }

        // Build command
        $cmd = sprintf(
            'cd %s && %s %s umbrella-mines start --max-attempts=%d --derive=%s >> %s 2>&1',
            escapeshellarg(ABSPATH),
            escapeshellarg($php_cli),
            escapeshellarg($wp_cli),
            $max_attempts,
            escapeshellarg($derivation_path),
            escapeshellarg($log_file)
        );

        // Run in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            exec($cmd . " &");
        }

        echo '<div class="notice notice-success"><p><strong>Mining started!</strong> Refresh page to see output.</p></div>';

    } elseif ($action === 'stop') {
        file_put_contents($log_file, $mining_output . "\n\n=== MINING STOPPED BY USER ===\n");

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('taskkill /F /IM php.exe /FI "WINDOWTITLE eq wp*" 2>&1');
        } else {
            exec("pkill -f 'wp umbrella-mines'");
        }

        echo '<div class="notice notice-warning"><p><strong>Mining stopped!</strong></p></div>';
    }
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
        set_transient('umbrella_night_rates', $night_rates_cache, 3600); // Cache 1 hour
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

// Get active mining processes (if tracked)
$active_processes = $wpdb->get_results("
    SELECT * FROM {$wpdb->prefix}umbrella_mining_processes
    WHERE status = 'running'
    ORDER BY started_at DESC
", ARRAY_A);

?>

<div class="wrap umbrella-dashboard">
    <h1>⛏️ Umbrella Mines - Mining Control Center</h1>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">👛</div>
            <div class="stat-content">
                <h3>Wallets</h3>
                <p class="stat-value"><?php echo number_format($stats['total_wallets']); ?></p>
                <small><?php echo number_format($stats['registered_wallets']); ?> registered</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">💎</div>
            <div class="stat-content">
                <h3>Solutions Found</h3>
                <p class="stat-value"><?php echo number_format($stats['total_solutions']); ?></p>
                <small><?php echo number_format($stats['submitted_solutions']); ?> submitted</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-content">
                <h3>Pending</h3>
                <p class="stat-value"><?php echo number_format($stats['pending_solutions']); ?></p>
                <small>Waiting to submit</small>
            </div>
        </div>

        <div class="stat-card night-earned">
            <div class="stat-icon">🌙</div>
            <div class="stat-content">
                <h3>NIGHT Earned</h3>
                <p class="stat-value"><?php echo number_format($total_night, 6); ?></p>
                <small><?php echo number_format($stats['total_receipts']); ?> receipts</small>
            </div>
        </div>
    </div>

    <!-- NIGHT Tracker -->
    <?php if ($current_challenge && $night_rates_cache): ?>
    <div class="card night-tracker">
        <h2>🌙 NIGHT Token Tracker</h2>
        <div class="challenge-info-grid">
            <div>
                <strong>Challenge Day:</strong>
                <?php echo (int)$current_challenge->day; ?> / 21
            </div>
            <div>
                <strong>Current Difficulty:</strong>
                <code><?php echo esc_html($current_challenge->difficulty); ?></code>
            </div>
            <div>
                <strong>STAR per Solution:</strong>
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
            </div>
            <div>
                <strong>Mining Ends:</strong>
                <?php echo esc_html($current_challenge->mining_period_ends ?? 'N/A'); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mining Control Panel -->
    <div class="card mining-controls">
        <h2>⚙️ Mining Control Panel</h2>

        <form id="mining-form" class="mining-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="max-attempts">Max Attempts per Wallet:</label>
                    <input type="number" id="max-attempts" name="max_attempts" value="500000" min="10000" step="10000" class="regular-text">
                    <p class="description">Number of nonces to try before generating new wallet</p>
                </div>

                <div class="form-group">
                    <label for="derivation-path">Derivation Path:</label>
                    <input type="text" id="derivation-path" name="derivation_path" value="0/0/0" class="regular-text" placeholder="0/0/0">
                    <p class="description">Format: account/chain/address (e.g., 0/0/0)</p>
                </div>
            </div>

            <div class="button-group">
                <button type="button" id="start-single" class="button button-primary button-hero">
                    ▶️ Start Mining (Single Process)
                </button>
                <button type="button" id="force-run" class="button button-secondary">
                    ⚡ Force Run Now (Skip Cron Wait)
                </button>
                <button type="button" id="stop-all" class="button button-danger">
                    ⏹️ Stop All Processes
                </button>
            </div>
        </form>

        <div class="auto-submit-toggle">
            <label>
                <input type="checkbox" id="auto-submit" <?php echo (!isset($config['submission_enabled']) || $config['submission_enabled'] == '1') ? 'checked' : ''; ?>>
                <strong>Auto-submit solutions</strong> when found (vs. manual review in Solutions tab)
            </label>
        </div>
    </div>

    <!-- Live Mining Output -->
    <div class="card mining-output">
        <h2>📊 Live Mining Processes</h2>
        <div id="process-status">
            <p style="color: #666;">No active mining processes. Click "Start Mining" above to begin.</p>
        </div>

        <div id="mining-logs" class="terminal-output">
            <div class="terminal-header">Terminal Output</div>
            <pre id="terminal-content">Waiting for mining process to start...</pre>
        </div>
    </div>

    <!-- WP-CLI Command Reference -->
    <div class="card cli-reference">
        <h2>💻 Manual WP-CLI Commands</h2>
        <p>You can also start mining directly via command line:</p>

        <div class="cli-command">
            <code>wp umbrella-mines start --max-attempts=500000 --derive=0/0/0</code>
            <button class="button button-small copy-btn" data-clipboard-text="wp umbrella-mines start --max-attempts=500000 --derive=0/0/0">Copy</button>
        </div>

        <div class="cli-command">
            <code>wp umbrella-mines start --max-attempts=500000 --derive=0/0/1 &</code>
            <button class="button button-small copy-btn" data-clipboard-text="wp umbrella-mines start --max-attempts=500000 --derive=0/0/1 &">Copy</button>
            <small style="margin-left: 10px;">Run in background</small>
        </div>
    </div>
</div>

<style>
.umbrella-dashboard {
    max-width: 1400px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.stat-card.night-earned {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.stat-icon {
    font-size: 48px;
    line-height: 1;
}

.stat-content h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    font-weight: 600;
    opacity: 0.8;
}

.stat-value {
    font-size: 32px;
    font-weight: bold;
    margin: 5px 0;
    line-height: 1;
}

.card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 25px;
    margin: 20px 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.card h2 {
    margin-top: 0;
    font-size: 20px;
    font-weight: 600;
}

.night-tracker {
    background: linear-gradient(to right, #f8f9fa, #e9ecef);
    border-left: 4px solid #667eea;
}

.challenge-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.mining-form {
    margin: 20px 0;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

.form-group .description {
    margin: 5px 0 0 0;
    font-style: italic;
    color: #666;
}

.button-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin: 20px 0;
}

.button-hero {
    height: auto;
    padding: 12px 24px;
    font-size: 16px;
}

#start-single {
    background: #7c3aed !important;
    border-color: #6d28d9 !important;
    color: white !important;
}

#start-single:hover {
    background: #6d28d9 !important;
    border-color: #5b21b6 !important;
}

.button-danger {
    background: #dc3545;
    border-color: #dc3545;
    color: white;
}

.button-danger:hover {
    background: #c82333;
    border-color: #bd2130;
}

.auto-submit-toggle {
    margin-top: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
}

.terminal-output {
    background: #1e1e1e;
    border-radius: 4px;
    margin-top: 15px;
    overflow: hidden;
}

.terminal-header {
    background: #2d2d2d;
    color: #fff;
    padding: 10px 15px;
    font-family: monospace;
    font-size: 12px;
}

#terminal-content {
    color: #d4d4d4;
    padding: 15px;
    margin: 0;
    max-height: 500px;
    overflow-y: auto;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.5;
}

#process-status {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
    margin-bottom: 15px;
}

.cli-reference {
    background: #f8f9fa;
}

.cli-command {
    background: #2d2d2d;
    color: #d4d4d4;
    padding: 12px 15px;
    border-radius: 4px;
    margin: 10px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: monospace;
}

.cli-command code {
    flex: 1;
    background: none;
    padding: 0;
}

.copy-btn {
    flex-shrink: 0;
}
</style>

<script>
jQuery(document).ready(function($) {
    let sseSource = null;

    // Auto-submit toggle
    $('#auto-submit').on('change', function() {
        const enabled = $(this).is(':checked') ? '1' : '0';
        $.post(ajaxurl, {
            action: 'umbrella_toggle_autosubmit',
            enabled: enabled,
            nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
        });
    });

    // Start mining - single process
    $('#start-single').on('click', function() {
        startMining(1);
    });

    // Force run now (manual cron trigger)
    $('#force-run').on('click', function() {
        $(this).prop('disabled', true).text('⏳ Processing...');
        $.post(ajaxurl, {
            action: 'umbrella_force_run',
            nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
        }, function(response) {
            $('#force-run').prop('disabled', false).text('⚡ Force Run Now');
            if (response.success) {
                alert('Cron executed! Check terminal output for updates.');
                pollMiningStatus();
            }
        });
    });

    // Stop all processes
    $('#stop-all').on('click', function() {
        if (confirm('Stop all active mining processes?')) {
            stopAllMining();
        }
    });

    function startMining(count) {
        const maxAttempts = $('#max-attempts').val();
        const derivationPath = $('#derivation-path').val();

        $('#process-status').html('<p style="color: #0073aa;">⚙️ Starting mining process' + (count > 1 ? 'es' : '') + '...</p>');
        $('#terminal-content').text('Initializing mining engine...\n');

        $.post(ajaxurl, {
            action: 'umbrella_start_mining',
            count: count,
            max_attempts: maxAttempts,
            derivation_path: derivationPath,
            nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
        }, function(response) {
            if (response.success) {
                $('#process-status').html('<p style="color: green;">✅ ' + response.data.message + '</p>');
                startSSE();
            } else {
                $('#process-status').html('<p style="color: red;">❌ Error: ' + response.data + '</p>');
            }
        }).fail(function() {
            $('#process-status').html('<p style="color: red;">❌ Failed to start mining process</p>');
        });
    }

    function stopAllMining() {
        $('#process-status').html('<p style="color: #666;">⏹️ Stopping all processes...</p>');

        $.post(ajaxurl, {
            action: 'umbrella_stop_mining',
            nonce: '<?php echo wp_create_nonce('umbrella_mining'); ?>'
        }, function(response) {
            if (response.success) {
                $('#process-status').html('<p style="color: orange;">⏹️ All processes stopped</p>');
                stopSSE();
                setTimeout(() => location.reload(), 2000);
            }
        });
    }

    function startSSE() {
        // Poll every 3 seconds for live updates
        pollMiningStatus();

        // Start polling interval
        sseSource = setInterval(pollMiningStatus, 3000);
    }

    function stopSSE() {
        if (sseSource) {
            clearInterval(sseSource);
            sseSource = null;
        }
    }

    function pollMiningStatus() {
        $.get(ajaxurl + '?action=umbrella_get_mining_status', function(response) {
            if (response.success) {
                const data = response.data;

                // Update process status
                if (data.processes && data.processes.length > 0) {
                    let statusHTML = '<p style="color: green;">● ' + data.processes.length + ' active mining process(es)</p>';
                    statusHTML += '<ul style="margin: 10px 0; padding-left: 20px;">';
                    data.processes.forEach(function(proc) {
                        statusHTML += '<li>Process #' + proc.id + ' | Path: ' + proc.derivation_path + ' | Status: ' + proc.status + '</li>';
                    });
                    statusHTML += '</ul>';
                    $('#process-status').html(statusHTML);
                } else {
                    $('#process-status').html('<p style="color: #666;">No active mining processes.</p>');
                }

                // Update terminal output
                if (data.output) {
                    $('#terminal-content').text(data.output);
                    // Auto-scroll to bottom
                    const terminalDiv = $('#terminal-content')[0];
                    terminalDiv.scrollTop = terminalDiv.scrollHeight;
                }
            }
        });
    }

    // Start polling on page load to show any existing processes
    pollMiningStatus();

    // Copy buttons
    $('.copy-btn').on('click', function() {
        const text = $(this).data('clipboard-text');
        navigator.clipboard.writeText(text);
        $(this).text('Copied!');
        setTimeout(() => $(this).text('Copy'), 2000);
    });
});
</script>
