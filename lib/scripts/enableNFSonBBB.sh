#!/usr/bin/env bash

##BEGIN-INSERT

SCALELITE_SERVER_IP=

##END-INSERT
mount $SCALELITE_SERVER_IP:/mnt/scalelite-recordings /mnt/scalelite-recordings
df -h
# Add new VM to NFS 
echo "$SCALELITE_SERVER_IP:/mnt/scalelite-recordings /mnt/scalelite-recordings nfs defaults 0 0" >> /etc/fstab


