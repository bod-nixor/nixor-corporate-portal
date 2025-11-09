import { createClient } from "redis";
import { env } from "./env";
import { RateLimitError } from "./errors";
import { logger } from "./logger";

interface RateLimiterOptions {
  namespace: string;
  windowSeconds: number;
  max: number;
}

export interface RateLimiterClient {
  incrementAndGet(key: string, windowSeconds: number): Promise<number>;
}

class RedisRateLimiterClient implements RateLimiterClient {
  private client = createClient({ url: env.REDIS_URL });
  private ready = false;

  private async ensureConnected() {
    if (!this.ready) {
      await this.client.connect();
      this.ready = true;
    }
  }

  async incrementAndGet(key: string, windowSeconds: number) {
    await this.ensureConnected();
    const multi = this.client.multi();
    multi.incr(key);
    multi.expire(key, windowSeconds, { nx: true });
    const [, count] = (await multi.exec()) as [number, number];
    return count;
  }
}

class MemoryRateLimiterClient implements RateLimiterClient {
  private store = new Map<string, { count: number; expiresAt: number }>();

  async incrementAndGet(key: string, windowSeconds: number) {
    const now = Date.now();
    const existing = this.store.get(key);
    if (!existing || existing.expiresAt < now) {
      this.store.set(key, { count: 1, expiresAt: now + windowSeconds * 1000 });
      return 1;
    }
    existing.count += 1;
    return existing.count;
  }
}

const client: RateLimiterClient = env.REDIS_URL
  ? new RedisRateLimiterClient()
  : new MemoryRateLimiterClient();

export async function enforceRateLimit(options: RateLimiterOptions, key: string) {
  const { namespace, windowSeconds, max } = options;
  const namespacedKey = `${namespace}:${key}`;
  const count = await client.incrementAndGet(namespacedKey, windowSeconds);
  logger.info({ namespacedKey, count }, "rate.limit");
  if (count > max) {
    throw new RateLimitError("Entity publish quota exceeded. Try again later.");
  }
  return count;
}
