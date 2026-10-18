<?php
namespace App\Media;

/** ffmpeg/ffprobe helpers working on local media files (MP4 or HLS manifest) */
class VideoProbe
{
    /** Default thumbnail position when the duration is unknown or long enough */
    private const DEFAULT_THUMBNAIL_SECONDS = 140;

    /**
     * Resolution and duration of the first video stream.
     * @return array{width: int, height: int, duration: ?float}|null
     */
    public static function probe(string $videoFile): ?array
    {
        $command = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json %s 2>&1',
            escapeshellarg($videoFile)
        );
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        $data = json_decode(implode("\n", $output), true);

        $width = (int) ($data['streams'][0]['width'] ?? 0);
        $height = (int) ($data['streams'][0]['height'] ?? 0);
        if ($returnVar !== 0 || $width <= 0 || $height <= 0) {
            error_log("Could not probe video: $videoFile - " . implode("\\n", $output));
            return null;
        }
        $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        return ['width' => $width, 'height' => $height, 'duration' => $duration];
    }

    /** @return string|false resolution as "WIDTHxHEIGHT" */
    public static function resolution(string $videoFile): string|false
    {
        $info = self::probe($videoFile);
        return $info ? "{$info['width']}x{$info['height']}" : false;
    }

    /** A representative frame: 10 % into the video, at most 2 min 20 s */
    public static function defaultThumbnailTime(?float $duration): float
    {
        if ($duration === null || $duration <= 0) {
            return 5.0;
        }
        return round(max(1.0, min(self::DEFAULT_THUMBNAIL_SECONDS, $duration * 0.1)), 1);
    }

    public static function generateThumbnail(string $videoFile, string $thumbnailFile, float|string $timePosition = '00:02:20'): bool
    {
        $command = sprintf(
            'ffmpeg -v error -y -ss %s -i %s -frames:v 1 -update 1 %s 2>&1',
            escapeshellarg((string) $timePosition),
            escapeshellarg($videoFile),
            escapeshellarg($thumbnailFile)
        );
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !is_file($thumbnailFile)) {
            error_log("FFmpeg failed: " . implode("\n", $output));
            return false;
        }
        return true;
    }
}
