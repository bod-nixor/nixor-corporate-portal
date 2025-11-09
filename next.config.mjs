import { createSecureHeaders } from "./src/lib/security-headers/index.js";

const nextConfig = {
  reactStrictMode: true,
  experimental: {
    typedRoutes: true
  },
  headers: async () => {
    return [
      {
        source: "/(.*)",
        headers: createSecureHeaders()
      }
    ];
  }
};

export default nextConfig;
