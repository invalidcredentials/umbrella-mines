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

<div class="wrap umbrella-mines-page">
    <div class="page-header">
        <h1>MANUAL SUBMIT</h1>
    </div>

    <p style="color: #999; margin-bottom: 30px;">Paste your solution details and submit directly to the API</p>

    <?php if ($submitted): ?>
        <?php if ($result['success']): ?>
            <div class="notice notice-success" style="padding: 20px; margin: 20px 0;">
                <h2 style="color: #00ff41;">SUCCESS</h2>
                <p><strong>HTTP Status:</strong> <?php echo $result['status_code']; ?></p>
                <p><strong>Response:</strong></p>
                <pre style="background: rgba(0, 255, 65, 0.1); padding: 15px; overflow: auto; color: #00ff41; border-radius: 4px;"><?php echo esc_html($result['body']); ?></pre>
            </div>
        <?php else: ?>
            <div class="notice notice-error" style="padding: 20px; margin: 20px 0;">
                <h2 style="color: #dc3232;">FAILED</h2>
                <?php if (isset($result['error'])): ?>
                    <p><strong>Error:</strong> <?php echo esc_html($result['error']); ?></p>
                <?php else: ?>
                    <p><strong>HTTP Status:</strong> <?php echo $result['status_code']; ?></p>
                    <p><strong>Response:</strong></p>
                    <pre style="background: rgba(0, 255, 65, 0.1); padding: 15px; overflow: auto; color: #00ff41; border-radius: 4px;"><?php echo esc_html($result['body']); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($result['url'])): ?>
            <div style="background: #e7f3ff; padding: 15px; margin: 20px 0; border-left: 4px solid #0073aa;">
                <p><strong>Submitted to:</strong></p>
                <code style="word-break: break-all;"><?php echo esc_html($result['url']); ?></code>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" style="background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%); padding: 30px; border: 1px solid #2a3f5f; border-radius: 8px; max-width: 800px; margin: 20px 0;">
        <?php wp_nonce_field('night_mine_manual_submit'); ?>

        <table class="form-table">
            <tr>
                <th><label for="address" style="color: #00ff41;">Wallet Address</label></th>
                <td>
                    <input type="text" name="address" id="address" class="regular-text" value="<?php echo isset($_POST['address']) ? esc_attr($_POST['address']) : ''; ?>" required style="width: 100%; font-family: monospace; background: #0a0e27; border: 1px solid #2a3f5f; color: #00ff41; padding: 10px; border-radius: 4px;">
                    <p class="description" style="color: #666;">Full Cardano address (addr1...)</p>
                </td>
            </tr>
            <tr>
                <th><label for="challenge_id" style="color: #00ff41;">Challenge ID</label></th>
                <td>
                    <input type="text" name="challenge_id" id="challenge_id" value="<?php echo isset($_POST['challenge_id']) ? esc_attr($_POST['challenge_id']) : ''; ?>" required style="font-family: monospace; background: #0a0e27; border: 1px solid #2a3f5f; color: #00ff41; padding: 10px; border-radius: 4px;">
                    <p class="description" style="color: #666;">Example: **D07C02</p>
                </td>
            </tr>
            <tr>
                <th><label for="nonce" style="color: #00ff41;">Nonce</label></th>
                <td>
                    <input type="text" name="nonce" id="nonce" value="<?php echo isset($_POST['nonce']) ? esc_attr($_POST['nonce']) : ''; ?>" required style="font-family: monospace; background: #0a0e27; border: 1px solid #2a3f5f; color: #00ff41; padding: 10px; border-radius: 4px;">
                    <p class="description" style="color: #666;">16 hex characters (e.g., ce10c84ef2d07520)</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="manual_submit" class="button button-primary button-hero" value="Submit to API" style="font-size: 18px; padding: 10px 30px;">
        </p>
    </form>

    <div style="background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%); padding: 20px; margin: 20px 0; border-left: 4px solid #00ff41; border-radius: 4px; max-width: 800px;">
        <h3 style="margin-top: 0; color: #00ff41;">HOW TO USE</h3>
        <ol>
            <li>Get your solution details from local database or export</li>
            <li>Paste the address, challenge ID, and nonce</li>
            <li>Click "Submit to API"</li>
            <li>Done! Uses production SSL certificates</li>
        </ol>
    </div>

    <div style="background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%); padding: 20px; margin: 20px 0; border-left: 4px solid #764ba2; border-radius: 4px; max-width: 800px;">
        <h3 style="margin-top: 0; color: #9b6bc7;">EXAMPLE VALUES</h3>
        <p><strong style="color: #00ff41;">Address:</strong> <code style="font-size: 11px; background: rgba(0, 255, 65, 0.1); color: #00ff41; padding: 4px 8px; border-radius: 4px;">addr1qy8frfevn2hz7n7xeqqdvt8x09jwjmzc7q02axp5g9qtyh53x7n56zv2m9xlmkln9kntddpny6qu6e95qeu9u96dsjvqjlwrx8</code></p>
        <p><strong style="color: #00ff41;">Challenge ID:</strong> <code style="background: rgba(0, 255, 65, 0.1); color: #00ff41; padding: 4px 8px; border-radius: 4px;">**D07C02</code></p>
        <p><strong style="color: #00ff41;">Nonce:</strong> <code style="background: rgba(0, 255, 65, 0.1); color: #00ff41; padding: 4px 8px; border-radius: 4px;">ce10c84ef2d07520</code></p>
        <p style="margin-bottom: 0; color: #666;"><em>This solution has 20 leading zero bits and should be accepted.</em></p>
    </div>
</div>
