<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">

    {include file="partials/videojs-includes.tpl"}

    <title>{$video.title|escape:'html'}</title>

    <script type="text/javascript">
//<![CDATA[
// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('video-player');
    if (!el) return;

    // Decode the base64-encoded media and thumb variables
    var media = window.atob("{$video.encoded_video}");
    var thumb = window.atob("{$video.encoded_thumbnail}");

    // Get the Video.js player instance
    const player = videojs(el, {
        controls: true,
        autoplay: false,
        preload: 'auto',
        // Below, we don't relay on video.js to have proper aspect ratio
        // fluid: true
    });

    player.poster(thumb);
    player.src({ src: media });
});
//]]>
    </script>
</head>
<body>
    <div class="video-container">
        <div class="video-wrapper" style="position: relative; width: 100%; padding-top: {$video.aspect_ratio*100}%;">
            <video id="video-player" class="video-js" controls preload="auto"
            style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></video>
        </div>
    </div>
</body>
</html>