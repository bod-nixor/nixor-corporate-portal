import { Prisma } from "@prisma/client";
import { getPrismaClient } from "./prisma";

interface AuditArgs {
  actorUserId?: string | null;
  action: string;
  entityId?: string | null;
  targetUserId?: string | null;
  subjectId?: string | null;
  metadata?: unknown; // allow any JSON-serializable input
}

/**
 * Safely coerce arbitrary input into Prisma.InputJsonValue.
 * Falls back to Prisma.JsonNull when undefined/null.
 * Uses JSON round-trip to strip functions/BigInt/etc.
 */
function toInputJsonValue(value: unknown): Prisma.InputJsonValue {
  if (value === undefined || value === null) return Prisma.JsonNull;
  // Ensure it's plain JSON data
  return JSON.parse(JSON.stringify(value)) as Prisma.InputJsonValue;
}

export async function audit({
  actorUserId,
  action,
  entityId,
  targetUserId,
  subjectId,
  metadata,
}: AuditArgs): Promise<void> {
  const prisma = getPrismaClient();

  const metadataJson: Prisma.InputJsonValue = toInputJsonValue(metadata);

  await prisma.auditLog.create({
    data: {
      actorUserId: actorUserId ?? undefined,
      action,
      entityId: entityId ?? undefined,
      targetUserId: targetUserId ?? undefined,
      subjectId: subjectId ?? undefined,
      metadataJson, // ✅ correct Prisma JSON type
    },
  });
}