import { NextResponse } from "next/server";
import { prisma } from "../../src/lib/prisma";
import { getCurrentUser } from "../../src/lib/session";
import { requireRole } from "../../src/lib/authz";

export async function GET() {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN"]);
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
