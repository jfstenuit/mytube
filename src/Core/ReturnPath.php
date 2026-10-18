<?php
namespace App\Core;

/** Validation of post-login "return" targets (no open redirect) */
class ReturnPath
{
    /**
     * Only absolute paths under the application base are accepted: no scheme, host,
     * protocol-relative URL, backslash, control character or "."/".." segment.
     * Anything else falls back to the base path (home page).
     */
    public static function sanitize(string $basePath, ?string $target): string
    {
        if ($target === null) {
            return $basePath;
        }
        $path = rawurldecode((string) parse_url($target, PHP_URL_PATH));
        if (!str_starts_with($target, $basePath)
            || str_starts_with($target, '//')
            || preg_match('/[\\\\\x00-\x1F\x7F]/', $target)
            || preg_match('#(^|/)\.\.?(/|$)#', $path)
            || parse_url($target, PHP_URL_HOST) !== null
            || parse_url($target, PHP_URL_SCHEME) !== null) {
            return $basePath;
        }
        return $target;
    }
}
