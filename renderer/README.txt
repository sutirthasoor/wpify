WPIFY RENDERER (headless Chrome for Canva Websites)

Option A - any VPS / Render / Railway / Fly with Node 18+:
  cd renderer && npm install
  TOKEN=choose-a-secret PORT=3000 node server.js

Option B - Docker:
  docker build -t wpify-renderer renderer
  docker run -p 3000:3000 -e TOKEN=choose-a-secret wpify-renderer

Put it behind HTTPS (reverse proxy or the host's built-in TLS), then in WordPress:
WPIFY > Renderer > URL (e.g. https://render.example.com) + the same TOKEN > Save.

Health check: GET /health   Endpoint used by the plugin: POST /snapshot {"url": "..."}
Only canva.site / canva.com / canva.link URLs are accepted.
