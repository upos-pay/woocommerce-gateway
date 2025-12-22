#!/bin/bash
set -e

# Ensure log directories are writable for the web server user
# WordPress official image uses www-data user for Apache/PHP
mkdir -p /var/www/html/wp-content/uploads/wc-logs
mkdir -p /var/www/html/wp-content/uploads/upos-logs
chown -R www-data:www-data /var/www/html/wp-content/uploads
chmod -R 777 /var/www/html/wp-content/uploads

# Run original WordPress entrypoint
docker-entrypoint.sh apache2-foreground &

# Wait for WordPress to be ready
sleep 15

# Check if WordPress is installed
if ! wp core is-installed --allow-root 2>/dev/null; then
    echo "Installing WordPress..."
    wp core install \
        --url="http://localhost:8088" \
        --title="UPOS Development" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="admin@example.com" \
        --skip-email \
        --allow-root
fi

# Install and activate WooCommerce if not already
if ! wp plugin is-installed woocommerce --allow-root 2>/dev/null; then
    echo "Installing WooCommerce..."
    wp plugin install woocommerce --activate --allow-root
fi

if ! wp plugin is-active woocommerce --allow-root 2>/dev/null; then
    wp plugin activate woocommerce --allow-root
fi

# Configure WooCommerce
wp option update woocommerce_store_address "Test Address" --allow-root 2>/dev/null || true
wp option update woocommerce_store_city "Taipei" --allow-root 2>/dev/null || true
wp option update woocommerce_default_country "TW" --allow-root 2>/dev/null || true
wp option update woocommerce_currency "TWD" --allow-root 2>/dev/null || true
wp option update woocommerce_calc_taxes "no" --allow-root 2>/dev/null || true

# Create a test product if none exist
PRODUCT_COUNT=$(wp post list --post_type=product --format=count --allow-root 2>/dev/null || echo "0")
if [ "$PRODUCT_COUNT" -eq "0" ]; then
    echo "Creating test product..."
    wp wc product create \
        --name="Test Product" \
        --type=simple \
        --regular_price=100 \
        --status=publish \
        --user=1 \
        --allow-root 2>/dev/null || true
fi

# Activate UPOS plugin if exists
if wp plugin is-installed upos-woocommerce --allow-root 2>/dev/null; then
    wp plugin activate upos-woocommerce --allow-root 2>/dev/null || true
fi

# Configure MailHog for email testing
wp option update mail_from "wordpress@localhost" --allow-root 2>/dev/null || true

echo "WordPress setup complete!"
echo "Admin URL: http://localhost:8088/wp-admin"
echo "Username: admin"
echo "Password: admin"

# Keep container running
wait
