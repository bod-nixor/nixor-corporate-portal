import { NextResponse } from "next/server";
import { z } from "zod";
import { getPrismaClient } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireAuth, assertEntityManager } from "@/lib/authz";
import { canVolunteerRegisterForEndeavour, canVolunteerSeeEndeavour } from "@/lib/visibility";
import { enforceRateLimit } from "@/lib/rate-limiter";
import { env } from "@/lib/env";
import { audit } from "@/lib/audit";

export const dynamic = "force-dynamic";

const filtersSchema = z.object({
  entityId: z.string().optional(),
  tag: z.string().optional()
});

const createSchema = z.object({
  entityId: z.string(),
  title: z.string().min(3),
  description: z.string().min(10),
  venue: z.string().min(2),
  startAt: z.string(),
  endAt: z.string(),
  maxVolunteers: z.number().int().positive().nullable().optional(),
  requiresTransportPayment: z.boolean()
});

export async function GET(request: Request) {
  const prisma = getPrismaClient();
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER", "HR", "ADMIN"]);
  const { searchParams } = new URL(request.url);
  const parsed = filtersSchema.safeParse({
    entityId: searchParams.get("entityId") ?? undefined,
    tag: searchParams.get("tag") ?? undefined
  });
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  type EndeavourForListing = {
    id: string;
    title: string;
    description: string;
    startAt: Date;
    endAt: Date;
    venue: string;
    entityId: string;
    entity: { name: string };
    requiresTransportPayment: boolean;
    maxVolunteers: number | null;
    tags: { tag: { name: string } }[];
    registrations: { status: string }[];
  };

  const endeavours = (await prisma.endeavour.findMany({
    where: {
      entityId: parsed.data.entityId ?? undefined,
      tags: parsed.data.tag ? { some: { tag: { slug: parsed.data.tag } } } : undefined
    },
    orderBy: { startAt: "asc" },
    select: {
      id: true,
      title: true,
      description: true,
      startAt: true,
      endAt: true,
      venue: true,
      entityId: true,
      entity: { select: { name: true } },
      requiresTransportPayment: true,
      maxVolunteers: true,
      tags: { select: { tag: { select: { name: true } } } },
      registrations: {
        where: { volunteerId: user.id },
        select: { status: true }
      }
    }
  })) as EndeavourForListing[];

  const visible = endeavours.filter((endeavour) => canVolunteerSeeEndeavour(user, endeavour.entityId));

  return NextResponse.json({
    endeavours: visible.map((endeavour) => ({
      id: endeavour.id,
      title: endeavour.title,
      description: endeavour.description,
      startAt: endeavour.startAt,
      endAt: endeavour.endAt,
      venue: endeavour.venue,
      entityId: endeavour.entityId,
      entityName: endeavour.entity.name,
      requiresTransportPayment: endeavour.requiresTransportPayment,
      maxVolunteers: endeavour.maxVolunteers,
      tags: endeavour.tags.map((item) => item.tag.name),
      registrationStatus: endeavour.registrations[0]?.status,
      isEligible: canVolunteerRegisterForEndeavour(user, endeavour.entityId)
    }))
  });
}

export async function POST(request: Request) {
  const prisma = getPrismaClient();
  const user = await getCurrentUser();
  requireAuth(user, ["ENTITY_MANAGER", "ADMIN"]);
  const body = await request.json();
  const parsed = createSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  if (user.role !== "ADMIN") {
    assertEntityManager(user, parsed.data.entityId);
  }

  const entity = await prisma.entity.findUniqueOrThrow({
    where: { id: parsed.data.entityId },
    select: { id: true, publishQuotaPer7d: true }
  });

  await enforceRateLimit(
    {
      namespace: env.RATE_LIMIT_REDIS_NAMESPACE,
      windowSeconds: 60 * 60 * 24 * 7,
      max: entity.publishQuotaPer7d
    },
    entity.id
  );

  const endeavour = await prisma.endeavour.create({
    data: {
      ...parsed.data,
      createdByUserId: user.id
    }
  });

  await audit({ actorUserId: user.id, action: "endeavour.create", subjectId: endeavour.id });

  return NextResponse.json({ endeavour }, { status: 201 });
}
