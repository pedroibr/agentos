- Upload zip in WP Admin → Plugins → Add New → Upload
- Or copy folder to wp-content/plugins/

Before publishing an extracted plugin folder, normalize its directories to `0755`
and its public source/assets to `0644`. Private staging folders outside the web root
may remain `0700`; do not carry that permission onto the published plugin root or
its asset directories. A restrictive shell umask can otherwise make CSS and JS
return HTTP 403 while PHP pages still render.

Release ZIPs must include explicit `0755` directory entries. After publishing,
verify HTTP 200 and the correct content type for `assets/agentos-embed.css`,
`assets/agentos-embed.js`, `assets/agentos-realtime.js`, and `assets/agentos-tools.js`.
Reload the actual frontend and visually confirm its layout; PHP lint, file hashes,
and a successful HTML response do not establish that public assets are accessible.
