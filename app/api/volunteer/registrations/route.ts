import { NextResponse } from "next/server";
import { prisma } from "../../../src/lib/prisma";
import { getCurrentUser } from "../../../src/lib/session";
import { requireAuth } from "../../../src/lib/authz";

export async function GET() {
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER"]);
  const registrations = await prisma.registration.findMany({
    where: { volunteerId: user.id },
    select: {
      id: true,
      status: true,
      endeavour: {
        select: {
          id: true,
          title: true,
          startAt: true,
          entity: { select: { name: true } }
        }
      },
      consentForm: { select: { submittedAt: true } },
      payment: { select: { status: true } }
    },
    orderBy: { registeredAt: "desc" }
  });
  return NextResponse.json({ registrations });
}
