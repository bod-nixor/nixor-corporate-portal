import { NextResponse } from "next/server";
import { getPrismaClient } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireAuth } from "@/lib/authz";

export const dynamic = "force-dynamic";

export async function GET() {
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER"]);
  const prisma = getPrismaClient();
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
