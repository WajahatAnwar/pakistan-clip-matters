#!/bin/bash

# Fix storage permissions
echo "Fixing storage directory permissions..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Set proper ownership (change 'www-data' to your web server user if different)
# Common users: www-data (Ubuntu/Debian), nginx, apache
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache

# Fix voice_samples directory specifically
mkdir -p storage/app/public/voice_samples
chmod -R 775 storage/app/public/voice_samples
chown -R www-data:www-data storage/app/public/voice_samples

# Ensure storage link exists
php artisan storage:link

echo "✓ Permissions fixed!"
