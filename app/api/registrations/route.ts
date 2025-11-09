import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireAuth } from "@/lib/authz";
import { canVolunteerRegisterForEndeavour } from "@/lib/visibility";
import { audit } from "@/lib/audit";
import { sendParentRegistrationNotice } from "@/server/email/service";
import { metrics } from "@/lib/metrics";

const createSchema = z.object({
  endeavourId: z.string()
});

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER"]);
  const body = await request.json();
  const parsed = createSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  if (!canVolunteerRegisterForEndeavour(user, parsed.data.endeavourId)) {
    return NextResponse.json({ error: "You are not eligible to register for this endeavour." }, { status: 403 });
  }

  const endeavour = await prisma.endeavour.findUniqueOrThrow({
    where: { id: parsed.data.endeavourId },
    select: {
      id: true,
      title: true,
      startAt: true,
      venue: true,
      entityId: true,
      requiresTransportPayment: true,
      registrations: {
        where: { volunteerId: user.id },
        select: { id: true }
      }
    }
  });

  if (endeavour.registrations.length > 0) {
    return NextResponse.json({ error: "Already registered." }, { status: 409 });
  }

  const nextStatus = endeavour.requiresTransportPayment ? "PAYMENT_PENDING" : "CONSENT_PENDING";

  const registration = await prisma.registration.create({
    data: {
      endeavourId: parsed.data.endeavourId,
      volunteerId: user.id,
      status: nextStatus
    }
  });

  metrics.registrationsCreated.inc();

  const parents = await prisma.parentContact.findMany({
    where: { volunteerId: user.id },
    select: { email: true, name: true }
  });

  await Promise.allSettled(
    parents.map((parent) =>
      sendParentRegistrationNotice({
        parentEmail: parent.email,
        volunteerName: user.name ?? "Volunteer",
        endeavourTitle: endeavour.title,
        startAt: endeavour.startAt.toISOString(),
        venue: endeavour.venue,
        consentUrl: `${process.env.NEXTAUTH_URL ?? "http://localhost:3000"}/consent/${registration.id}`
      })
    )
  );

  metrics.emailsSent.inc(parents.length);

  await audit({
    actorUserId: user.id,
    action: "registration.create",
    subjectId: registration.id,
    entityId: endeavour.entityId
  });

  return NextResponse.json({ registration }, { status: 201 });
}
