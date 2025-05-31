# MyTube

**MyTube** is a self-hosted, lightweight video player and publishing platform built in PHP. It supports modern playback features via [Video.js](https://videojs.com/), social media integration, responsive layouts, and modular code architecture.

This project is ideal for small creators, organizations, or anyone seeking an independent, embeddable video platform without relying on third-party services.

## Features

- **Responsive Video Playback** with [Video.js v8](https://github.com/videojs/video.js)
  - HLS (HTTP Live Streaming) support (H.264/AAC)
  - Bitrate selector (multi-quality support)
  - Chromecast and Android TV cast support
  - Optional chapter markers via WebVTT

- **Modern PHP Architecture**
  - Modular `src/` structure with Composer autoloading (PSR-4)
  - Views managed by [Smarty](https://www.smarty.net/)
  - Config handled via `.env` files with `.env.sample` for defaults

- **Social & Web Integration**
  - OpenGraph tags for link previews (Facebook, Twitter, LinkedIn)
  - oEmbed support (WordPress and others)
  - `watch` and `embed` views optimized for sharing and iframes

- **Structured Layout**
  - Clean separation of `src/`, `templates/`, `assets/`, `scripts/`
  - Responsive layout using CSS + known video resolution to avoid layout shifts

- **Privacy-Respecting by Design**
  - No user tracking or third-party scripts by default
  - Anonymous cookie support for like/comment/view features (planned)
  - Optional CAPTCHA integration for abuse protection (planned)

## Roadmap

- [ ] User authentication and login
- [ ] Admin UI for uploading, moderation, and analytics
- [ ] Modular JavaScript components with shared asset loading
- [ ] Migration from Smarty to Twig templates
- [ ] View/comment/like counters tied to anonymous ID cookies
- [ ] Subtitle and chapter management via `.vtt` files
- [ ] REST API endpoints for front-end interaction
- [ ] Optional sharing buttons using Web Share API

## Project Structure

```text
mytube/
├── src/              # PHP backend modules (controllers, models, middleware)
├── templates/        # Smarty templates (planned: migrate to Twig)
├── assets/           # Static CSS/images/fonts
├── scripts/          # JavaScript (Video.js plugins, feature modules)
├── public/           # Web root (watch.php, embed.php, assets)
├── .env              # Local config (e.g. DB credentials)
├── .env.sample       # Template for required environment variables
├── .htaccess         # Redirects everything to index.php
├── composer.json     # PHP dependencies (autoload, libraries)
├── index.php         # Application entrypoint
└── README.md
```

## Requirements

* PHP 8.x (with `mod_php`, no PHP-FPM required)
* MySQL or MariaDB
* Apache with `mod_rewrite`
* Composer (for dependency management)
* ffmpeg (external, for video conversion — optional)

## Quick Start

1. **Clone the repo**

   ```bash
   git clone https://github.com/jfstenuit/mytube.git
   cd mytube
   ```

2. **Install dependencies**

   ```bash
   composer install
   ```

3. **Configure environment**

   ```bash
   cp .env.sample .env
   # Edit .env with DB credentials and site URL
   ```

4. **Set up Apache vhost**
   Point the DocumentRoot to `public/` and enable `mod_rewrite`.

5. **Import sample data (optional)**

6. **Enjoy**
   Visit `http://localhost/watch?id=1` to test playback.

## License

This project is open-source and released under the **MIT License**.

## Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss the proposed changes.

## Contact & Feedback

Feature suggestions, bug reports, and contributions are welcome via GitHub Issues.

