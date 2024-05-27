#!/usr/bin/env bash

##BEGIN-INSERT
NEW_DOMAIN=
NEW_IP=
SECRET=
ENABLE_IN_SCALELITE=
##END-INSERT


[ -z ${NEW_DOMAIN+x} ] && echo "New domain is not set" && exit 1;

echo "Add new BBB VM to pool"
COMMAND=( docker exec scalelite-api ./bin/rake servers:add[https://${NEW_DOMAIN}/bigbluebutton/api,${SECRET},1] )
RESULT=$("${COMMAND[@]}")
echo "$RESULT"

if [ $ENABLE_IN_SCALELITE = "true" ]; then
    echo "Enable new VM"
    id=$(echo "$RESULT" | grep "^id" | awk -F ":" '{print $2}' | tr -d ' ')
    COMMAND=( docker exec scalelite-api ./bin/rake servers:enable[$id] )
    RESULT=$("${COMMAND[@]}")
    echo "$RESULT"
fi

# Add new VM to NFS 
echo "/mnt/scalelite-recordings $NEW_IP(rw,sync,no_root_squash)" >> /etc/exports
exportfs -r
sudo systemctl start nfs-kernel-server.service 
#COMMAND=( docker exec scalelite-api ./bin/rake servers )
#RESULT=$("${COMMAND[@]}")
#echo "$RESULT"
