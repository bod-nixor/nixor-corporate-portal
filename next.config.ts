import type { NextConfig } from "next";
import { createSecureHeaders } from "./src/lib/security-headers";

const nextConfig: NextConfig = {
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
  },
  webpack: (config) => {
    config.ignoreWarnings = [...(config.ignoreWarnings ?? []), /node_modules[\\/]mjml/];
    return config;
  }
};

export default nextConfig;
