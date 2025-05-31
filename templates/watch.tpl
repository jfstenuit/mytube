<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow,noarchive">

  {foreach from=$og key=key item=value}
  <meta property="og:{$key}" content="{$value|escape:'htmlall'}" />
  {/foreach}

  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <!-- Video.js CSS -->
  <link href="https://vjs.zencdn.net/8.20.0/video-js.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/@silvermine/videojs-chromecast@1.5.0/dist/silvermine-videojs-chromecast.css" rel="stylesheet">

  <link href="assets/css/style.css" rel="stylesheet">
  <link href="{$og.image}" rel="image_src">
  <link rel="alternate" type="application/json+oembed" href="https://vids.axu.be/oembed?v={$video.id|escape:'url'}" title="oEmbed JSON">
  <link rel="alternate" type="text/xml+oembed" href="https://vids.axu.be/oembed?v={$video.id|escape:'url'}&format=xml" title="oEmbed XML">

  <!-- Video.js JavaScript -->
  <script src="https://vjs.zencdn.net/8.20.0/video.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@videojs/http-streaming@3.1.0/dist/videojs-http-streaming.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/videojs-hls-quality-selector/dist/videojs-hls-quality-selector.min.js"></script>
  <script type="text/javascript" src="https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1"></script>
  <script src="https://cdn.jsdelivr.net/npm/@silvermine/videojs-chromecast@1.5.0/dist/silvermine-videojs-chromecast.min.js"></script>
  
  <script defer src="assets/js/mytube.js"></script>

  <title>{$video.title|escape:'html'}</title>
</head>
<body>

  <header>
    <img src="assets/img/logo.png" alt="Logo">
    <h1>MyTube</h1>
  </header>

  <main>
  <section class="video-section">
    <div class="video-container">
      <div class="video-wrapper" style="position: relative; width: 100%; padding-top: {$video.aspect_ratio*100}%;">
        <video id="video-player" class="video-js" controls preload="auto"
          style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"
          data-src="{$video.encoded_video}"
          data-thumb="{$video.encoded_thumbnail}">
        </video>
      </div>
    </div>
    <div class="video-meta">
      <h2>{$video.title|escape:'html'}</h2>
      <div class="video-actions">
        {* <span class="author">{$video.author|escape:'html'}</span> *}
        <div class="actions">
          <button id="share-btn" class="material-icons" title="Share">share</button>
          <button id="embed-btn" class="material-icons" title="Embed">code</button>
        </div>
      </div>
    </div>

    <div class="video-description">
      {$video.description}
    </div>

  </section>

  <aside class="sidebar">
    <div class="section">
      <h3>Dans la même collection</h3>
      <div class="related-videos">
{foreach $seealso as $altvid}
        <a href="watch?v={$altvid.id|escape:'url'}" class="video-card">
          <img src="thumbs/{$altvid.id|escape:'url'}.jpg" alt="">
          <span>{$altvid.title|escape:'html'}</span>
        </a>
{/foreach}
      </div>
    </div>
    <div class="section">
      <h3>Soutenez mon travail</h3>

      <script src="https://cdnjs.buymeacoffee.com/1.0.0/button.prod.min.js"
        data-name="bmc-button" data-slug="jfstenuit" data-color="#FFDD00"
        data-emoji="" data-font="Cookie" data-text="Buy me a coffee"
        data-outline-color="#000000" data-font-color="#000000"
        data-coffee-color="#ffffff"></script>
    </div>
  </aside>
  </main>

  <footer>
    &copy; Jean-François Stenuit 2016-2025
  </footer>

<div id="embed-modal" class="modal" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal-content" role="dialog" aria-modal="true">
    <h3>Intégrer cette vidéo</h3>
    <textarea id="embed-code" readonly>
&lt;div style="position: relative; width: 100%; aspect-ratio: 16 / 9; overflow: hidden; background: #000;"&gt;
    &lt;iframe
        src="https://vids.axu.be/embed?v={$video.id}"
        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;"
        frameborder="0"
        allowfullscreen&gt;
    &lt;/iframe&gt;
&lt;/div&gt;
    </textarea>
    <button id="copy-embed">Copier</button>
    <button data-close>Fermer</button>
  </div>
</div>

</body>
</html>
