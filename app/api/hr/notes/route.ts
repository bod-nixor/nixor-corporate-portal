import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "../../../src/lib/prisma";
import { getCurrentUser } from "../../../src/lib/session";
import { requireRole } from "../../../src/lib/authz";
import { audit } from "../../../src/lib/audit";

const schema = z.object({
  registrationId: z.string(),
  note: z.string().min(1)
});

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireRole(user, ["HR", "ADMIN"]);
  const body = await request.json();
  const parsed = schema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  const registration = await prisma.registration.findUniqueOrThrow({
    where: { id: parsed.data.registrationId },
    select: { volunteerId: true, endeavour: { select: { entityId: true } } }
  });

  await prisma.hRNote.create({
    data: {
      volunteerId: registration.volunteerId,
      entityId: registration.endeavour.entityId,
      authorId: user.id,
      note: parsed.data.note
    }
  });

  await audit({
    actorUserId: user.id,
    action: "hr.note.create",
    subjectId: registration.volunteerId,
    entityId: registration.endeavour.entityId
  });

  return NextResponse.json({ ok: true });
}
