#!/usr/bin/env bash

# Retrieve new data
OLD_EXTERNAL_IPV4=152.42.190.150
NEW_EXTERNAL_IPV4=104.248.148.228
EMAIL=ntnsuong.c5ntrai@gmail.com
OLD_DOMAIN=bbbv3.scalelitebbb.systems
NEW_DOMAIN=bbbv1.scalelitebbb.systems
# Define the file paths
FREESWITCH_VARS_FILE="/opt/freeswitch/etc/freeswitch/vars.xml"
NGINX_SIP_FILE="/usr/share/bigbluebutton/nginx/sip.nginx"
FREESWITCH_EXTERNAL_PROFILE_FILE="/opt/freeswitch/etc/freeswitch/sip_profiles/external.xml"
BBB_EXPORTER_FILE="/etc/bigbluebutton-exporter/settings.env"
# //grep "wsUrl" /usr/share/meteor/bundle/programs/server/assets/app/config/settings.yml
sed -i "s/${$OLD_DOMAIN}/${$NEW_DOMAIN}/g" $BBB_EXPORTER_FILE


sudo systemctl restart bigbluebutton-exporter
