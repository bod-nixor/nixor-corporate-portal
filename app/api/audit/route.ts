import { NextResponse } from "next/server";
import { getPrismaClient } from "@/lib/prisma";
import { getCurrentUser } from "@/lib/session";
import { requireRole } from "@/lib/authz";

export const dynamic = "force-dynamic";

export async function GET() {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN"]);
  const prisma = getPrismaClient();
  const logs = await prisma.auditLog.findMany({
    orderBy: { createdAt: "desc" },
    take: 50,
    select: {
      id: true,
      action: true,
      metadataJson: true,
      createdAt: true
    }
  });
  return NextResponse.json({ logs });
}
