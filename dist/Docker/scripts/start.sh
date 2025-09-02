#!/bin/bash

# Export environment variables to make them available system-wide
env | awk -F= '{ 
    key=$1; 
    $1=""; 
    sub(/^=/, "", $0); 
    # Remove leading whitespace from the value
    sub(/^[ \t]+/, "", $0);
    gsub(/"/, "\\\"", $0); # Escape quotes in values
    printf("export %s=\"%s\"\n", key, $0); 
}' > /etc/profile.d/appservice_env.sh

chmod +x /etc/profile.d/appservice_env.sh
source /etc/profile.d/appservice_env.sh

# Configure PHP-FPM to pass environment variables
echo "[www]" > /etc/php/8.3/fpm/pool.d/env.conf
echo "; Pass environment variables to PHP-FPM" >> /etc/php/8.3/fpm/pool.d/env.conf

# Add each environment variable to PHP-FPM configuration
env | while read -r line; do
    [[ -z "$line" ]] && continue
    key="${line%%=*}"
    value="${line#*=}"

    # Skip system vars and empty values
    if [[ -n "$value" && ! "$key" =~ ^(PWD|SHLVL|_|PATH|HOSTNAME|OLDPWD)$ ]]; then
        escaped_value=$(printf '%s\n' "$value" | sed 's/"/\\"/g')
        echo "env[$key] = \"$escaped_value\"" >> /etc/php/8.3/fpm/pool.d/env.conf
        echo "[DEBUG] Added env var to PHP-FPM: $key"
    else
        echo "[DEBUG] Skipped env var: $key (empty or system)"
    fi
done

# Create additional log directories if needed
mkdir -p /var/log/nginx /var/log
touch /var/log/nginx/access.log /var/log/nginx/error.log /var/log/drupal-cron.log /var/log/php_errors.log

# Set proper permissions for logs
chown -R www-data:adm /var/log/nginx
chmod -R 755 /var/log/nginx
chmod 666 /var/log/drupal-cron.log /var/log/php_errors.log

# Link Nginx logs to stdout/stderr for Azure App Service monitoring
ln -sf /dev/stdout /var/log/nginx/access.log
ln -sf /dev/stderr /var/log/nginx/error.log

# Optional: Link PHP error log to stderr as well
ln -sf /dev/stderr /var/log/php_errors.log

# Set up crontab to run every minute
echo "*/1 * * * * root /bin/bash -c 'source /etc/profile.d/appservice_env.sh && /var/www/html/cron.sh'" > /etc/cron.d/drupal-cron
chmod 0644 /etc/cron.d/drupal-cron

# Start SSH service for Azure App Service
echo "Starting SSH service..."
service ssh start

# Verify SSH is running
if ! pgrep -x "sshd" > /dev/null; then
    echo "Warning: SSH failed to start"
    # Don't exit, continue with other services
fi

# Start cron service
service cron start

# Start PHP-FPM service
echo "Starting PHP-FPM service..."
service php8.3-fpm start

# Verify PHP-FPM is running
if ! pgrep -x "php-fpm8.3" > /dev/null; then
    echo "Error: PHP-FPM failed to start"
    echo "[DEBUG] Contents of env.conf:"
    cat /etc/php/8.3/fpm/pool.d/env.conf || true
    exit 1
fi

# Test nginx configuration before starting
nginx -t
if [ $? -ne 0 ]; then
    echo "Error: Nginx configuration test failed"
    exit 1
fi

echo "All services started successfully. Starting Nginx in foreground mode..."

# Start Nginx in foreground mode
nginx -g "daemon off;"
