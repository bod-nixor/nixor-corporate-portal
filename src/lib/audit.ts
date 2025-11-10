import { getPrismaClient } from "./prisma";

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
      metadataJson: metadata ?? {}
    }
  });
}
