// server.js - Next.js + Passenger bootstrap for cPanel
const { createServer } = require('http');
const next = require('next');

// dev=false for production
const app = next({ dev: false });
const handle = app.getRequestHandler();

app.prepare().then(() => {
  // With Passenger you DON'T bind to a fixed port.
  // Just start an HTTP server without specifying a port.
  createServer((req, res) => {
    handle(req, res);
  }).listen();
});

module.exports = app; // important for Passenger
