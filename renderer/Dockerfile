FROM ghcr.io/puppeteer/puppeteer:latest
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
WORKDIR /home/pptruser/app
COPY --chown=pptruser:pptruser package.json server.js ./
RUN npm install --omit=dev
ENV PORT=3000
EXPOSE 3000
CMD ["node", "server.js"]
