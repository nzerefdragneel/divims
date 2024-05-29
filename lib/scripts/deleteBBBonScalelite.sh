#!/usr/bin/env bash

##BEGIN-INSERT

BBB_SERVER_ID=
BBB_SERVER_IP=
##END-INSERT
docker exec -i scalelite-api bundle exec rake servers:remove[$BBB_SERVER_ID]


