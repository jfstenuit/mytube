//<![CDATA[
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('video-player');
    const media = window.atob(el.dataset.src);
    const thumb = window.atob(el.dataset.thumb);

    const player = videojs(el, {
        controls: true,
        autoplay: false,
        preload: 'auto'
    });

    player.poster(thumb);
    player.src({
        src: media
    });

    player.hlsQualitySelector({
        displayCurrentQuality: true
    });

    player.chromecast();

    document.getElementById('share-btn').addEventListener('click', async () => {
        if (navigator.share) {
            await navigator.share({
                title: document.title,
                text: "Regardez cette vidéo sur MyTube",
                url: window.location.href
            });
        } else {
            // Fallback: copy to clipboard
            navigator.clipboard.writeText(window.location.href);
            alert("Lien copié dans le presse-papiers !");
        }
    });

    document.getElementById('embed-btn').addEventListener('click', () => {
        document.getElementById('embed-modal').removeAttribute('hidden');
    });

    document.querySelectorAll('[data-close]').forEach(el =>
        el.addEventListener('click', () => document.getElementById('embed-modal').setAttribute('hidden', ''))
    );

    document.getElementById('copy-embed').addEventListener('click', () => {
        document.getElementById('embed-code').select();
        document.execCommand('copy');
        alert('Code d’intégration copié !');
    });

});
//]]>  