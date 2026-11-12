# WP Google Fonts Local

Self-host the Google Fonts your theme uses. The plugin intercepts any `fonts.googleapis.com` stylesheet, downloads the CSS and its font files into `wp-content/uploads/google-fonts/`, and serves them from your own domain — one less third-party request, faster first paint, and no visitor data sent to Google.

## How it works

1. On the front end, the `style_loader_src` filter catches any enqueued Google Fonts stylesheet.
2. The CSS is fetched with a modern browser user-agent so Google returns compact `woff2` files.
3. Every `url(https://fonts.gstatic.com/…)` in the CSS is downloaded and rewritten to a local URL.
4. The rewritten CSS is saved once per unique Google Fonts URL and reused on every page load — nothing is fetched on repeat visits.

## Features

- **Zero configuration** — works with any theme that enqueues Google Fonts the standard way.
- **Weekly refresh** — a WP-Cron event clears the cache weekly so font updates are picked up.
- **Tools page** — **Tools → Google Fonts Local** lists every localized stylesheet with its download date, plus manual refresh and full purge buttons.
- **Privacy-safe** — any font URL that could not be downloaded is stripped from the CSS, so no request ever leaks back to Google.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Visit the front end of your site once — fonts are localized on first load.

## Uninstall

Deleting the plugin removes the cache option, the weekly cron event and all downloaded font files.
