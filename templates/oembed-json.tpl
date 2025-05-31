{
  "version": "1.0",
  "type": "video",
  "provider_name": "MyTube",
  "provider_url": "https://vids.axu.be",
  "title": "{$video.title|escape:'url'}",
  "author_name": "Jean-François Stenuit",
  "author_url": "https://vids.axu.be",
  "thumbnail_url": "https://vids.axu.be/thumbs/{$video.path|escape:'url'}.jpg",
  "thumbnail_width": 1920,
  "thumbnail_height": 1080,
  "html": "<iframe src=\"https://vids.axu.be/embed?v={$video.id|escape:'url'}\" width=\"640\" height=\"360\" frameborder=\"0\" allowfullscreen></iframe>",
  "width": 640,
  "height": 360
}