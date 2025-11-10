import { NextResponse } from "next/server";
import { getPrismaClient } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireRole } from "@/lib/authz";

type CandidateRegistration = {
  id: string;
  status: string;
  volunteer: {
    id: string;
    name: string | null;
    email: string;
    studentId: string | null;
    participations: Array<{
      entityId: string;
      participatedAt: Date;
    }>;
  };
  endeavour: {
    title: string;
    entityId: string;
  };
};

export const dynamic = "force-dynamic";

export async function GET() {
  const user = await getCurrentUser();
  requireRole(user, ["HR", "ADMIN"]);
  const prisma = getPrismaClient();

  const candidates: CandidateRegistration[] = await prisma.registration.findMany({
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
      candidates.map(async (registration: CandidateRegistration) => {
        const participationCount = registration.volunteer.participations.filter(
          (participation) => participation.entityId === registration.endeavour.entityId
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
