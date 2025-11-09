import { createSecureHeaders } from "./src/lib/security-headers";

const nextConfig = {
  reactStrictMode: true,
  experimental: {
    typedRoutes: true,
    serverActions: true
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
