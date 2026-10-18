//<![CDATA[
document.addEventListener('DOMContentLoaded', function () {
    // "Autres collections" menu (<details>): close on a click or tap elsewhere, or on Escape
    const switcher = document.querySelector('.collection-switcher');
    if (switcher) {
        document.addEventListener('click', (e) => {
            if (switcher.open && !switcher.contains(e.target)) switcher.open = false;
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && switcher.open) {
                switcher.open = false;
                switcher.querySelector('summary').focus();
            }
        });
    }

    // Like button: toggled without reloading; falls back to a normal form post on error
    const likeForm = document.querySelector('.like-form');
    if (likeForm) {
        likeForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const button = likeForm.querySelector('.like-btn');
            button.disabled = true;
            try {
                const response = await fetch(likeForm.action, {
                    method: 'POST',
                    body: new FormData(likeForm),
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error(response.status);
                const result = await response.json();
                button.setAttribute('aria-pressed', result.liked ? 'true' : 'false');
                button.title = result.liked ? 'Je n’aime plus' : 'J’aime';
                button.querySelector('.material-icons').textContent = result.liked ? 'thumb_up' : 'thumb_up_off_alt';
                button.querySelector('.like-count').textContent = result.count.toLocaleString('fr-BE');
            } catch (err) {
                likeForm.submit();
            } finally {
                button.disabled = false;
            }
        });
    }

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

    // Canonical watch URL (the home page shows a random video under the root URL)
    const shareUrl = document.querySelector('meta[property="og:url"]')?.content || window.location.href;

    document.getElementById('share-btn').addEventListener('click', async () => {
        if (navigator.share) {
            await navigator.share({
                title: document.title,
                text: "Regardez cette vidéo sur MyTube",
                url: shareUrl
            });
        } else {
            // Fallback: copy to clipboard
            navigator.clipboard.writeText(shareUrl);
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