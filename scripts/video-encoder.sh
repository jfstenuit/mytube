#!/bin/bash

# Call like this for full background operation :
#    setsid nohup /path/to/video_encoder.sh >/dev/null 2>&1 < /dev/null &

set -euo pipefail

# === Config ===
LOCK_FILE="/tmp/video_encoder.lock"
INCOMING_DIR="$(dirname "$0")/../incoming"
PROCESSED_DIR="$(dirname "$0")/../processed"
HLS_OUTPUT_DIR="$(dirname "$0")/../media"
LOG_DIR="$(dirname "$0")/../logs"
LOG_FILE="$LOG_DIR/encoder_$(date +'%Y%m%d').log"

# === Setup ===
mkdir -p "$PROCESSED_DIR" "$HLS_OUTPUT_DIR" "$LOG_DIR"
exec >>"$LOG_FILE" 2>&1

echo "[$(date)] === Encoder started ==="

# === Singleton check ===
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "[$(date)] Another instance is running. Exiting."
    exit 1
fi

# === Encoder preset detection ===
if ffmpeg -f lavfi -i nullsrc -t 1 -c:v h264_nvenc -f null -loglevel error -y /dev/null 2>/dev/null; then
    ENCODER="h264_nvenc"
    PRESET="p4"
    echo "[$(date)] Using NVIDIA hardware encoding"
else
    ENCODER="libx264"
    PRESET="veryslow"
    echo "[$(date)] Falling back to libx264 software encoding"
fi

# === Main loop ===
while true; do
    FILE=$(find "$INCOMING_DIR" -type f -printf "%T@ %p\n" | sort -n | head -n 1 | cut -d' ' -f2-)
    if [ -z "$FILE" ]; then
        echo "[$(date)] No more files to process. Exiting."
        break
    fi

    MD5=$(md5sum "$FILE" | awk '{print $1}')
    BASENAME=$(basename "$FILE")
    EXT="${BASENAME##*.}"
    MEDIA_DIR="$HLS_OUTPUT_DIR/$MD5"

    if [ -d "$MEDIA_DIR" ]; then
        echo "[$(date)] Skipping $BASENAME – already processed as $MD5"
        mv "$FILE" "$PROCESSED_DIR/"
        continue
    fi

    mkdir -p "$MEDIA_DIR"

    echo "[$(date)] Starting processing: $BASENAME"

    if nice -n 15 ionice -c2 -n7 ffmpeg -threads 1 -hide_banner -loglevel warning -y \
        -i "$FILE" \
        -filter_complex "[0:v]split=5[v1out][v2][v3][v4][v5];[v2]scale=1280x720[v2out];[v3]scale=960x540[v3out];[v4]scale=640x360[v4out];[v5]scale=416x234[v5out]" \
        -map "[v1out]" -c:v:0 $ENCODER -b:v:0 7.2M -maxrate:v:0 7.9M -bufsize:v:0 10.8M -pix_fmt yuv420p -preset $PRESET -g 50 -sc_threshold 0 -keyint_min 50 \
        -map "[v2out]" -c:v:1 $ENCODER -b:v:1 4.8M -maxrate:v:1 5.3M -bufsize:v:1 7.2M -pix_fmt yuv420p -preset $PRESET -g 50 -sc_threshold 0 -keyint_min 50 \
        -map "[v3out]" -c:v:2 $ENCODER -b:v:2 2.4M -maxrate:v:2 2.6M -bufsize:v:2 3.6M -pix_fmt yuv420p -preset $PRESET -g 50 -sc_threshold 0 -keyint_min 50 \
        -map "[v4out]" -c:v:3 $ENCODER -b:v:3 1.2M -maxrate:v:3 1.3M -bufsize:v:3 1.8M -pix_fmt yuv420p -preset $PRESET -g 50 -sc_threshold 0 -keyint_min 50 \
        -map "[v5out]" -c:v:4 $ENCODER -b:v:4 0.8M -maxrate:v:4 0.88M -bufsize:v:4 1.2M -pix_fmt yuv420p -preset $PRESET -g 50 -sc_threshold 0 -keyint_min 50 \
        -map 0:a -c:a aac -b:a 160k -ac 2 \
        -map_metadata -1 \
        -hls_flags independent_segments \
        -hls_segment_type mpegts \
        -var_stream_map "a:0,agroup:audio,name:audio v:0,agroup:audio,name:1080p v:1,agroup:audio,name:720p v:2,agroup:audio,name:480p v:3,agroup:audio,name:360p v:4,agroup:audio,name:240p" \
        -master_pl_name "$MEDIA_DIR/master.m3u8" \
        -f hls -hls_time 4 -hls_playlist_type vod \
        -hls_segment_filename "$MEDIA_DIR/%v/segment_%d.ts" "$MEDIA_DIR/%v.m3u8"; then

        mv "$FILE" "$PROCESSED_DIR/"
        echo "[$(date)] Successfully processed and moved: $BASENAME"
    else
        echo "[$(date)] ERROR: Failed to process $BASENAME"
    fi
done

echo "[$(date)] === Encoder finished ==="
