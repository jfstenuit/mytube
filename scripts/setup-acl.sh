#!/bin/bash

# Exit on error
set -e

echo "Applying POSIX ACLs for MyTube..."

# Ensure ACLs are supported
if ! mount | grep -q 'acl'; then
  echo "Error: ACL support not enabled on this filesystem!"
  exit
fi

TARGET_USER="www-data"
DIRS=(
  "incoming/"
  "logs/"
  "processed/"
  "thumbs/"
  "smarty/"
)

echo "Setting POSIX ACLs for user: $TARGET_USER"

for dir in "${DIRS[@]}"; do
  if [ -d "$dir" ]; then
    echo "Applying ACLs to: $dir"
    setfacl -Rm u:$TARGET_USER:rwX "$dir"
    setfacl -dRm u:$TARGET_USER:rwX "$dir"
  else
    echo "Warning: Directory not found: $dir"
  fi
done

echo "ACLs applied successfully."
