# Linux Mining Guide - Umbrella Mines

Complete guide for running Umbrella Mines on Linux servers (Ubuntu/Debian/GCP/AWS).

## Quick Start - Fresh Server Setup

If you're starting from scratch on a new GCP/AWS instance, follow these steps in order:

### Step 1: Update System & Install LAMP Stack

```bash
# Update packages
sudo apt update && sudo apt upgrade -y

# Install Apache, MariaDB, PHP, and ALL required extensions
sudo apt install apache2 mariadb-server php php-cli php-common php-mysql php-curl php-gd php-mbstring php-xml php-xmlrpc php-soap php-intl php-zip php-bcmath php-gmp libapache2-mod-php unzip wget -y

# Start services
sudo systemctl start apache2 mariadb
sudo systemctl enable apache2 mariadb
```

**⚠️ CRITICAL:** `php-bcmath` and `php-gmp` are REQUIRED. The plugin will NOT work without them!

### Step 2: Configure Firewall (GCP/Cloud Platforms)

**For Google Cloud Platform:**

1. Go to **VPC Network** → **Firewall**
2. Click **"Create Firewall Rule"**
3. Create rule for HTTP:
   - Name: `allow-http`
   - Targets: All instances in the network
   - Source IP ranges: `0.0.0.0/0`
   - Protocols: `tcp:80`
4. Click **Create**

**For AWS:** Ensure Security Group allows inbound traffic on port 80.

### Step 3: Create MySQL Database

```bash
sudo mysql
```

Paste these commands:

