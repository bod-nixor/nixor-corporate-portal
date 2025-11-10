// src/lib/audit.ts
import { getPrismaClient } from "./prisma";
import { Prisma } from "@prisma/client";

interface AuditArgs {
  actorUserId?: string | null;
  action: string;
  entityId?: string | null;
  targetUserId?: string | null;
  subjectId?: string | null;
  metadata?: Record<string, unknown> | null | undefined;
}

// Allow both JSON values and the Prisma JsonNull sentinel
type JsonInput = Prisma.InputJsonValue | Prisma.JsonNull;

function toJsonInput(value: unknown): JsonInput {
  if (value === undefined || value === null) {
    // Store JSON null (not DB null) in a JSON column
    return Prisma.JsonNull;
  }
  // Deep-clone to ensure it's plain JSON-serializable data
  return JSON.parse(JSON.stringify(value)) as Prisma.InputJsonValue;
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
      metadataJson: toJsonInput(metadata)
    }
  });
}