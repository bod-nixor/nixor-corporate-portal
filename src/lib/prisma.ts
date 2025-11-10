/* eslint-env es2021 */
import { PrismaClient } from "@prisma/client";

const globalForPrisma = globalThis as unknown as { prisma: PrismaClient | undefined };

function createPrismaClient(): PrismaClient {
  try {
    return new PrismaClient({
      log: process.env.NODE_ENV === "development" ? ["query", "warn", "error"] : ["error"]
    });
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Prisma client could not be initialized.";
    console.warn("Falling back to a no-op Prisma client:", message);
    return new Proxy(
      {},
      {
        get() {
          throw new Error(
            "Prisma client is unavailable. Ensure `prisma generate` has been executed before using database APIs."
          );
        }
      }
    ) as PrismaClient;
  }
}

export const prisma = globalForPrisma.prisma ?? createPrismaClient();

if (process.env.NODE_ENV !== "production") {
  globalForPrisma.prisma = prisma;
}
