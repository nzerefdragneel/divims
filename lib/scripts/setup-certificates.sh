#!/bin/bash

# Variables
# DOMAINS=(bbb1.scalelitebbb.systems bbb2.scalelitebbb.systems bbb3.scalelitebbb.systems bbb4.scalelitebbb.systems bbb5.scalelitebbb.systems bbb6.scalelitebbb.systems bbb.scalelitebbb.systems)
EMAIL="nguyentranngocsuong94@gmail.com"
WEB_SERVER="nginx"  # Can be nginx or apache
PEM_DESTINATION_DIR="/etc/letsencrypt/live"
DOMAIN_BASE="scalelitebbb.systems"
# Function to install Certbot
install_certbot() {
    if [ -f /etc/debian_version ]; then
        sudo apt update
        sudo apt install -y certbot python3-certbot-$WEB_SERVER
    elif [ -f /etc/redhat-release ]; then
        sudo yum install -y epel-release
        sudo yum install -y certbot python3-certbot-$WEB_SERVER
    else
        echo "Unsupported OS"
        exit 1
    fi
}

# Function to request certificates
request_certificates() {
    DOMAIN_ARGS=""
    for i in {1..9}; do
        DOMAIN="bbbdemo$i.$DOMAIN_BASE"
        DOMAIN_ARGS="$DOMAIN_ARGS -d $DOMAIN"
    done
    # DOMAIN_ARGS="$DOMAIN_ARGS -d bbbdemo.$DOMAIN_BASE"
    # DOMAIN_ARGS="$DOMAIN_ARGS -d bbbdemo.$DOMAIN_BASE -d bbbtest.$DOMAIN_BASE"
    sudo certbot --$WEB_SERVER $DOMAIN_ARGS --email $EMAIL --agree-tos --no-eff-email
}


# Function to save PEM files
save_pem_files() {
    for DOMAIN in "${DOMAINS[@]}"; do
        DOMAIN_DIR="/etc/letsencrypt/live/$DOMAIN"
        if [ ! -d "$PEM_DESTINATION_DIR" ]; then
            sudo mkdir -p "$PEM_DESTINATION_DIR"
        fi
        if [ -d "$DOMAIN_DIR" ]; then
            sudo cp "$DOMAIN_DIR/fullchain.pem" "$PEM_DESTINATION_DIR/$DOMAIN-fullchain.pem"
            sudo cp "$DOMAIN_DIR/privkey.pem" "$PEM_DESTINATION_DIR/$DOMAIN-privkey.pem"
        else
            echo "Directory $DOMAIN_DIR does not exist."
        fi
    done
}

# Function to set up automatic renewal
setup_automatic_renewal() {
    sudo systemctl enable certbot-renew.timer
    sudo systemctl start certbot-renew.timer
}

# Function to test renewal process
test_renewal() {
    sudo certbot renew --dry-run
}

# Main script execution
install_certbot
request_certificates
setup_automatic_renewal
test_renewal

echo "SSL certificates have been obtained and saved."
