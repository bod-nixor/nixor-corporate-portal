import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "../../src/lib/prisma";
import { getCurrentUser } from "../../src/lib/session";
import { requireRole } from "../../src/lib/authz";
import { audit } from "../../src/lib/audit";

const entitySchema = z.object({
  name: z.string().min(2),
  slug: z.string().min(2),
  publishQuotaPer7d: z.number().int().min(1)
});

export async function GET() {
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER", "HR", "ADMIN"]);
  const entities = await prisma.entity.findMany({
    select: { id: true, name: true, slug: true, publishQuotaPer7d: true }
  });
  return NextResponse.json({ entities });
}

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN"]);
  const body = await request.json();
  const parsed = entitySchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }
  const entity = await prisma.entity.create({ data: parsed.data });
  await audit({ actorUserId: user.id, action: "entity.create", subjectId: entity.id });
  return NextResponse.json({ entity }, { status: 201 });
}
