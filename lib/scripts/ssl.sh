#!/usr/bin/env bash

# Retrieve new data
  TARGET=/etc/bigbluebutton/bbb-webrtc-sfu/production.yml
  touch $TARGET

  yq e -i ".freeswitch.ip = \"$IP\"" $TARGET

  if [[ $BIGBLUEBUTTON_RELEASE == 2.2.* ]] && [[ ${BIGBLUEBUTTON_RELEASE#*.*.} -lt 29 ]]; then
    if [ -n "$INTERNAL_IP" ]; then
      yq e -i ".freeswitch.sip_ip = \"$INTERNAL_IP\"" $TARGET
    else
      yq e -i ".freeswitch.sip_ip = \"$IP\"" $TARGET
    fi
  else
    # Use nginx as proxy for WSS -> WS (see https://github.com/bigbluebutton/bigbluebutton/issues/9667)
    yq e -i ".freeswitch.sip_ip = \"$IP\"" $TARGET
  fi
  chown bigbluebutton:bigbluebutton $TARGET
  chmod 644 $TARGET

  # Configure mediasoup IPs, reference: https://raw.githubusercontent.com/bigbluebutton/bbb-webrtc-sfu/v2.7.2/docs/mediasoup.md
  # mediasoup IPs: WebRTC
  yq e -i '.mediasoup.webrtc.listenIps[0].ip = "0.0.0.0"' $TARGET
  yq e -i ".mediasoup.webrtc.listenIps[0].announcedIp = \"$IP\"" $TARGET

  # mediasoup IPs: plain RTP (internal comms, FS <-> mediasoup)
  yq e -i '.mediasoup.plainRtp.listenIp.ip = "0.0.0.0"' $TARGET
  yq e -i ".mediasoup.plainRtp.listenIp.announcedIp = \"$IP\"" $TARGET

  systemctl reload nginx
