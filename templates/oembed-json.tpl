{
  "version": "1.0",
  "type": "video",
  "provider_name": "MyTube",
  "provider_url": "{$urlprefix}",
  "title": "{$video.title|escape:'url'}",
  "author_name": "Jean-François Stenuit",
  "author_url": "{$urlprefix}",
  "thumbnail_url": "{$urlprefix}thumbs/{$video.path|escape:'url'}.jpg",
  "thumbnail_width": 1920,
  "thumbnail_height": 1080,
  "html": "<iframe src=\"{$urlprefix}embed?v={$video.id|escape:'url'}\" width=\"640\" height=\"360\" frameborder=\"0\" allowfullscreen></iframe>",
  "width": 640,
  "height": 360
}