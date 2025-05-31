#!/bin/bash

set -euo pipefail

# Usage check
if [ $# -lt 2 ]; then
  echo "Usage: $0 <hls_directory> <video_title>"
  exit 1
fi

HLS_DIR="$1"
TITLE="$2"
MEDIA_DIR="$(dirname "$0")/../media/$HLS_DIR"

if [ ! -d "$MEDIA_DIR" ]; then
  echo "Error: HLS directory does not exist: $MEDIA_DIR"
  exit 1
fi

# Load .env from project root
ENV_FILE="$(dirname "$0")/../.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Missing .env file"
  exit 1
fi

# Export env vars
set -a
source "$ENV_FILE"
set +a

# Generate a 12-character base64url ID
generate_id() {
  head -c9 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=[:space:]' | cut -c1-12
}

VIDEO_ID=$(generate_id)
COLLECTION_ID=0
DESCRIPTION=""

# Run SQL
SQL="INSERT INTO videos (id, collection_id, path, title, description)
VALUES ('$VIDEO_ID', $COLLECTION_ID, '$HLS_DIR', '$TITLE', '$DESCRIPTION');"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$SQL"

echo "✔ Video added with ID: $VIDEO_ID"
