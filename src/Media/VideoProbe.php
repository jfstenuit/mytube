<?php
namespace App\Media;

/**
 * ffmpeg/ffprobe helpers working on local media files.
 * To be moved to the ingestion pipeline (see docs/auth-plan.md, phase 6).
 */
class VideoProbe
{
    public static function generateThumbnail(string $videoFile, string $thumbnailFile, string $timePosition = '00:02:20'): bool
    {
        $command = sprintf(
            'ffmpeg -v error -ss %s -i %s -frames:v 1 -update 1 %s 2>&1',
            escapeshellarg($timePosition),
            escapeshellarg($videoFile),
            escapeshellarg($thumbnailFile)
        );
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            error_log("FFmpeg failed: " . implode("\n", $output));
            return false;
        }
        return true;
    }

    /** @return string|false resolution as "WIDTHxHEIGHT" */
    public static function resolution(string $videoFile): string|false
    {
        $command = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height ' .
            '-of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($videoFile)
        );
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar === 0 && count($output) >= 2) {
            $width = (int) trim($output[0]);
            $height = (int) trim($output[1]);
            if ($width > 0 && $height > 0) {
                return "{$width}x{$height}";
            }
        } else {
            error_log("Could not extract resolution from video: $videoFile - " . implode("\\n", $output));
        }
        return false;
    }
}
