<?php
/**
 * Manual Submit - Just paste and submit
 */

if (!defined('ABSPATH')) exit;

$submitted = false;
$result = '';

// Handle submission
if (isset($_POST['manual_submit']) && check_admin_referer('night_mine_manual_submit')) {
    $address = sanitize_text_field($_POST['address']);
    $challenge_id = sanitize_text_field($_POST['challenge_id']);
    $nonce = sanitize_text_field($_POST['nonce']);

    $url = "https://scavenger.prod.gd.midnighttge.io/solution/{$address}/{$challenge_id}/{$nonce}";

    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array(
            'Content-Type' => 'application/json; charset=utf-8'
        ),
        'body' => '{}',
        'timeout' => 180,  // 3 minutes - API is slow!
        'sslverify' => false
    ));

    $submitted = true;

    if (is_wp_error($response)) {
        $result = array(
            'success' => false,
            'error' => $response->get_error_message()
        );
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $result = array(
            'success' => ($status_code == 200 || $status_code == 201),
            'status_code' => $status_code,
            'body' => $body,
            'url' => $url
        );
    }
}

?>

<?php require_once __DIR__ . '/admin-styles.php'; ?>

<style>
.manual-submit-form {
    background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
    padding: 40px;
    border: 2px solid #2a3f5f;
    border-radius: 12px;
    max-width: 900px;
    margin: 30px 0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

.manual-submit-form table {
    width: 100%;
}

.manual-submit-form th {
    padding: 15px 20px 15px 0;
    font-size: 12px;
    font-weight: 600;
    color: #00ff41;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    text-align: left;
    vertical-align: top;
    width: 180px;
}

.manual-submit-form td {
    padding: 15px 0;
}

.manual-submit-form input[type="text"] {
    width: 100%;
    font-family: 'Courier New', monospace;
    background: linear-gradient(145deg, #0a0e27 0%, #050815 100%);
    border: 2px solid #2a3f5f;
    color: #00ff41;
    padding: 14px 16px;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.manual-submit-form input[type="text"]:focus {
    outline: none;
    border-color: #00ff41;
    box-shadow: 0 0 15px rgba(0, 255, 65, 0.3);
}

.submit-btn-hero {
    background: linear-gradient(135deg, #00ff41 0%, #00d435 100%) !important;
    border: none !important;
    color: #000 !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    padding: 16px 40px !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    text-transform: uppercase !important;
    letter-spacing: 2px !important;
    box-shadow: 0 4px 20px rgba(0, 255, 65, 0.4) !important;
    transition: all 0.3s ease !important;
}

.submit-btn-hero:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 25px rgba(0, 255, 65, 0.6) !important;
}

.success-box {
    background: rgba(0, 255, 65, 0.05);
    border: 2px solid #00ff41;
    border-radius: 12px;
    padding: 30px;
    margin: 30px 0;
    max-width: 900px;
}

.success-box h2 {
    color: #00ff41;
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 20px 0;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.error-box {
    background: rgba(255, 51, 102, 0.05);
    border: 2px solid #ff3366;
    border-radius: 12px;
    padding: 30px;
    margin: 30px 0;
    max-width: 900px;
}

.error-box h2 {
    color: #ff3366;
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 20px 0;
    text-transform: uppercase;
    letter-spacing: 2px;
}

.info-box-manual {
    background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
    padding: 30px;
    margin: 30px 0;
    border-left: 4px solid #00ff41;
    border-radius: 8px;
    max-width: 900px;
}

.info-box-manual h3 {
    margin-top: 0;
    color: #00ff41;
    font-size: 16px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 20px;
}

.info-box-manual ol {
    color: #e0e0e0;
    line-height: 2;
    margin: 0;
    padding-left: 25px;
}

.info-box-manual ol li {
    margin-bottom: 10px;
}

.example-box {
    background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
    padding: 30px;
    margin: 30px 0;
    border-left: 4px solid #764ba2;
    border-radius: 8px;
    max-width: 900px;
}

.example-box h3 {
    margin-top: 0;
    color: #9b6bc7;
    font-size: 16px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 20px;
}

.example-box code {
    background: rgba(0, 255, 65, 0.1);
    color: #00ff41;
    padding: 8px 12px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    word-break: break-all;
}

.url-display {
    background: rgba(0, 212, 255, 0.05);
    padding: 20px;
    margin: 20px 0;
    border-left: 4px solid #00d4ff;
    border-radius: 8px;
    max-width: 900px;
}

.url-display strong {
    color: #00d4ff;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.url-display code {
    display: block;
    background: rgba(0, 212, 255, 0.1);
    color: #00d4ff;
    padding: 12px;
    border-radius: 6px;
    margin-top: 10px;
    word-break: break-all;
    font-family: 'Courier New', monospace;
    font-size: 12px;
}
</style>

<div class="wrap umbrella-mines-page">
    <div class="page-header">
        <h1><span class="umbrella-icon">☂</span> UMBRELLA MINES <span class="page-subtitle">MANUAL SUBMIT</span></h1>
    </div>

    <p style="color: #999; margin-bottom: 30px; font-size: 15px;">Paste your solution details and submit directly to the Scavenger Mine API</p>

    <?php if ($submitted): ?>
        <?php if ($result['success']): ?>
            <div class="success-box">
                <h2>✅ Success</h2>
                <p style="color: #e0e0e0; margin-bottom: 15px;"><strong>HTTP Status:</strong> <?php echo $result['status_code']; ?></p>
                <p style="color: #e0e0e0; margin-bottom: 10px;"><strong>API Response:</strong></p>
                <pre style="background: rgba(0, 255, 65, 0.1); padding: 20px; overflow: auto; color: #00ff41; border-radius: 8px; border: 1px solid rgba(0, 255, 65, 0.3); margin: 0;"><?php echo esc_html($result['body']); ?></pre>
            </div>
        <?php else: ?>
            <div class="error-box">
                <h2>❌ Failed</h2>
                <?php if (isset($result['error'])): ?>
                    <p style="color: #e0e0e0; margin: 0;"><strong>Error:</strong> <?php echo esc_html($result['error']); ?></p>
                <?php else: ?>
                    <p style="color: #e0e0e0; margin-bottom: 15px;"><strong>HTTP Status:</strong> <?php echo $result['status_code']; ?></p>
                    <p style="color: #e0e0e0; margin-bottom: 10px;"><strong>API Response:</strong></p>
                    <pre style="background: rgba(255, 51, 102, 0.1); padding: 20px; overflow: auto; color: #ff3366; border-radius: 8px; border: 1px solid rgba(255, 51, 102, 0.3); margin: 0;"><?php echo esc_html($result['body']); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($result['url'])): ?>
            <div class="url-display">
                <strong>Submitted to:</strong>
                <code><?php echo esc_html($result['url']); ?></code>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="manual-submit-form">
        <?php wp_nonce_field('night_mine_manual_submit'); ?>

        <table>
            <tr>
                <th><label for="address">Wallet Address</label></th>
                <td>
                    <input type="text" name="address" id="address" value="<?php echo isset($_POST['address']) ? esc_attr($_POST['address']) : ''; ?>" required>
                    <p class="description" style="color: #666; margin-top: 8px; font-size: 12px;">Full Cardano address (addr1...)</p>
                </td>
            </tr>
            <tr>
                <th><label for="challenge_id">Challenge ID</label></th>
                <td>
                    <input type="text" name="challenge_id" id="challenge_id" value="<?php echo isset($_POST['challenge_id']) ? esc_attr($_POST['challenge_id']) : ''; ?>" required>
                    <p class="description" style="color: #666; margin-top: 8px; font-size: 12px;">Example: **D07C02</p>
                </td>
            </tr>
            <tr>
                <th><label for="nonce">Nonce</label></th>
                <td>
                    <input type="text" name="nonce" id="nonce" value="<?php echo isset($_POST['nonce']) ? esc_attr($_POST['nonce']) : ''; ?>" required>
                    <p class="description" style="color: #666; margin-top: 8px; font-size: 12px;">16 hex characters (e.g., ce10c84ef2d07520)</p>
                </td>
            </tr>
        </table>

        <p class="submit" style="margin-top: 30px; text-align: center;">
            <input type="submit" name="manual_submit" class="submit-btn-hero" value="🚀 Submit to API">
        </p>
    </form>

    <div class="info-box-manual">
        <h3>💡 How To Use</h3>
        <ol>
            <li>Get your solution details from the Solutions page or database export</li>
            <li>Paste the wallet address, challenge ID, and nonce into the form above</li>
            <li>Click "Submit to API" and wait for the response (can take up to 3 minutes)</li>
            <li>Check the response to confirm your solution was accepted</li>
        </ol>
    </div>

    <div class="example-box">
        <h3>📋 Example Values</h3>
        <p style="color: #e0e0e0; margin-bottom: 12px;"><strong style="color: #00ff41;">Address:</strong><br>
        <code>addr1qy8frfevn2hz7n7xeqqdvt8x09jwjmzc7q02axp5g9qtyh53x7n56zv2m9xlmkln9kntddpny6qu6e95qeu9u96dsjvqjlwrx8</code></p>

        <p style="color: #e0e0e0; margin-bottom: 12px;"><strong style="color: #00ff41;">Challenge ID:</strong><br>
        <code>**D07C02</code></p>

        <p style="color: #e0e0e0; margin-bottom: 12px;"><strong style="color: #00ff41;">Nonce:</strong><br>
        <code>ce10c84ef2d07520</code></p>

        <p style="margin-bottom: 0; color: #666; font-style: italic; font-size: 13px;">This example solution has 20 leading zero bits and should be accepted by the API.</p>
    </div>
</div>
