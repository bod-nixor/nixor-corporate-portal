import pino from "pino";
import { randomUUID } from "crypto";

export const logger = pino({
  base: { app: "nixor-endeavour-dashboard" },
  transport: process.env.NODE_ENV === "development" ? { target: "pino-pretty" } : undefined
});

export function withRequest(loggerInstance = logger, requestId?: string) {
  return loggerInstance.child({ requestId: requestId ?? randomUUID() });
}
