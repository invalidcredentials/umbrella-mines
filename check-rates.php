<?php
// Load WordPress
define('ABSPATH', __DIR__ . '/../../../');
require_once(ABSPATH . 'wp-load.php');

global $wpdb;

echo "=== CHECKING NIGHT RATES ===\n\n";

// Check what's in the database
$db_rates = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}umbrella_night_rates ORDER BY day ASC");
echo "Database has " . count($db_rates) . " rates:\n";
foreach ($db_rates as $rate) {
    echo "  Day {$rate->day}: {$rate->star_per_receipt} STAR per receipt (fetched: {$rate->fetched_at})\n";
}

// Check what the API returns
echo "\n=== API RESPONSE ===\n";
$response = wp_remote_get('https://scavenger.prod.gd.midnighttge.io/work_to_star_rate', array(
    'timeout' => 10,
    'sslverify' => false
));

if (is_wp_error($response)) {
    echo "ERROR: " . $response->get_error_message() . "\n";
} else {
    $body = wp_remote_retrieve_body($response);
    echo "Raw response: $body\n";
    $rates = json_decode($body, true);
    echo "\nParsed as array:\n";
    print_r($rates);
}

// Check current challenge
echo "\n=== CURRENT CHALLENGE ===\n";
$current_challenge = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}umbrella_mining_challenges ORDER BY fetched_at DESC LIMIT 1");
if ($current_challenge) {
    echo "Day: {$current_challenge->day}\n";
    echo "Challenge ID: {$current_challenge->challenge_id}\n";
}

// Check receipt count
echo "\n=== RECEIPTS ===\n";
$receipt_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_receipts");
echo "Total receipts: $receipt_count\n";

// Try the calculation
if (!empty($db_rates) && $current_challenge) {
    $current_day = (int)$current_challenge->day;
    echo "\n=== CALCULATION ===\n";
    echo "Current day: $current_day\n";

    // Build rates array
    $night_rates_cache = array();
    foreach ($db_rates as $rate) {
        $night_rates_cache[(int)$rate->day - 1] = (int)$rate->star_per_receipt;
    }

    echo "Looking for index: " . ($current_day - 1) . "\n";
    if (isset($night_rates_cache[$current_day - 1])) {
        $star_per_receipt = $night_rates_cache[$current_day - 1];
        echo "Star per receipt: $star_per_receipt\n";
        $total_star = $receipt_count * $star_per_receipt;
        echo "Total STAR: $total_star\n";
        $total_night = $total_star / 1000000;
        echo "Total NIGHT: $total_night\n";
    } else {
        echo "NO RATE FOUND FOR DAY $current_day!\n";
        echo "Available rates: ";
        print_r($night_rates_cache);
    }
}
