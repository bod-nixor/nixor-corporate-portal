import { NextResponse } from "next/server";
import { z } from "zod";
import { getPrismaClient } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireRole } from "@/lib/authz";
import { audit } from "@/lib/audit";
import {
  getVisibilityMode,
  setVisibilityMode
} from "@/lib/visibility";
import type { VisibilityMode } from "@/lib/types";

export const dynamic = "force-dynamic";

const schema = z.object({ visibilityMode: z.enum(["RESTRICTED", "OPEN"]) });

export async function GET() {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN"]);
  const prisma = getPrismaClient();
  const entities = await prisma.entity.findMany({
    select: { id: true, name: true, publishQuotaPer7d: true }
  });
  const lastSetting = await prisma.auditLog.findFirst({
    where: { action: "settings.visibility" },
    orderBy: { createdAt: "desc" },
    select: { metadataJson: true }
  });
  const persistedMode = (lastSetting?.metadataJson as { visibilityMode?: string } | undefined)
    ?.visibilityMode as VisibilityMode | undefined;
  const visibilityMode = persistedMode ?? getVisibilityMode();

  if (persistedMode && persistedMode !== getVisibilityMode()) {
    setVisibilityMode(persistedMode);
  }
  return NextResponse.json({ visibilityMode, entities });
}

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN"]);
  const body = await request.json();
  const parsed = schema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }
  process.env.VISIBILITY_MODE = parsed.data.visibilityMode;
  setVisibilityMode(parsed.data.visibilityMode);
  await audit({
    actorUserId: user.id,
    action: "settings.visibility",
    metadata: { visibilityMode: parsed.data.visibilityMode }
  });
  return NextResponse.json({ visibilityMode: parsed.data.visibilityMode });
}
