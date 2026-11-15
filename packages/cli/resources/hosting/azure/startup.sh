#!/bin/sh
# Emitted only. Operator must review before configuring this startup command.
# Copy reviewed nginx.conf to /home/site/wwwroot/deploy/corevia-nginx.conf.
set -eu
cp /home/site/wwwroot/deploy/corevia-nginx.conf /etc/nginx/sites-available/default
nginx -t
service nginx reload
# This customizes the web image; it does not start a durable queue worker.
