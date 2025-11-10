// next.config.mjs
import { createSecureHeaders } from "./src/lib/security-headers/index.js"; 
// ^ ensure this path resolves to a .js/.mjs file, not .ts

const nextConfig = {
  reactStrictMode: true,
  experimental: {
    typedRoutes: true,
  },
  async headers() {
    return [
      {
        source: "/(.*)",
        headers: createSecureHeaders(),
      },
    ];
  },
  webpack(config) {
    config.ignoreWarnings = [...(config.ignoreWarnings ?? []), /node_modules[\\/]mjml/];
    return config;
  },
};

export default nextConfig;