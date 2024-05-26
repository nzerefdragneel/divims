#!/usr/bin/env bash

# Retrieve new data
OLD_EXTERNAL_IPV4="159.223.41.152"
NEW_EXTERNAL_IPV4="152.42.212.132"
EMAIL="nguyentranngocsuong94@gmail.com"
NEW_DOMAIN="bbb6.scalelitebbb.systems"
# Define the file paths
FREESWITCH_VARS_FILE="/opt/freeswitch/etc/freeswitch/vars.xml"
NGINX_SIP_FILE="/usr/share/bigbluebutton/nginx/sip.nginx"
FREESWITCH_EXTERNAL_PROFILE_FILE="/opt/freeswitch/etc/freeswitch/sip_profiles/external.xml"


wget -qO- https://raw.githubusercontent.com/bigbluebutton/bbb-install/v3.0.x-release/bbb-install.sh | bash -s -- -w -v jammy-300 -s $NEW_DOMAIN -e $EMAIL

# Update IP address in FreeSWITCH vars.xml
sed -i -r -e "s/${OLD_EXTERNAL_IPV4}/${NEW_EXTERNAL_IPV4}/g" "$FREESWITCH_VARS_FILE"

# Update IP address in FreeSWITCH external profile
sed -i -r -e "s/${OLD_EXTERNAL_IPV4}/${NEW_EXTERNAL_IPV4}/g" "$FREESWITCH_EXTERNAL_PROFILE_FILE"

# Update IP address in NGINX sip.nginx
sed -i -r -e "s/${OLD_EXTERNAL_IPV4}/${NEW_EXTERNAL_IPV4}/g" "$NGINX_SIP_FILE"

# Additionally, update the protocol and port for NGINX configuration if needed
sed -i -r -e "s/http:\/\/${NEW_EXTERNAL_IPV4}:5066/https:\/\/${NEW_EXTERNAL_IPV4}:7443/g" "$NGINX_SIP_FILE"

# Restart services to apply changes
sudo systemctl restart freeswitch
sudo systemctl restart nginx

echo "IP addresses updated and services restarted."

