import { env } from "./env";

export function isEmailAllowed(email: string) {
  const allowlist = env.DOMAIN_ALLOWLIST.split(",").map((item) => item.trim().toLowerCase());
  return allowlist.some((domain) => email.toLowerCase().endsWith(`@${domain}`));
}
