import { createEnv } from "@t3-oss/env-nextjs";
import { z } from "zod";

type VisibilityMode = "RESTRICTED" | "OPEN";

/* eslint-disable no-unused-vars */
declare global {
  namespace NodeJS {
    interface ProcessEnv {
      VISIBILITY_MODE: VisibilityMode;
    }
  }
}
/* eslint-enable no-unused-vars */

const runtimeEnv = {
  ...process.env,
  DATABASE_URL: process.env.DATABASE_URL ?? "mysql://user:pass@localhost:3306/nixor",
  DIRECT_URL: process.env.DIRECT_URL,
  GOOGLE_CLIENT_ID: process.env.GOOGLE_CLIENT_ID ?? "placeholder",
  GOOGLE_CLIENT_SECRET: process.env.GOOGLE_CLIENT_SECRET ?? "placeholder",
  NEXTAUTH_SECRET: process.env.NEXTAUTH_SECRET ?? "dev-secret",
  NEXTAUTH_URL: process.env.NEXTAUTH_URL ?? "http://localhost:3000",
  SMTP_HOST: process.env.SMTP_HOST ?? "localhost",
  SMTP_PORT: process.env.SMTP_PORT ?? "1025",
  SMTP_USER: process.env.SMTP_USER ?? "user",
  SMTP_PASS: process.env.SMTP_PASS ?? "pass",
  SMTP_FROM: process.env.SMTP_FROM ?? "noreply@nixorcollege.edu.pk",
  DOMAIN_ALLOWLIST: process.env.DOMAIN_ALLOWLIST ?? "nixorcollege.edu.pk",
  VISIBILITY_MODE: process.env.VISIBILITY_MODE ?? "RESTRICTED",
  RATE_LIMIT_REDIS_NAMESPACE: process.env.RATE_LIMIT_REDIS_NAMESPACE ?? "nixor:endeavour:quota",
  REDIS_URL: process.env.REDIS_URL
};

export const env = createEnv({
  server: {
    DATABASE_URL: z.string().url(),
    DIRECT_URL: z.string().url().optional(),
    GOOGLE_CLIENT_ID: z.string().min(1),
    GOOGLE_CLIENT_SECRET: z.string().min(1),
    NEXTAUTH_SECRET: z.string().min(1),
    NEXTAUTH_URL: z.string().url().optional(),
    SMTP_HOST: z.string().min(1),
    SMTP_PORT: z.coerce.number().int(),
    SMTP_USER: z.string().min(1),
    SMTP_PASS: z.string().min(1),
    SMTP_FROM: z.string().email(),
    REDIS_URL: z.string().url().optional(),
    DOMAIN_ALLOWLIST: z.string().min(1),
    VISIBILITY_MODE: z.enum(["RESTRICTED", "OPEN"]).default("RESTRICTED"),
    RATE_LIMIT_REDIS_NAMESPACE: z.string().min(1).default("nixor:endeavour:quota")
  },
  client: {},
  runtimeEnv,
  skipValidation: !!process.env.CI || process.env.NODE_ENV === "test"
});
