<?php
namespace App\Media;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

/**
 * Media files under media/. A video's "path" (videos.path) is either the base
 * name of NAME.mp4, or the name of an HLS directory.
 */
class MediaLibrary
{
    /** Supported layouts for a path, in lookup order: relative manifest pattern => type */
    private const LAYOUTS = [
        '%s.mp4' => 'mp4',
        '%s/manifest/video.m3u8' => 'hls',
        '%s/master.m3u8' => 'hls',
    ];

    /** Video files that need encoding first (scripts/video-encoder.sh) */
    private const UNSUPPORTED = ['mkv', 'mov', 'avi', 'webm', 'm4v', 'wmv', 'mpg', 'mpeg', 'ts', 'flv', '3gp'];

    private string $root;

    public function __construct(string $mediaRoot, private string $urlPrefix = 'media/')
    {
        $this->root = rtrim(realpath($mediaRoot) ?: $mediaRoot, '/');
    }

    /**
     * Finds the manifest of a registered path.
     * @return array{file: string, url: string, relative: string, type: string}|null
     */
    public function locate(string $path): ?array
    {
        if (!self::isValidPath($path)) {
            return null;
        }
        foreach (self::LAYOUTS as $pattern => $type) {
            $relative = sprintf($pattern, $path);
            $resolved = realpath($this->root . '/' . $relative);
            if ($resolved && str_starts_with($resolved, $this->root . '/')) {
                return [
                    'file' => $resolved,
                    'url' => $this->urlPrefix . sprintf($pattern, rawurlencode($path)),
                    'relative' => $relative,
                    'type' => $type,
                ];
            }
        }
        return null;
    }

    /**
     * Media entries found directly under media/ that aren't in $registeredPaths.
     *
     * @param string[] $registeredPaths
     * @return array<int, array{path: string, name: string, type: string, size: ?int, mtime: int, problem: ?string}>
     */
    public function unregistered(array $registeredPaths): array
    {
        $registered = array_flip($registeredPaths);
        return array_values(array_filter($this->scan(), fn ($e) => !isset($registered[$e['path']])));
    }

    /** @return array{path: string, name: string, type: string, size: ?int, mtime: int, problem: ?string}|null */
    public function findUnregistered(string $path, array $registeredPaths): ?array
    {
        foreach ($this->unregistered($registeredPaths) as $entry) {
            if ($entry['path'] === $path) {
                return $entry;
            }
        }
        return null;
    }

    public static function isValidPath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '.') && !preg_match('#[/\\\\\x00-\x1F]#', $path)
            && strlen($path) <= 255;
    }

    private function scan(): array
    {
        $entries = [];
        foreach (scandir($this->root) ?: [] as $name) {
            if (str_starts_with($name, '.')) {
                continue;
            }
            $full = $this->root . '/' . $name;
            if (is_dir($full)) {
                $entry = $this->scanDirectory($name, $full);
            } elseif (is_file($full)) {
                $entry = $this->scanFile($name, $full);
            } else {
                $entry = null;
            }
            if ($entry !== null && self::isValidPath($entry['path'])) {
                $entries[] = $entry;
            }
        }
        usort($entries, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $entries;
    }

    private function scanFile(string $name, string $full): ?array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'mp4' && str_ends_with($name, '.mp4')) {
            return $this->entry(substr($name, 0, -4), $name, 'mp4', filesize($full), filemtime($full),
                is_readable($full) ? null : 'unreadable');
        }
        if ($extension === 'mp4' || in_array($extension, self::UNSUPPORTED, true)) {
            // .MP4 in upper case is not found by the player, which looks for NAME.mp4
            return $this->entry($name, $name, $extension, filesize($full), filemtime($full),
                $extension === 'mp4' ? 'extension_case' : 'unsupported');
        }
        return null;
    }

    private function scanDirectory(string $name, string $full): ?array
    {
        $manifest = null;
        foreach (['manifest/video.m3u8', 'master.m3u8'] as $candidate) {
            if (file_exists("$full/$candidate")) {
                $manifest = "$full/$candidate";
                break;
            }
        }
        if ($manifest === null) {
            // Not readable at all, or a directory that isn't an HLS rendition
            return is_readable($full) && is_executable($full) ? null
                : $this->entry($name, $name, 'hls', null, filemtime($full), 'unreadable');
        }

        $problem = null;
        $size = 0;
        $playlists = [];
        try {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $file) {
                if (!$file->isReadable() || ($file->isDir() && !$file->isExecutable())) {
                    $problem = 'unreadable';
                    break;
                }
                if ($file->isFile()) {
                    $size += $file->getSize();
                    if ($file->getExtension() === 'm3u8') {
                        $playlists[] = $file->getPathname();
                    }
                }
            }
        } catch (UnexpectedValueException) {
            $problem = 'unreadable';
        }

        // Variant playlists of a finished VOD encoding end with #EXT-X-ENDLIST
        if ($problem === null) {
            foreach ($playlists as $playlist) {
                $content = file_get_contents($playlist);
                if (str_contains($content, '#EXTINF') && !str_contains($content, '#EXT-X-ENDLIST')) {
                    $problem = 'incomplete';
                    break;
                }
            }
        }

        return $this->entry($name, $name, 'hls', $problem === 'unreadable' ? null : $size, filemtime($full), $problem);
    }

    private function entry(string $path, string $name, string $type, ?int $size, int $mtime, ?string $problem): array
    {
        return compact('path', 'name', 'type', 'size', 'mtime', 'problem');
    }
}
