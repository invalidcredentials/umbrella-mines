<?php
/**
 * Public Mining Display Page
 * URL: yoursite.com/umbrella-mines
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Get real mining stats
$stats = array(
    'total_wallets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets"),
    'total_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions"),
    'total_receipts' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_receipts"),
);

// Get current challenge
$current_challenge = $wpdb->get_row("
    SELECT * FROM {$wpdb->prefix}umbrella_mining_challenges
    ORDER BY fetched_at DESC
    LIMIT 1
");

// Fetch NIGHT rates from database (same logic as dashboard)
$night_rates_cache = array();
$db_rates = $wpdb->get_results("
    SELECT day, star_per_receipt
    FROM {$wpdb->prefix}umbrella_night_rates
    ORDER BY day ASC
");

if (!empty($db_rates)) {
    // Use database rates
    foreach ($db_rates as $rate) {
        $night_rates_cache[(int)$rate->day - 1] = (int)$rate->star_per_receipt;
    }
}

// Calculate total NIGHT earned - join receipts with their actual challenge days
$total_star = 0;
$night_calculation = $wpdb->get_results("
    SELECT c.day, COUNT(r.id) as receipt_count, n.star_per_receipt
    FROM {$wpdb->prefix}umbrella_mining_receipts r
    INNER JOIN {$wpdb->prefix}umbrella_mining_solutions s ON r.solution_id = s.id
    INNER JOIN {$wpdb->prefix}umbrella_mining_challenges c ON s.challenge_id = c.challenge_id
    INNER JOIN {$wpdb->prefix}umbrella_night_rates n ON c.day = n.day
    GROUP BY c.day, n.star_per_receipt
");

foreach ($night_calculation as $row) {
    $total_star += (int)$row->receipt_count * (int)$row->star_per_receipt;
}
$total_night = $total_star / 1000000;

// Check if mining is currently running
$log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';
$is_mining = false;
$mining_output = '';

if (file_exists($log_file) && filesize($log_file) > 0) {
    // Get last 50 lines for terminal display (more buffer to catch solutions)
    $lines = file($log_file);
    if ($lines !== false) {
        $total_lines = count($lines);
        if ($total_lines > 50) {
            $mining_output = implode('', array_slice($lines, -50));
        } else {
            $mining_output = implode('', $lines);
        }

        // Check if mining is active by looking for stop signal in last 50 lines
        $check_lines = array_slice($lines, -50);
        $recent_content = implode('', $check_lines);

        // If we see the stop signal, mining is NOT active
        if (strpos($recent_content, 'Stop signal received. Mining stopped gracefully') !== false) {
            $is_mining = false;
        } else {
            // Otherwise check if file was recently modified (active mining)
            $last_modified = filemtime($log_file);
            $is_mining = (time() - $last_modified) < 60;
        }
    }
}

// Get site name and custom mine name
$site_name = get_bloginfo('name');
$mine_name = get_option('umbrella_mine_name', 'Umbrella Mines');
if (empty($mine_name)) {
    $mine_name = 'Umbrella Mines';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($site_name); ?> - <?php echo esc_html($mine_name); ?></title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: linear-gradient(180deg, #0a0e27 0%, #1a0a2e 50%, #0a0e27 100%);
            font-family: 'Courier New', monospace;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        /* Neon Title */
        .neon-title {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
        }

        .neon-title h1 {
            margin: 0;
            font-size: 48px;
            font-weight: bold;
            letter-spacing: 8px;
            color: #00ff41;
            text-shadow:
                0 0 10px #00ff41,
                0 0 20px #00ff41,
                0 0 30px #00ff41,
                0 0 40px #00ff41,
                0 0 70px #00ff41,
                0 0 80px #00ff41;
            animation: flicker 3s infinite alternate;
        }

        .subtitle {
            margin: 10px 0 0 0;
            font-size: 18px;
            letter-spacing: 4px;
            color: #00d4ff;
            text-shadow:
                0 0 5px #00d4ff,
                0 0 10px #00d4ff,
                0 0 15px #00d4ff,
                0 0 20px #00d4ff;
        }

        .site-name {
            font-size: 14px;
            color: #666;
            letter-spacing: 2px;
            margin-top: 5px;
        }

        .umbrella-float {
            font-size: 42px;
            display: inline-block;
            color: #00ff41;
            text-shadow:
                0 0 10px #00ff41,
                0 0 20px #00ff41,
                0 0 30px #00ff41;
            animation: float 3s ease-in-out infinite;
            margin: 0 15px;
        }

        @keyframes flicker {
            0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% {
                text-shadow:
                    0 0 10px #00ff41,
                    0 0 20px #00ff41,
                    0 0 30px #00ff41,
                    0 0 40px #00ff41,
                    0 0 70px #00ff41,
                    0 0 80px #00ff41;
            }
            20%, 24%, 55% {
                text-shadow:
                    0 0 5px #00ff41,
                    0 0 10px #00ff41;
            }
        }


        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(5deg); }
            50% { transform: translateY(-10px) rotate(-5deg); }
        }


        /* Page-wide firework particles */
        .page-firework {
            position: fixed;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            pointer-events: none;
            z-index: 9999;
            box-shadow: 0 0 20px currentColor;
            transition: all 1.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        @keyframes page-firework-fade {
            0% {
                opacity: 1;
                transform: scale(1);
            }
            100% {
                opacity: 0;
                transform: scale(0.3);
            }
        }

        .mining-container {
            display: flex;
            gap: 20px;
            max-width: 1240px;
            width: 100%;
            padding: 0 10px;
            box-sizing: border-box;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .mining-container {
                flex-direction: column;
                gap: 15px;
                padding: 0 5px;
            }

            .neon-title h1 {
                font-size: 28px;
                letter-spacing: 4px;
            }

            .neon-title .subtitle {
                font-size: 14px;
                letter-spacing: 2px;
            }

            .umbrella-float {
                font-size: 24px;
                margin: 0 8px;
            }

            .crt-monitor {
                width: 100%;
                max-width: 500px;
                height: auto;
                padding: 15px;
                margin: 0 auto;
            }

            .screen {
                height: 400px;
            }

            #animation-canvas {
                width: 100%;
                height: 100%;
            }

            .monitor-header {
                font-size: 12px;
                letter-spacing: 1px;
            }

            .status-bar {
                font-size: 10px;
                padding: 6px 8px;
            }

            .terminal-output {
                font-size: 11px;
                padding: 10px;
            }

            .toggle-container {
                max-width: 350px;
                padding: 15px;
            }

            .toggle-switch {
                width: 180px;
                height: 70px;
            }

            .toggle-switch::before,
            .toggle-switch::after {
                font-size: 11px;
            }

            .toggle-slider {
                width: 70px;
                height: 52px;
            }

            body.viper-mode .toggle-slider {
                left: 98px;
            }
        }

        /* Very small mobile */
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .neon-title h1 {
                font-size: 22px;
                letter-spacing: 2px;
            }

            .neon-title .subtitle {
                font-size: 12px;
            }

            .umbrella-float {
                font-size: 20px;
                margin: 0 5px;
            }

            .crt-monitor {
                padding: 10px;
            }

            .screen {
                height: 300px;
            }

            .monitor-header {
                font-size: 11px;
                margin-bottom: 10px;
            }

            .terminal-output {
                font-size: 10px;
                line-height: 1.4;
            }

            .toggle-container {
                max-width: 300px;
                padding: 12px;
            }

            .toggle-label {
                font-size: 12px;
                margin-bottom: 12px;
            }

            .toggle-switch {
                width: 160px;
                height: 60px;
            }

            .toggle-switch::before {
                left: 8px;
                font-size: 10px;
            }

            .toggle-switch::after {
                right: 8px;
                font-size: 10px;
            }

            .toggle-slider {
                width: 60px;
                height: 44px;
                left: 6px;
                top: 6px;
            }

            body.viper-mode .toggle-slider {
                left: 90px;
            }
        }

        .crt-monitor {
            width: 600px;
            height: 600px;
            background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
            border: 3px solid #00ff41;
            border-radius: 12px;
            padding: 20px;
            box-shadow:
                0 0 30px rgba(0, 255, 65, 0.5),
                0 0 60px rgba(0, 255, 65, 0.3),
                inset 0 0 20px rgba(0, 0, 0, 0.5);
            position: relative;
            animation: pulse-glow 2s ease-in-out infinite alternate;
        }

        @keyframes pulse-glow {
            0% { box-shadow: 0 0 30px rgba(0, 255, 65, 0.5), 0 0 60px rgba(0, 255, 65, 0.3), inset 0 0 20px rgba(0, 0, 0, 0.5); }
            100% { box-shadow: 0 0 40px rgba(0, 255, 65, 0.7), 0 0 80px rgba(0, 255, 65, 0.4), inset 0 0 20px rgba(0, 0, 0, 0.5); }
        }

        @keyframes glitch-shake {
            0% { transform: translate(0, 0) rotate(0deg); }
            10% { transform: translate(-5px, 2px) rotate(-2deg); }
            20% { transform: translate(3px, -3px) rotate(1deg); }
            30% { transform: translate(-2px, 4px) rotate(-1deg); }
            40% { transform: translate(4px, -2px) rotate(2deg); }
            50% { transform: translate(-3px, 3px) rotate(-1deg); }
            60% { transform: translate(2px, -4px) rotate(1deg); }
            70% { transform: translate(-4px, 1px) rotate(-2deg); }
            80% { transform: translate(3px, -1px) rotate(1deg); }
            90% { transform: translate(-1px, 2px) rotate(-1deg); }
            100% { transform: translate(0, 0) rotate(0deg); }
        }

        .crt-monitor.glitching {
            animation: glitch-shake 0.3s ease-in-out;
        }

        .crt-monitor::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(
                rgba(18, 16, 16, 0) 50%,
                rgba(0, 0, 0, 0.25) 50%
            );
            background-size: 100% 4px;
            pointer-events: none;
            z-index: 10;
        }

        .monitor-header {
            text-align: center;
            color: #00ff41;
            font-size: 14px;
            letter-spacing: 2px;
            margin-bottom: 15px;
            text-shadow: 0 0 10px rgba(0, 255, 65, 0.5);
        }

        .screen {
            width: 100%;
            height: 520px;
            background: #000;
            border: 2px solid #00ff41;
            position: relative;
            overflow: hidden;
        }

        /* Animation Canvas */
        #animation-canvas {
            width: 100%;
            height: 100%;
            image-rendering: pixelated;
            image-rendering: crisp-edges;
        }

        /* Terminal Output */
        .terminal-output {
            width: 100%;
            height: 100%;
            padding: 15px;
            color: #00ff41;
            font-size: 12px;
            line-height: 1.5;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            white-space: pre;
            overflow-x: auto;
            text-align: left;
        }

        .terminal-output::-webkit-scrollbar {
            width: 8px;
        }

        .solution-highlight {
            color: #ffff00;
            font-weight: bold;
            text-shadow: 0 0 10px #ffff00, 0 0 20px #ffff00;
            animation: pulse-glow 1s infinite;
        }

        @keyframes pulse-glow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .terminal-output::-webkit-scrollbar-track {
            background: #0a0e27;
        }

        .terminal-output::-webkit-scrollbar-thumb {
            background: #00ff41;
            border-radius: 4px;
        }

        .terminal-line {
            margin: 2px 0;
        }

        .hash-rate {
            color: #00d4ff;
        }

        .solution-found {
            color: #ffff00;
            font-weight: bold;
            animation: blink 0.5s ease-in-out 3;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .status-bar {
            margin-top: 10px;
            padding: 8px 12px;
            background: rgba(0, 255, 65, 0.1);
            border: 1px solid #00ff41;
            border-radius: 4px;
            color: #00ff41;
            font-size: 11px;
            letter-spacing: 1px;
            text-align: center;
        }

    </style>
</head>
<body>
    <!-- Neon Title Header -->
    <div class="neon-title">
        <h1>
            <span class="umbrella-float">☂</span>
            <?php echo strtoupper(esc_html($mine_name)); ?>
            <span class="umbrella-float">☂</span>
        </h1>
        <p class="subtitle">NIGHT MINER</p>
        <p class="site-name"><?php echo esc_html($site_name); ?></p>
    </div>

    <div class="mining-container">
        <!-- Animation Monitor -->
        <div class="crt-monitor">
            <div class="monitor-header">MINING STATS</div>
            <div class="screen">
                <canvas id="animation-canvas"></canvas>
            </div>
            <div class="status-bar">PICKAXE MINING <?php echo $is_mining ? 'ACTIVE' : 'PAUSED'; ?></div>
        </div>

        <!-- Terminal Monitor -->
        <div class="crt-monitor">
            <div class="monitor-header">LIVE MINING STREAM</div>
            <div class="screen">
                <div class="terminal-output" id="terminal"><?php if (!empty($mining_output)): ?><?php
                    // Highlight solution text
                    $highlighted = str_replace('✨ SOLUTION FOUND!', '<span class="solution-highlight">✨ SOLUTION FOUND!</span>', esc_html($mining_output));
                    echo $highlighted;
                ?><?php else: ?>
                        <div class="terminal-line">No mining activity yet...</div>
                        <div class="terminal-line">Waiting for miner to start...</div><?php endif; ?></div>
            </div>
            <div class="status-bar" id="status"><?php echo $is_mining ? 'MINING ACTIVE' : 'IDLE'; ?></div>
        </div>
    </div>


    <script>
        // Real stats from WordPress
        const REAL_STATS = {
            wallets: <?php echo (int)$stats['total_wallets']; ?>,
            solutions: <?php echo (int)$stats['total_solutions']; ?>,
            nightEarned: <?php echo number_format($total_night, 6, '.', ''); ?>,
            isMining: <?php echo $is_mining ? 'true' : 'false'; ?>
        };

        // Pixel Art Mining Animation
        const canvas = document.getElementById('animation-canvas');
        const ctx = canvas.getContext('2d');

        // Set canvas size responsively
        function resizeCanvas() {
            const container = canvas.parentElement;
            const containerWidth = container.clientWidth;
            const containerHeight = container.clientHeight;

            // Maintain aspect ratio
            const aspectRatio = 560 / 520;

            if (containerWidth / containerHeight > aspectRatio) {
                canvas.height = containerHeight;
                canvas.width = containerHeight * aspectRatio;
            } else {
                canvas.width = containerWidth;
                canvas.height = containerWidth / aspectRatio;
            }
        }

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        // No Viper Mode on public display - always Umbrella theme
        const isViperMode = false;

        // Animation state
        let frame = 0;
        let pickaxeAngle = 0;
        let isSwinging = false;
        let swingProgress = 0;
        let particles = [];
        let totalSwings = 0;
        let swingsSinceSolution = 0;
        let swingsSinceGlitch = 0;
        let nextGlitchAt = Math.floor(Math.random() * 40) + 80; // Random between 80-120 swings

        // Floppy disk position (center of screen)
        const diskX = canvas.width / 2;
        const diskY = canvas.height / 2 + 50;

        // Particle class
        class Particle {
            constructor(x, y) {
                this.x = x;
                this.y = y;
                this.vx = (Math.random() - 0.5) * 8;
                this.vy = (Math.random() - 0.5) * 8 - 4;
                this.life = 30;
                this.size = Math.random() * 4 + 2;
            }

            update() {
                this.x += this.vx;
                this.y += this.vy;
                this.vy += 0.3; // gravity
                this.life--;
            }

            draw() {
                const color = isViperMode ? '255, 51, 51' : '0, 255, 65';
                ctx.fillStyle = `rgba(${color}, ${this.life / 30})`;
                ctx.fillRect(Math.floor(this.x), Math.floor(this.y), this.size, this.size);
            }
        }

        // Firework particle for solutions found
        class Firework {
            constructor(x, y) {
                this.x = x;
                this.y = y;
                const angle = Math.random() * Math.PI * 2;
                const speed = Math.random() * 10 + 6;
                this.vx = Math.cos(angle) * speed;
                this.vy = Math.sin(angle) * speed;
                this.life = 50;
                this.maxLife = 50;
                this.size = Math.random() * 5 + 3;
                // Different colors for each mode
                const umbrellaColors = ['#00ff41', '#00d4ff', '#ffff00', '#ff00ff', '#ff8800'];
                const viperColors = ['#ff3333', '#ffaa00', '#ffff00', '#ffffff', '#ff8800'];
                const colors = isViperMode ? viperColors : umbrellaColors;
                this.color = colors[Math.floor(Math.random() * colors.length)];
            }

            update() {
                this.x += this.vx;
                this.y += this.vy;
                this.vx *= 0.97; // air resistance
                this.vy += 0.3; // gravity
                this.life--;
            }

            draw() {
                const opacity = this.life / this.maxLife;
                ctx.fillStyle = `rgba(${parseInt(this.color.slice(1,3), 16)}, ${parseInt(this.color.slice(3,5), 16)}, ${parseInt(this.color.slice(5,7), 16)}, ${opacity})`;

                // Glow effect
                ctx.shadowBlur = 15;
                ctx.shadowColor = this.color;
                ctx.fillRect(Math.floor(this.x), Math.floor(this.y), this.size, this.size);
                ctx.shadowBlur = 0;
            }
        }

        let fireworks = [];

        // Draw pixelated floppy disk (actual 3.5" disk style)
        function drawFloppyDisk(x, y, hit = false) {
            const scale = 3;
            const offsetY = hit ? 2 : 0; // Shake on hit

            // Main disk body (black plastic)
            ctx.fillStyle = '#1a1a1a';
            ctx.fillRect(x - 20 * scale, y - 25 * scale + offsetY, 40 * scale, 50 * scale);

            // Disk outline
            ctx.strokeStyle = '#333';
            ctx.lineWidth = 2;
            ctx.strokeRect(x - 20 * scale, y - 25 * scale + offsetY, 40 * scale, 50 * scale);

            // Metal shutter (top, sliding part)
            ctx.fillStyle = '#888';
            ctx.fillRect(x - 18 * scale, y - 20 * scale + offsetY, 36 * scale, 12 * scale);

            // Shutter detail lines
            ctx.strokeStyle = '#666';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(x - 18 * scale, y - 14 * scale + offsetY);
            ctx.lineTo(x + 18 * scale, y - 14 * scale + offsetY);
            ctx.stroke();

            // Label area (white sticker)
            ctx.fillStyle = '#e0e0e0';
            ctx.fillRect(x - 16 * scale, y - 2 * scale + offsetY, 32 * scale, 20 * scale);

            // Label border
            ctx.strokeStyle = '#999';
            ctx.lineWidth = 1;
            ctx.strokeRect(x - 16 * scale, y - 2 * scale + offsetY, 32 * scale, 20 * scale);

            // Label text
            ctx.fillStyle = '#000';
            ctx.font = 'bold 14px monospace';
            ctx.fillText('NIGHT', x - 24, y + 12 + offsetY);

            // Write protect notch (bottom right)
            ctx.fillStyle = '#000';
            ctx.fillRect(x + 12 * scale, y + 20 * scale + offsetY, 6 * scale, 4 * scale);

            // Hub hole (center circle for the magnetic disk)
            ctx.fillStyle = '#444';
            ctx.beginPath();
            ctx.arc(x, y - 12 * scale + offsetY, 4 * scale, 0, Math.PI * 2);
            ctx.fill();
        }

        // Draw pixelated pickaxe (Minecraft style)
        function drawPickaxe(x, y, angle) {
            ctx.save();

            // Rotate around the BOTTOM of handle (where hand/wrist grips)
            ctx.translate(x, y);
            ctx.rotate(angle);

            const scale = 3;
            const handleLength = 35 * scale;

            // Handle (wooden brown) - extends UP from rotation point (bottom of handle)
            ctx.fillStyle = '#8B4513';
            ctx.fillRect(-2 * scale, -handleLength, 4 * scale, handleLength);

            // Handle grip detail (darker) - near the bottom where hand is
            ctx.fillStyle = '#6B3410';
            ctx.fillRect(-2 * scale, -10 * scale, 4 * scale, 3 * scale);
            ctx.fillRect(-2 * scale, -20 * scale, 4 * scale, 3 * scale);

            // Pick head mounting (dark metal) - at TOP of handle
            ctx.fillStyle = '#333';
            ctx.fillRect(-3 * scale, -handleLength - 3 * scale, 6 * scale, 6 * scale);

            // Left curved pick (pointed) - extends LEFT from head
            ctx.fillStyle = '#777';
            // Thick part
            ctx.fillRect(-16 * scale, -handleLength - 2 * scale, 13 * scale, 4 * scale);
            // Taper to point
            ctx.fillRect(-18 * scale, -handleLength - 1 * scale, 2 * scale, 2 * scale);
            // Point
            ctx.fillRect(-20 * scale, -handleLength, 2 * scale, 1 * scale);

            // Right curved pick (pointed) - extends RIGHT from head
            ctx.fillStyle = '#777';
            // Thick part
            ctx.fillRect(3 * scale, -handleLength - 2 * scale, 13 * scale, 4 * scale);
            // Taper to point
            ctx.fillRect(16 * scale, -handleLength - 1 * scale, 2 * scale, 2 * scale);
            // Point
            ctx.fillRect(18 * scale, -handleLength, 2 * scale, 1 * scale);

            // Metal highlights (light gray on top edge)
            ctx.fillStyle = '#999';
            ctx.fillRect(-16 * scale, -handleLength - 2 * scale, 13 * scale, 1 * scale);
            ctx.fillRect(3 * scale, -handleLength - 2 * scale, 13 * scale, 1 * scale);

            // Pick point highlights
            ctx.fillStyle = '#aaa';
            ctx.fillRect(-18 * scale, -handleLength - 1 * scale, 2 * scale, 1 * scale);
            ctx.fillRect(16 * scale, -handleLength - 1 * scale, 2 * scale, 1 * scale);

            ctx.restore();
        }

        // Animation loop
        function animate() {
            // Clear canvas
            ctx.fillStyle = '#000';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Draw floppy disk
            const isHit = swingProgress > 0.7 && swingProgress < 0.8;
            drawFloppyDisk(diskX, diskY, isHit);

            // Swing animation - only if mining is active
            if (!isSwinging && REAL_STATS.isMining) {
                // Start new swing every 60 frames
                if (frame % 60 === 0) {
                    isSwinging = true;
                    swingProgress = 0;
                }
            } else if (isSwinging) {
                swingProgress += 0.05;

                // Create particles when hitting
                if (swingProgress > 0.7 && swingProgress < 0.75) {
                    swingsSinceSolution++;
                    totalSwings++;
                    swingsSinceGlitch++;

                    // Check for glitch event
                    if (swingsSinceGlitch >= nextGlitchAt) {
                        const monitor = document.querySelector('.crt-monitor');
                        monitor.classList.add('glitching');
                        setTimeout(() => {
                            monitor.classList.remove('glitching');
                        }, 300);

                        swingsSinceGlitch = 0;
                        nextGlitchAt = Math.floor(Math.random() * 40) + 80; // Next glitch in 80-120 swings
                    }

                    // 5% chance for NICE block hit (extra particles)
                    const isNiceBlock = Math.random() < 0.05;
                    const particleCount = isNiceBlock ? 69 : 5;

                    for (let i = 0; i < particleCount; i++) {
                        particles.push(new Particle(diskX, diskY - 40));
                    }
                }

                if (swingProgress >= 1) {
                    isSwinging = false;
                    swingProgress = 0;
                }
            }

            // Calculate pickaxe position and angle
            if (isSwinging) {
                // Swing arc from top-right to bottom (hitting disk)
                const t = swingProgress;
                pickaxeAngle = -Math.PI / 4 + (Math.PI / 2) * t;
            } else {
                // Return to starting position
                pickaxeAngle = -Math.PI / 4;
            }

            // Pickaxe position (above and to the right of disk)
            const pickX = diskX + 120;
            const pickY = diskY - 60;

            // Draw pickaxe
            drawPickaxe(pickX, pickY, pickaxeAngle);

            // Update and draw particles
            particles = particles.filter(p => p.life > 0);
            particles.forEach(p => {
                p.update();
                p.draw();
            });

            // Update and draw fireworks
            fireworks = fireworks.filter(f => f.life > 0);
            fireworks.forEach(f => {
                f.update();
                f.draw();
            });

            // Stats overlay
            ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
            ctx.fillRect(10, 10, 260, 160);

            // Different colors for different modes
            const primaryColor = isViperMode ? '#ff3333' : '#00ff41';
            const secondaryColor = isViperMode ? '#ffaa00' : '#00d4ff';

            ctx.strokeStyle = primaryColor;
            ctx.lineWidth = 2;
            ctx.strokeRect(10, 10, 260, 160);

            ctx.fillStyle = primaryColor;
            ctx.font = 'bold 12px monospace';
            ctx.fillText('MINING STATS', 20, 30);

            ctx.font = '11px monospace';
            ctx.fillText(`Total Swings: ${totalSwings.toLocaleString()}`, 20, 50);
            ctx.fillText(`Swings Since Solution: ${swingsSinceSolution}`, 20, 70);
            ctx.fillText(`Particles: ${particles.length}`, 20, 90);

            // Real stats from WordPress
            ctx.fillStyle = secondaryColor;
            ctx.fillText(`Wallets: ${REAL_STATS.wallets}`, 20, 115);
            ctx.fillText(`Solutions: ${REAL_STATS.solutions}`, 20, 135);
            ctx.fillText(`NIGHT Earned: ${REAL_STATS.nightEarned}`, 20, 155);

            frame++;
            requestAnimationFrame(animate);
        }

        // Terminal simulation with real solutions
        const terminal = document.getElementById('terminal');
        const statusBar = document.getElementById('status');
        let hashCount = 0;
        let solutionCount = REAL_STATS.solutions;

        function addTerminalLine(text, className = '') {
            const line = document.createElement('div');
            line.className = `terminal-line ${className}`;
            line.textContent = text;
            terminal.appendChild(line);
            terminal.scrollTop = terminal.scrollHeight;

            // Keep only last 50 lines
            while (terminal.children.length > 50) {
                terminal.removeChild(terminal.firstChild);
            }
        }

        function simulateMining() {
            // Random hash updates (only if mining is active)
            setInterval(() => {
                if (REAL_STATS.isMining) {
                    hashCount += Math.floor(Math.random() * 100) + 50;
                    const hashRate = Math.floor(Math.random() * 200) + 800;
                    addTerminalLine(`[${new Date().toLocaleTimeString()}] Hashes: ${hashCount.toLocaleString()} | Rate: ${hashRate} H/s`, 'hash-rate');

                    statusBar.textContent = `MINING ACTIVE | ${hashRate} H/s | ${solutionCount} SOLUTIONS`;
                }
            }, 2000);

            // Random solution found (only if mining is active)
            setInterval(() => {
                if (REAL_STATS.isMining && Math.random() > 0.7) {
                    solutionCount++;
                    const nonce = Math.floor(Math.random() * 999999);
                    addTerminalLine(`>>> SOLUTION FOUND! Nonce: ${nonce} <<<`, 'solution-found');
                    addTerminalLine(`Submitting to Midnight Scavenger API...`);
                    setTimeout(() => {
                        addTerminalLine(`Solution submitted successfully!`);
                    }, 1000);

                    // TRIGGER FIREWORKS ON ANIMATION!
                    triggerFireworks();
                }
            }, 8000);

            // Function to trigger fireworks
            function triggerFireworks() {
                // Launch 100 firework particles from center of canvas
                const centerX = canvas.width / 2;
                const centerY = canvas.height / 2;

                for (let i = 0; i < 100; i++) {
                    fireworks.push(new Firework(centerX, centerY));
                }

                // ALWAYS trigger page-wide fireworks! Finding a solution is rare and special!
                triggerPageFireworks();

                // Random chance for MEGA celebration
                const celebrationType = Math.random();
                if (celebrationType < 0.33) {
                    // Triple burst!
                    setTimeout(() => triggerPageFireworks(), 500);
                    setTimeout(() => triggerPageFireworks(), 1000);
                } else if (celebrationType < 0.66) {
                    // Sustained fireworks
                    setTimeout(() => triggerPageFireworks(), 300);
                    setTimeout(() => triggerPageFireworks(), 600);
                    setTimeout(() => triggerPageFireworks(), 900);
                } else {
                    // MASSIVE single burst
                    setTimeout(() => {
                        for (let i = 0; i < 3; i++) {
                            triggerPageFireworks();
                        }
                    }, 400);
                }
            }

            // Create page-wide fireworks that escape the monitors
            function triggerPageFireworks() {
                const umbrellaColors = ['#00ff41', '#00d4ff', '#ffff00', '#ff00ff', '#ff8800'];
                const viperColors = ['#ff3333', '#ffaa00', '#ffff00', '#ffffff', '#ff8800'];
                const colors = isViperMode ? viperColors : umbrellaColors;
                const numParticles = Math.floor(Math.random() * 30) + 40; // 40-70 particles

                for (let i = 0; i < numParticles; i++) {
                    // Random starting position across the viewport
                    const startX = Math.random() * window.innerWidth;
                    const startY = Math.random() * window.innerHeight;

                    // Random end position (fly outward from center)
                    const centerX = window.innerWidth / 2;
                    const centerY = window.innerHeight / 2;
                    const angle = Math.atan2(startY - centerY, startX - centerX);
                    const distance = Math.random() * 300 + 200; // Fly 200-500px
                    const endX = startX + Math.cos(angle) * distance;
                    const endY = startY + Math.sin(angle) * distance + Math.random() * 200; // Add gravity

                    // Create particle element
                    const particle = document.createElement('div');
                    particle.className = 'page-firework';
                    const color = colors[Math.floor(Math.random() * colors.length)];
                    particle.style.backgroundColor = color;
                    particle.style.left = startX + 'px';
                    particle.style.top = startY + 'px';
                    particle.style.width = (Math.random() * 6 + 4) + 'px';
                    particle.style.height = particle.style.width;

                    document.body.appendChild(particle);

                    // Trigger animation
                    setTimeout(() => {
                        particle.style.left = endX + 'px';
                        particle.style.top = endY + 'px';
                        particle.style.opacity = '0';
                        particle.style.transform = 'scale(0.3)';
                    }, 10);

                    // Remove after animation
                    setTimeout(() => {
                        particle.remove();
                    }, 1600);
                }
            }

            // Initial messages (only if mining is active)
            if (REAL_STATS.isMining) {
                addTerminalLine('=================================');
                addTerminalLine('UMBRELLA MINES MINER v0.3.1');
                addTerminalLine('=================================');
                addTerminalLine('Initializing AshMaize FFI...');
                setTimeout(() => {
                    addTerminalLine('Generating ephemeral wallet...');
                    statusBar.textContent = 'GENERATING WALLET...';
                }, 500);
                setTimeout(() => {
                    addTerminalLine('Wallet created: addr1qx...');
                    addTerminalLine('Fetching current challenge...');
                    statusBar.textContent = 'FETCHING CHALLENGE...';
                }, 1000);
                setTimeout(() => {
                    addTerminalLine('Challenge: 00000abc... | Difficulty: 20');
                    addTerminalLine('Starting mining...');
                    statusBar.textContent = 'MINING ACTIVE | 0 H/s | 0 SOLUTIONS';
                }, 1500);
            }
        }

        // Start animations
        animate();
        // Don't simulate - using real terminal output from PHP

        // Check for solution in current content on load
        <?php if ($is_mining): ?>
        let lastTerminalContent = '';
        try {
            const terminalElement = document.getElementById('terminal');
            if (terminalElement) {
                lastTerminalContent = terminalElement.textContent;

                // Check immediately if there's already a solution visible
                if (lastTerminalContent.includes('✨ SOLUTION FOUND!')) {
                    console.log('🎉 SOLUTION ALREADY ON SCREEN! Triggering celebration!');
                    triggerFireworks();
                }
            }
        } catch (e) {
            console.log('Error checking initial solution:', e);
        }

        // Auto-refresh terminal if mining is active (every 2 seconds for faster detection)
        setInterval(function() {
            try {
                // Fetch latest terminal output
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        try {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newTerminal = doc.querySelector('#terminal');
                            const terminal = document.getElementById('terminal');

                            if (newTerminal && terminal) {
                                const newContent = newTerminal.textContent;

                                // Check if a solution was just found!
                                if (newContent.includes('✨ SOLUTION FOUND!') && !lastTerminalContent.includes('✨ SOLUTION FOUND!')) {
                                    console.log('🎉 SOLUTION DETECTED! Triggering celebration!');
                                    try {
                                        triggerFireworks();
                                        // Also reset the swings counter
                                        swingsSinceSolution = 0;
                                    } catch (e) {
                                        console.log('Error triggering fireworks:', e);
                                    }
                                }

                                terminal.innerHTML = newTerminal.innerHTML;
                                terminal.scrollTop = terminal.scrollHeight;
                                lastTerminalContent = newContent;
                            }
                        } catch (e) {
                            console.log('Error parsing terminal update:', e);
                        }
                    })
                    .catch(err => console.log('Error fetching terminal:', err));
            } catch (e) {
                console.log('Error in terminal refresh interval:', e);
            }
        }, 2000); // Check every 2 seconds instead of 5
        <?php endif; ?>
    </script>
</body>
</html>
