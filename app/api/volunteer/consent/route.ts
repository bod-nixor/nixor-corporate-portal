import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireAuth } from "@/lib/authz";
import { audit } from "@/lib/audit";

const consentSchema = z.object({
  registrationId: z.string(),
  formSnapshot: z.record(z.any())
});

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER"]);
  const body = await request.json();
  const parsed = consentSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  const registration = await prisma.registration.findFirstOrThrow({
    where: { id: parsed.data.registrationId, volunteerId: user.id }
  });

  await prisma.consentForm.upsert({
    where: { registrationId: registration.id },
    update: {},
    create: {
      registrationId: registration.id,
      formSnapshotJson: parsed.data.formSnapshot,
      submittedAt: new Date()
    }
  });

  await prisma.registration.update({
    where: { id: registration.id },
    data: {
      status: registration.status === "PAYMENT_PENDING" ? "PAYMENT_PENDING" : "CONFIRMED"
    }
  });

  await audit({
    actorUserId: user.id,
    action: "consent.submit",
    subjectId: registration.id
  });

  return NextResponse.json({ ok: true });
}
