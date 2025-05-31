<?xml version="1.0" encoding="utf-8"?>
<oembed>
  <version>1.0</version>
  <type>video</type>
  <provider_name>MyTube</provider_name>
  <provider_url>https://vids.axu.be</provider_url>
  <title>{$video.title|escape:'htmlall'}</title>
  <author_name>Jean-François Stenuit</author_name>
  <author_url>https://vids.axu.be</author_url>
  <thumbnail_url>https://vids.axu.be/thumbs/{$video.path|escape:'url'}.jpg</thumbnail_url>
  <thumbnail_width>1920</thumbnail_width>
  <thumbnail_height>1080</thumbnail_height>
  <html><![CDATA[<iframe src="https://vids.axu.be/embed?v={$video.id|escape:'url'}" width="640" height="360" frameborder="0" allowfullscreen></iframe>]]></html>
  <width>640</width>
  <height>360</height>
</oembed>
