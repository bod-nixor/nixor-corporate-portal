import { NextResponse } from "next/server";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireAuth } from "@/lib/authz";
import { paymentProvider } from "@/lib/payment-provider";
import { audit } from "@/lib/audit";

const schema = z.object({ registrationId: z.string(), amountCents: z.number().int().positive() });

export async function POST(request: Request) {
  const user = await getCurrentUser();
  requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER"]);
  const body = await request.json();
  const parsed = schema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: parsed.error.message }, { status: 400 });
  }

  const registration = await prisma.registration.findFirstOrThrow({
    where: { id: parsed.data.registrationId, volunteerId: user.id },
    select: { id: true, status: true }
  });

  const intent = await paymentProvider.createIntent(parsed.data.amountCents, "PKR");

  await prisma.payment.upsert({
    where: { registrationId: registration.id },
    update: { amountCents: parsed.data.amountCents, status: "INITIATED", providerRef: intent.id },
    create: {
      registrationId: registration.id,
      amountCents: parsed.data.amountCents,
      currency: "PKR",
      status: "INITIATED",
      providerRef: intent.id
    }
  });

  await prisma.registration.update({
    where: { id: registration.id },
    data: { status: "PAYMENT_PENDING" }
  });

  await audit({ actorUserId: user.id, action: "payment.intent", subjectId: registration.id });

  return NextResponse.json({ intent });
}
