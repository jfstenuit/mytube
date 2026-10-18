//<![CDATA[
// View counting: reports one view per page load once the video has actually played for
// data-view-threshold seconds (seeks and pauses don't count). The server checks the signed
// token, the real elapsed time and duplicates (see src/Stats/ViewCounter.php).
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('video-player');
    if (!el || !el.dataset.viewToken || !window.videojs || !navigator.sendBeacon) return;

    const threshold = parseFloat(el.dataset.viewThreshold) || 30;
    const videoId = el.dataset.videoId;
    const token = el.dataset.viewToken;

    function track(player) {
        let played = 0;
        let last = null;
        let sent = false;

        player.on('seeking', () => { last = null; });
        player.on('timeupdate', () => {
            const now = player.currentTime();
            if (last !== null && !player.paused() && !player.seeking()) {
                const delta = now - last;
                // Normal playback advances in small steps; larger jumps are seeks
                if (delta > 0 && delta < 2) played += delta;
            }
            last = now;
            if (!sent && played >= threshold) {
                sent = true;
                const data = new FormData();
                data.append('v', videoId);
                data.append('token', token);
                navigator.sendBeacon('view', data);
            }
        });
    }

    // The player is created by the page's own script (mytube.js or the embed page)
    const player = videojs.getPlayer('video-player');
    if (player) {
        track(player);
    } else {
        videojs.hook('setup', (p) => { if (p.id() === 'video-player') track(p); });
    }
});
//]]>