```sql
CREATE DATABASE wordpress;
CREATE USER 'wpuser'@'localhost' IDENTIFIED BY 'StrongPassword123!';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wpuser'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 4: Install WordPress

```bash
cd /tmp
wget https://wordpress.org/latest.tar.gz
tar -xzvf latest.tar.gz
sudo cp -r wordpress/* /var/www/html/
sudo rm /var/www/html/index.html
sudo chown -R www-data:www-data /var/www/html/
sudo chmod -R 755 /var/www/html/
```

### Step 5: Get Server IP & Complete WordPress Setup

```bash
curl ifconfig.me
```

**Go to `http://YOUR_IP/` in your browser and complete WordPress installation:**

- Database name: `wordpress`
- Username: `wpuser`
- Password: `StrongPassword123!`
- Database Host: `localhost`
- Table Prefix: `wp_` (default)

Create your WordPress admin account when prompted.

### Step 6: Install WP-CLI

```bash
cd ~
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp

# Verify installation
wp --info
```

### Step 7: Install Umbrella Mines Plugin

```bash
cd /var/www/html/wp-content/plugins/
sudo wget https://github.com/invalidcredentials/umbrella-mines/archive/refs/heads/master.zip
sudo unzip master.zip
sudo mv umbrella-mines-master umbrella-mines
sudo chown -R www-data:www-data umbrella-mines
sudo chmod -R 755 umbrella-mines
sudo rm master.zip
```

**Activate in WordPress admin:** Go to Plugins → Umbrella Mines → Activate

### Step 8: Update WordPress Site URL (IMPORTANT!)

```bash
cd /var/www/html

# Get your server IP
MY_IP=$(curl -s ifconfig.me)

# Update WordPress URLs
wp option update siteurl "http://$MY_IP" --allow-root
wp option update home "http://$MY_IP" --allow-root
```

This prevents redirect issues when accessing wp-admin.

---

## Prerequisites (If Already Have WordPress)

If WordPress is already installed, ensure you have:

1. **PHP 8.0 or higher** (plugin supports PHP 8.0, 8.1, 8.2, 8.3, 8.4+)
2. **CRITICAL PHP extensions** (plugin will NOT work without these):
   ```bash
   sudo apt install php-bcmath php-gmp php-mysql php-curl php-gd php-mbstring php-xml php-intl php-zip unzip -y
   sudo systemctl restart apache2
   ```

3. **WP-CLI installed:**
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

### Single Miner Instance (Foreground - See Live Output)

```bash
cd /var/www/html
wp umbrella-mines start --max-attempts=500000 --derive=0/0/0 --allow-root
```

Press Ctrl+C to stop. Use this for testing to see hashrate and output.

### Multiple Miner Instances (Background - Production Use)

**Start 6 miners in background (no log files):**

```bash
cd /var/www/html

# Kill any existing miners first
pkill -f "umbrella-mines start"

# Start 6 miners
wp umbrella-mines start --max-attempts=500000 --derive=0/0/0 --allow-root > /dev/null 2>&1 &
sleep 1
wp umbrella-mines start --max-attempts=500000 --derive=1/1/1 --allow-root > /dev/null 2>&1 &
sleep 1
wp umbrella-mines start --max-attempts=500000 --derive=2/2/2 --allow-root > /dev/null 2>&1 &
sleep 1
wp umbrella-mines start --max-attempts=500000 --derive=3/3/3 --allow-root > /dev/null 2>&1 &
sleep 1
wp umbrella-mines start --max-attempts=500000 --derive=4/4/4 --allow-root > /dev/null 2>&1 &
sleep 1
wp umbrella-mines start --max-attempts=500000 --derive=5/5/5 --allow-root > /dev/null 2>&1 &
```

**Start 16 miners on large servers (one-liner):**

```bash
cd /var/www/html && pkill -f "umbrella-mines start" && wp umbrella-mines start --max-attempts=500000 --derive=0/0/0 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=1/1/1 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=2/2/2 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=3/3/3 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=4/4/4 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=5/5/5 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=6/6/6 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=7/7/7 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=8/8/8 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=9/9/9 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=10/10/10 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=11/11/11 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=12/12/12 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=13/13/13 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=14/14/14 --allow-root > /dev/null 2>&1 & wp umbrella-mines start --max-attempts=500000 --derive=15/15/15 --allow-root > /dev/null 2>&1 &
```

## Managing Miners

### Check Running Miners

```bash
# Show all running miners
ps aux | grep "umbrella-mines start" | grep -v grep

# Count running miners
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

# Start 10 miners with sleep between each
cd /var/www/html
for i in {0..9}; do
  wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 &
  sleep 1
done
```

## Monitoring Results

**All results go to WordPress database - NO log files saved!**

### View in WordPress Admin

1. Go to: `http://YOUR_SERVER_IP/wp-admin`
2. Navigate to: **Umbrella Mines → Dashboard**
3. View solutions: **Umbrella Mines → Solutions**

### Check Database Directly

```bash
# Count total wallets generated
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_wallets;" --allow-root --skip-column-names

# Count solutions found
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_solutions;" --allow-root --skip-column-names

# Recent solutions
wp db query "SELECT * FROM wp_umbrella_mining_solutions ORDER BY created_at DESC LIMIT 5;" --allow-root

# Watch wallet count update every 10 seconds (shows mining activity)
watch -n 10 'wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_wallets;" --allow-root --skip-column-names'
```

Press Ctrl+C to stop watching.

## Server Resource Optimization

### How Many Miners to Run?

**Rule of thumb:** 1-2 miners per CPU core

```bash
# Check CPU cores
nproc

# Example: 4 cores = run 4-8 miners
# Example: 8 cores = run 8-16 miners
# Example: 16 cores = run 16-32 miners
```

### Monitor Server Resources

```bash
# CPU and memory usage (real-time)
htop

# If htop not installed
sudo apt install htop -y
htop

# Press 'q' to exit htop

# Check memory usage
free -h

# Check disk usage
df -h
```

### Recommended Settings by Server Size

| Server Type | Cores | RAM | Recommended Miners | Cost/Month |
|------------|-------|-----|-------------------|------------|
| Small (e2-micro) | 2 | 1GB | 2-3 miners | ~$7 |
| Medium (e2-medium) | 2 | 4GB | 4-6 miners | ~$25 |
| Large (e2-standard-2) | 2 | 8GB | 4-8 miners | ~$50 |
| XL (n2d-standard-4) | 4 | 16GB | 8-12 miners | ~$100 |
| 2XL (n2d-highmem-8) | 8 | 64GB | 16-24 miners | ~$250 |

**ARM instances (t4g on AWS, t2a on GCP) are 20% cheaper and work great!**

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
After=network.target mysql.service apache2.service mariadb.service

[Service]
Type=forking
User=www-data
WorkingDirectory=/var/www/html
ExecStart=/bin/bash -c 'for i in {0..9}; do /usr/local/bin/wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 & sleep 1; done'
ExecStop=/usr/bin/pkill -f "umbrella-mines start"
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Save: Ctrl+O, Enter, Ctrl+X

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

**This is the most common error!** Install bcmath and gmp:

```bash
sudo apt install php-bcmath php-gmp -y
sudo systemctl restart apache2

# Verify they're loaded
php -m | grep bcmath
php -m | grep gmp
```

### Error: "This plugin does not work with your version of PHP"

Install any PHP 8.0+ version:

```bash
# Check current PHP version
php -v

# If below 8.0, install PHP 8.2 (stable)
sudo apt install php8.2 php8.2-cli php8.2-common php8.2-mysql php8.2-curl php8.2-gd php8.2-mbstring php8.2-xml php8.2-bcmath php8.2-gmp libapache2-mod-php8.2 -y

# Enable PHP 8.2 in Apache
sudo a2dismod php7.4 php8.0 php8.1  # disable old versions
sudo a2enmod php8.2
sudo systemctl restart apache2
```

### Error: "FFI extension not loaded"

```bash
sudo apt install php-ffi -y
echo "ffi.enable=1" | sudo tee -a /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/cli/php.ini
sudo systemctl restart apache2
```

### Error: "WP-CLI not found"

```bash
# Reinstall WP-CLI
cd ~
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp

# Verify
wp --info
```

### Miners Not Starting

```bash
# Check WordPress is accessible
curl -I http://localhost/wp-admin/

# Check plugin is activated
wp plugin list --allow-root

# Activate if needed
wp plugin activate umbrella-mines --allow-root

# Try starting one miner in foreground to see errors
cd /var/www/html
wp umbrella-mines start --max-attempts=500000 --derive=0/0/0 --allow-root
```

### WordPress Admin Won't Load / Keeps Redirecting

**This happens when server IP changes!** Update WordPress URLs:

```bash
cd /var/www/html

# Get current IP
MY_IP=$(curl -s ifconfig.me)

# Update WordPress site URL
wp option update siteurl "http://$MY_IP" --allow-root
wp option update home "http://$MY_IP" --allow-root
```

Then access: `http://YOUR_NEW_IP/wp-admin`

### Can't Access WordPress (Connection Refused)

**Check if Apache is running:**

```bash
sudo systemctl status apache2

# If stopped, start it
sudo systemctl start apache2
```

**Check firewall rules** (GCP/AWS) - ensure port 80 is open.

### Server Frozen / Out of Memory

```bash
# Check memory usage
free -h

# If memory is full, stop some miners
pkill -f "umbrella-mines start"

# Reduce number of miners (run half as many)
cd /var/www/html
for i in {0..4}; do
  wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 &
  sleep 1
done
```

### Database Getting Too Large

```bash
# Check database size
wp db size --allow-root

# Optimize database
wp db optimize --allow-root

# Delete old wallets (keep only registered ones with solutions)
wp db query "DELETE FROM wp_umbrella_mining_wallets WHERE registered_at IS NULL AND id NOT IN (SELECT DISTINCT wallet_id FROM wp_umbrella_mining_solutions);" --allow-root
```

## Performance Tips

1. **Use unique derivation paths for each miner:**
   - Good: 0/0/0, 1/1/1, 2/2/2, etc.
   - Also good: 0/0/0, 0/0/1, 0/0/2, etc.
   - Avoid duplicates!

2. **Increase max-attempts for longer mining runs per wallet:**
   ```bash
   --max-attempts=1000000  # 1 million attempts per wallet
   ```

3. **Monitor for solutions regularly:**
   ```bash
   watch -n 10 'wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_solutions;" --allow-root --skip-column-names'
   ```

4. **Keep WordPress database optimized:**
   ```bash
   # Run weekly
   wp db optimize --allow-root
   ```

5. **Start miners with a delay to avoid startup congestion:**
   ```bash
   for i in {0..9}; do
     wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 &
     sleep 1  # 1 second delay between each
   done
   ```

## Quick Reference Commands

```bash
# Install required extensions (DO THIS FIRST!)
sudo apt install php-bcmath php-gmp unzip -y && sudo systemctl restart apache2

# Install WP-CLI
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp

# Start 10 miners (one-liner with delays)
cd /var/www/html && for i in {0..9}; do wp umbrella-mines start --max-attempts=500000 --derive=$i/$i/$i --allow-root > /dev/null 2>&1 & sleep 1; done

# Stop all miners
pkill -f "umbrella-mines start"

# Check running miners
ps aux | grep "umbrella-mines start" | grep -v grep | wc -l

# Count solutions
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_solutions;" --allow-root --skip-column-names

# Count wallets
wp db query "SELECT COUNT(*) FROM wp_umbrella_mining_wallets;" --allow-root --skip-column-names

# Update WordPress URLs after IP change
cd /var/www/html && MY_IP=$(curl -s ifconfig.me) && wp option update siteurl "http://$MY_IP" --allow-root && wp option update home "http://$MY_IP" --allow-root
```

## Support

- **GitHub:** https://github.com/invalidcredentials/umbrella-mines
- **Issues:** https://github.com/invalidcredentials/umbrella-mines/issues
- **Website:** https://umbrella.lol

---

**Happy Mining!** 🌂⛏️
