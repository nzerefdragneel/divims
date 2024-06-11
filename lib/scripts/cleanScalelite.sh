#!/bin/bash

# File containing the exports
exports_file="/etc/exports"

# Temporary file to store the active IPs
active_ips_file="/tmp/active_ips.txt"

# Function to get active IP addresses
get_active_ips() {
  # Adjust the IP range according to your network
  nmap -sn 192.168.1.0/24 | grep 'Nmap scan report' | awk '{print $NF}' | tr -d '()' > $active_ips_file
}

# Function to remove unused IP addresses from /etc/exports
clean_exports() {
  # Backup the original exports file
  cp $exports_file "${exports_file}.bak"

  # Read each line of /etc/exports
  while IFS= read -r line; do
    # Extract IP address from the line
    if [[ $line =~ ([0-9]{1,3}\.){3}[0-9]{1,3} ]]; then
      ip="${BASH_REMATCH[0]}"
      # Check if the IP is in the active IPs list
      if ! grep -q $ip $active_ips_file; then
        # If IP is not active, remove the line
        echo "Removing unused IP: $ip"
        sed -i "/$ip/d" $exports_file
      fi
    fi
  done < $exports_file
}

# Get active IPs
get_active_ips

# Clean the /etc/exports file
clean_exports

# Clean up
rm $active_ips_file

echo "Cleanup complete. Backup of the original file is saved as ${exports_file}.bak"
