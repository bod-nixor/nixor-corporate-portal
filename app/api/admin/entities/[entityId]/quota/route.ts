import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "../../../../../src/lib/prisma";
import { getCurrentUser } from "../../../../../src/lib/session";
import { requireRole } from "../../../../../src/lib/authz";
import { audit } from "../../../../../src/lib/audit";

const schema = z.object({ publishQuotaPer7d: z.number().int().min(1) });

export async function POST(
  request: Request,
  { params }: { params: { entityId: string } }
) {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN"]);
  const body = await request.json();
  const parsed = schema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }
  const entity = await prisma.entity.update({
    where: { id: params.entityId },
    data: { publishQuotaPer7d: parsed.data.publishQuotaPer7d }
  });
  await audit({ actorUserId: user.id, action: "entity.quota.update", subjectId: entity.id });
  return NextResponse.json({ entity });
}
