#!/usr/bin/env bash

# Retrieve new data
OLD_EXTERNAL_IPV4=165.22.51.241
NEW_EXTERNAL_IPV4=152.42.253.176
EMAIL=nguyentranngocsuong94@gmail.com
OLD_DOMAIN=bbbclone.scalelitebbb.systems
NEW_DOMAIN=bbbssh.scalelitebbb.systems
# Define the file paths

# Restart services to apply changes
sudo systemctl restart freeswitch
sudo systemctl restart nginx
echo "Require new certificate for $NEW_DOMAIN"
unlink /etc/nginx/sites-enabled/bigbluebutton
service nginx restart
certbot certonly --non-interactive -a webroot --webroot-path /var/www/html --domain $NEW_DOMAIN --email tech@arawa.fr --agree-tos --rsa-key-size=4096 --renew-hook="systemctl restart nginx"
#Reconfigure nginx
(cd /etc/nginx/sites-enabled && ln -s ../sites-available/bigbluebutton)
link /etc/nginx/sites-enabled/bigbluebutton
service nginx reload
echo "Delete old certificate"
sed -i -e "s#/var/www/html#/var/www/bigbluebutton-default#" /etc/letsencrypt/renewal/${NEW_DOMAIN}.conf
echo "Reconfigure BBB domain"
bbb-conf --setip $NEW_DOMAIN

# echo "IP addresses updated and services restarted."
# sudo chmod -R 644 /etc/nginx/sites-available/bbbcheck.scalelitebbb.systems
# sudo chmod -R 644 /etc/nginx/sites-enabled/bbbcheck.scalelitebbb.systems

    if [[ -f "/etc/letsencrypt/live/$NEW_DOMAIN/fullchain.pem" ]] && [[ -f "/etc/letsencrypt/renewal/$NEW_DOMAIN.conf" ]] \
        && ! grep -q '/var/www/bigbluebutton-default/assets' "/etc/letsencrypt/renewal/$NEW_DOMAIN.conf"; then
      sed -i -e 's#/var/www/bigbluebutton-default#/var/www/bigbluebutton-default/assets#' "/etc/letsencrypt/renewal/$NEW_DOMAIN.conf"
      if ! certbot renew; then
        err "Let's Encrypt SSL renewal request for $NEW_DOMAIN did not succeed - exiting"
      fi
    fi


  if [ ! -f "/etc/letsencrypt/live/$NEW_DOMAIN/fullchain.pem" ]; then
    rm -f /tmp/bigbluebutton.bak
    if ! grep -q "$NEW_DOMAIN" /etc/nginx/sites-available/bigbluebutton; then  # make sure we can do the challenge
      if [ -f /etc/nginx/sites-available/bigbluebutton ]; then
        cp /etc/nginx/sites-available/bigbluebutton /tmp/bigbluebutton.bak
      fi
