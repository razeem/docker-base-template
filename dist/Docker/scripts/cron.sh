#!/bin/bash

# Source environment variables
source /etc/profile.d/appservice_env.sh

# Change to Drupal directory
cd /var/www/html

# Log cron execution
echo "$(date): Running Drupal cron" >> /var/log/drupal-cron.log

# Check if Drush exists in vendor/bin (Composer installed)
if [ -f "vendor/bin/drush" ]; then
    echo "$(date): Using Drush from vendor/bin" >> /var/log/drupal-cron.log
    ./vendor/bin/drush cron 2>&1 | tee -a /var/log/drupal-cron.log
elif [ -f "vendor/drush/drush/drush" ]; then
    echo "$(date): Using Drush from vendor/drush/drush" >> /var/log/drupal-cron.log
    ./vendor/drush/drush/drush cron 2>&1 | tee -a /var/log/drupal-cron.log
else
    echo "$(date): Drush not found, skipping cron execution" >> /var/log/drupal-cron.log
fi

# Log completion
echo "$(date): Drupal cron completed" >> /var/log/drupal-cron.log