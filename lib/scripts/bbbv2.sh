#!/usr/bin/env bash

# Retrieve new data
OLD_EXTERNAL_IPV4=152.42.161.28
NEW_EXTERNAL_IPV4=159.89.202.148
EMAIL=nguyentranngocsuong94@gmail.com
OLD_DOMAIN=bbbv2.scalelitebbb.systems
NEW_DOMAIN=bbbv3.scalelitebbb.systems
# Define the file paths
FREESWITCH_VARS_FILE="/opt/freeswitch/etc/freeswitch/vars.xml"
NGINX_SIP_FILE="/usr/share/bigbluebutton/nginx/sip.nginx"
FREESWITCH_EXTERNAL_PROFILE_FILE="/opt/freeswitch/etc/freeswitch/sip_profiles/external.xml"
# //grep "wsUrl" /usr/share/meteor/bundle/programs/server/assets/app/config/settings.yml

sed -i -r -e "s/${OLD_EXTERNAL_IPV4}/${NEW_EXTERNAL_IPV4}/g" "$FREESWITCH_VARS_FILE"

# Update IP address in FreeSWITCH external profile
sed -i -r -e "s/${OLD_EXTERNAL_IPV4}/${NEW_EXTERNAL_IPV4}/g" "$FREESWITCH_EXTERNAL_PROFILE_FILE"

# Update IP address in NGINX sip.nginx
sed -i -r -e "s/${OLD_EXTERNAL_IPV4}/${NEW_EXTERNAL_IPV4}/g" "$NGINX_SIP_FILE"

# Additionally, update the protocol and port for NGINX configuration if needed
sed -i -r -e "s/http:\/\/${NEW_EXTERNAL_IPV4}:5066/https:\/\/${NEW_EXTERNAL_IPV4}:7443/g" "$NGINX_SIP_FILE"

sed -i -e "s/${OLD_DOMAIN}/${NEW_DOMAIN}/" /etc/nginx/sites-available/bigbluebutton
(cd /etc/nginx/sites-enabled && ln -s ../sites-available/bigbluebutton)
# link /etc/nginx/sites-enabled/bigbluebutton
service nginx reload
echo "Delete old certificate"
# certbot delete --cert-name $OLD_DOMAIN
sed -i -e "s#/var/www/html#/var/www/bigbluebutton-default#" /etc/letsencrypt/renewal/${NEW_DOMAIN}.conf
echo "Reconfigure BBB domain"
bbb-conf --setip $NEW_DOMAIN


systemctl restart systemd-journald


# /usr/share/bigbluebutton/nginx/sip.nginx (sip.nginx)
#                         proxy_pass: 152.42.227.127
#                           protocol: http

if [[ ! -f /etc/nginx/ssl/ffdhe2048.pem ]]; then
    cat >/etc/nginx/ssl/ffdhe2048.pem <<"HERE"
-----BEGIN DH PARAMETERS-----
MIIBCAKCAQEA//////////+t+FRYortKmq/cViAnPTzx2LnFg84tNpWp4TZBFGQz
+8yTnc4kmz75fS/jY2MMddj2gbICrsRhetPfHtXV/WVhJDP1H18GbtCFY2VVPe0a
87VXE15/V8k1mE8McODmi3fipona8+/och3xWKE2rec1MKzKT0g6eXq8CrGCsyT7
YdEIqUuyyOP7uWrat2DX9GgdT0Kj3jlN9K5W7edjcrsZCwenyO4KbXCeAvzhzffi
7MA0BM0oNC9hkXL+nOmFg/+OTxIy7vKBg8P+OxtMb61zO7X8vC7CIAXFjvGDfRaD
ssbzSibBsu/6iGtCOGEoXJf//////////wIBAg==
-----END DH PARAMETERS-----
HERE
fi
if [[ -f /etc/nginx/ssl/dhp-4096.pem ]]; then
    rm /etc/nginx/ssl/dhp-4096.pem
fi

xmlstarlet edit --inplace --update '//param[@name="wss-binding"]/@value' --value "$NEW_EXTERNAL_IPV4:7443" /opt/freeswitch/conf/sip_profiles/external.xml

yq e -i '.playback_protocol = "https"' /usr/local/bigbluebutton/core/scripts/bigbluebutton.yml
chmod 644 /usr/local/bigbluebutton/core/scripts/bigbluebutton.yml 

TARGET=/etc/bigbluebutton/bbb-webrtc-sfu/production.yml
touch $TARGET

yq e -i ".freeswitch.ip = \"$NEW_EXTERNAL_IPV4\"" $TARGET

# Use nginx as proxy for WSS -> WS (see https://github.com/bigbluebutton/bigbluebutton/issues/9667)
yq e -i ".freeswitch.sip_ip = \"$NEW_EXTERNAL_IPV4\"" $TARGET

chown bigbluebutton:bigbluebutton $TARGET
chmod 644 $TARGET

# Configure mediasoup IPs, reference: https://raw.githubusercontent.com/bigbluebutton/bbb-webrtc-sfu/v2.7.2/docs/mediasoup.md
# mediasoup IPs: WebRTC
yq e -i '.mediasoup.webrtc.listenIps[0].ip = "0.0.0.0"' $TARGET
yq e -i ".mediasoup.webrtc.listenIps[0].announcedIp = \"$NEW_EXTERNAL_IPV4\"" $TARGET

# mediasoup IPs: plain RTP (internal comms, FS <-> mediasoup)
yq e -i '.mediasoup.plainRtp.listenIp.ip = "0.0.0.0"' $TARGET
yq e -i ".mediasoup.plainRtp.listenIp.announcedIp = \"$NEW_EXTERNAL_IPV4\"" $TARGET

systemctl reload nginx

#  wget -qO- https://raw.githubusercontent.com/bigbluebutton/bbb-install/v3.0.x-release/bbb-install.sh | bash -s -- -w -v jammy-300  -d -s bbbv1.scalelitebbb.systems 

#  152.42.161.28
#  bbbv2.scalelitebbb.systems

#bbbv3.scalelitebbb.systems
#128.199.141.227

#bbbv1.scalelitebbb.systems
#128.199.100.143