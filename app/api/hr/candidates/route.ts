import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireRole } from "@/lib/authz";

export async function GET() {
  const user = await getCurrentUser();
  requireRole(user, ["HR", "ADMIN"]);

  const candidates = await prisma.registration.findMany({
    select: {
      id: true,
      status: true,
      volunteer: {
        select: {
          id: true,
          name: true,
          email: true,
          studentId: true,
          participations: {
            select: {
              entityId: true,
              participatedAt: true
            },
            orderBy: { participatedAt: "desc" }
          }
        }
      },
      endeavour: { select: { title: true, entityId: true } }
    },
    orderBy: { registeredAt: "asc" }
  });

  return NextResponse.json({
    candidates: await Promise.all(
      candidates.map(async (registration) => {
        const participationCount = registration.volunteer.participations.filter(
          (p) => p.entityId === registration.endeavour.entityId
        );
        const latestNote = await prisma.hRNote.findFirst({
          where: {
            volunteerId: registration.volunteer.id,
            entityId: registration.endeavour.entityId
          },
          orderBy: { createdAt: "desc" },
          select: { note: true }
        });
        return {
          registrationId: registration.id,
          endeavourTitle: registration.endeavour.title,
          volunteerName: registration.volunteer.name,
          volunteerEmail: registration.volunteer.email,
          studentId: registration.volunteer.studentId,
          participationCount: participationCount.length,
          lastParticipationDate: participationCount[0]?.participatedAt?.toISOString() ?? null,
          status: registration.status,
          notes: latestNote?.note ?? ""
        };
      })
    )
  });
}
