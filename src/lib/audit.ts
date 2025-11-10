import { Prisma } from "@prisma/client";
import { getPrismaClient } from "./prisma";

// Accept both regular JSON and the Prisma JsonNull sentinel
type JsonInput = Prisma.InputJsonValue | typeof Prisma.JsonNull;

function toJsonInput(value: unknown): JsonInput {
  // If undefined/null, use Prisma’s JsonNull sentinel for JSON columns
  if (value === undefined || value === null) {
    return Prisma.JsonNull;
  }
  // Ensure plain JSON-serializable value
  return JSON.parse(JSON.stringify(value)) as Prisma.InputJsonValue;
}

interface AuditArgs {
  actorUserId?: string | null;
  action: string;
  entityId?: string | null;
  targetUserId?: string | null;
  subjectId?: string | null;
  metadata?: Record<string, unknown>;
}

export async function audit({
  actorUserId,
  action,
  entityId,
  targetUserId,
  subjectId,
  metadata
}: AuditArgs) {
  const prisma = getPrismaClient();
  await prisma.auditLog.create({
    data: {
      actorUserId: actorUserId ?? undefined,
      action,
      entityId: entityId ?? undefined,
      targetUserId: targetUserId ?? undefined,
      subjectId: subjectId ?? undefined,
      // Use the helper to convert metadata into a valid Prisma input
      metadataJson: toJsonInput(metadata),
    },
  });
}