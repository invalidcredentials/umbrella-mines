# Linux Mining Guide - Umbrella Mines

Complete guide for running Umbrella Mines on Linux servers (Ubuntu/Debian/GCP/AWS).

## Prerequisites

1. **WordPress installed and running**
2. **PHP 8.3.x installed** (any 8.3 patch version)
3. **Required PHP extensions:**
   ```bash
   sudo apt install php8.3-bcmath php8.3-gmp php8.3-mysql php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml -y
   ```

4. **WP-CLI installed:**
   ```bash
   curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
   chmod +x wp-cli.phar
   sudo mv wp-cli.phar /usr/local/bin/wp
   wp --info
   ```

## Installing Umbrella Mines Plugin

```bash
cd /var/www/html/wp-content/plugins/
sudo wget https://github.com/invalidcredentials/umbrella-mines/archive/refs/heads/master.zip
sudo unzip master.zip
sudo mv umbrella-mines-master umbrella-mines
sudo chown -R www-data:www-data umbrella-mines
sudo chmod -R 755 umbrella-mines
sudo rm master.zip
```

Then activate in WordPress admin: Plugins → Umbrella Mines → Activate

## Running Miners

### Single Miner Instance

```bash
cd /var/www/html
wp umbrella-mines start --max-attempts=500000 --derive=0/0/0 --allow-root
```

### Multiple Miner Instances (Background)

**Start 10 miners with different derivation paths:**

```bash
cd /var/www/html

# Kill any existing miners first
pkill -f "umbrella-mines start"

# Start 10 miners in background (no logs)
nohup wp umbrella-mines start --max-attempts=500000 --derive=0/0/0 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=1/1/1 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=2/2/2 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=3/3/3 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=4/4/4 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=5/5/5 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=6/6/6 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=7/7/7 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=8/8/8 --allow-root > /dev/null 2>&1 &
nohup wp umbrella-mines start --max-attempts=500000 --derive=9/9/9 --allow-root > /dev/null 2>&1 &
```

**Or use a loop for 20+ miners:**

```bash
cd /var/www/html
for i in {0..19}; do
  nohup wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 &
done
```

## Managing Miners

### Check Running Miners

```bash
ps aux | grep "umbrella-mines start"
```

### Count Running Miners

```bash
ps aux | grep "umbrella-mines start" | grep -v grep | wc -l
```

### Stop All Miners

```bash
pkill -f "umbrella-mines start"
```

### Stop Single Miner (by PID)

```bash
# Find PID
ps aux | grep "umbrella-mines start"

# Kill specific PID
kill <PID>
```

### Restart All Miners

```bash
# Stop all
pkill -f "umbrella-mines start"

# Wait 2 seconds
sleep 2

# Start 10 miners
cd /var/www/html
for i in {0..9}; do
  nohup wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 &
done
```

## Monitoring Results

**All results go to WordPress database - NO log files saved!**

### View in WordPress Admin

1. Go to: `http://YOUR_SERVER_IP/wp-admin`
2. Navigate to: **Umbrella Mines → Dashboard**
3. View wallets: **Umbrella Mines → Solutions**

### Check Database Directly

```bash
# Count total wallets generated
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_wallets;" --allow-root

# Count solutions found
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_solutions;" --allow-root

# Recent solutions
wp db query "SELECT * FROM wp_umbrella_mining_solutions ORDER BY created_at DESC LIMIT 5;" --allow-root
```

## Server Resource Optimization

### How Many Miners to Run?

**Rule of thumb:** 1-2 miners per CPU core

```bash
# Check CPU cores
nproc

# Example: 8 cores = run 8-16 miners
# Example: 16 cores = run 16-32 miners
```

### Monitor Server Resources

```bash
# CPU usage
top

# Or install htop for better view
sudo apt install htop -y
htop

# Memory usage
free -h

# Disk usage
df -h
```

### Recommended Settings by Server Size

| Server Type | Cores | RAM | Recommended Miners |
|------------|-------|-----|-------------------|
| Small (t4g.small) | 2 | 2GB | 2-4 miners |
| Medium (e2-medium) | 2 | 4GB | 4-6 miners |
| Large (t4g.large) | 2 | 8GB | 4-8 miners |
| XL (e2-standard-4) | 4 | 16GB | 8-16 miners |
| 2XL (e2-standard-8) | 8 | 32GB | 16-32 miners |

## Auto-Start Miners on Server Boot

Create a systemd service to auto-start miners when server reboots:

```bash
# Create service file
sudo nano /etc/systemd/system/umbrella-mines.service
```

**Paste this:**

```ini
[Unit]
Description=Umbrella Mines Background Miners
After=network.target mysql.service apache2.service

[Service]
Type=forking
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/bin/bash -c 'for i in {0..9}; do nohup /usr/local/bin/wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 & done'
ExecStop=/usr/bin/pkill -f "umbrella-mines start"
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

**Enable and start:**

```bash
sudo systemctl daemon-reload
sudo systemctl enable umbrella-mines.service
sudo systemctl start umbrella-mines.service

# Check status
sudo systemctl status umbrella-mines.service
```

## Troubleshooting

### Error: "ext/bcmath is required"

```bash
sudo apt install php8.3-bcmath -y
sudo systemctl restart apache2
```

### Error: "FFI extension not loaded"

```bash
sudo apt install php8.3-ffi -y
echo "ffi.enable=1" | sudo tee -a /etc/php/8.3/cli/php.ini
sudo systemctl restart apache2
```

### Error: "WP-CLI not found"

```bash
# Reinstall WP-CLI
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp
```

### Miners Not Starting

```bash
# Check WordPress is accessible
curl -I http://localhost/wp-admin/

# Check plugin is activated
wp plugin list --allow-root

# Activate if needed
wp plugin activate umbrella-mines --allow-root
```

### Server Running Out of Memory

```bash
# Check memory usage
free -h

# If low, reduce number of miners
pkill -f "umbrella-mines start"

# Start fewer miners (half)
for i in {0..4}; do
  nohup wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 &
done
```

## Performance Tips

1. **Use derivation paths that spread across the keyspace:**
   - Good: 0/0/0, 1/1/1, 2/2/2, etc.
   - Also good: 0/0/0, 0/0/1, 0/0/2, etc.

2. **Increase max-attempts for longer mining per wallet:**
   ```bash
   --max-attempts=1000000  # 1 million attempts
   ```

3. **Monitor for solutions regularly:**
   ```bash
   watch -n 10 'wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_solutions;" --allow-root'
   ```

4. **Keep WordPress database optimized:**
   ```bash
   wp db optimize --allow-root
   ```

## Quick Reference Commands

```bash
# Start 10 miners
cd /var/www/html && for i in {0..9}; do nohup wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 & done

# Stop all miners
pkill -f "umbrella-mines start"

# Check running miners
ps aux | grep "umbrella-mines start" | grep -v grep

# Count solutions
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_solutions;" --allow-root
```

## Support

- GitHub: https://github.com/invalidcredentials/umbrella-mines
- Issues: https://github.com/invalidcredentials/umbrella-mines/issues
- Website: https://umbrella.lol

---

**Happy Mining!** 🌂⛏️
