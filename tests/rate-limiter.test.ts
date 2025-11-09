import { describe, it, expect, beforeEach } from "vitest";
import { enforceRateLimit } from "../src/lib/rate-limiter";
import * as envModule from "../src/lib/env";

beforeEach(() => {
  (envModule as unknown as { env: { REDIS_URL?: string } }).env.REDIS_URL = undefined;
});

describe("rate limiter", () => {
  it("allows within quota and blocks when exceeded", async () => {
    const options = { namespace: "test", windowSeconds: 60, max: 2 };
    await enforceRateLimit(options, "entity-1");
    await enforceRateLimit(options, "entity-1");
    await expect(enforceRateLimit(options, "entity-1")).rejects.toThrowError();
  });
});
