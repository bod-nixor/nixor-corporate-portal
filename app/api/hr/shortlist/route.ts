import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "../../../src/lib/prisma";
import { getCurrentUser } from "../../../src/lib/session";
import { requireRole } from "../../../src/lib/authz";
import { audit } from "../../../src/lib/audit";

const schema = z.object({
  registrationIds: z.array(z.string()).min(1)
});

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireRole(user, ["HR", "ADMIN"]);
  const body = await request.json();
  const parsed = schema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  await prisma.registration.updateMany({
    where: { id: { in: parsed.data.registrationIds } },
    data: { status: "SHORTLISTED" }
  });

  await audit({
    actorUserId: user.id,
    action: "registration.shortlist",
    metadata: { registrationIds: parsed.data.registrationIds }
  });

  return NextResponse.json({ ok: true });
}
