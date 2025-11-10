import { auth } from "./auth";
import { getPrismaClient } from "./prisma";

export async function getCurrentUser() {
  const session = await auth();
  if (!session?.user?.email) {
    return null;
  }
  const prisma = getPrismaClient();
  const user = await prisma.user.findUnique({
    where: { id: session.user.id },
    select: {
      id: true,
      email: true,
      name: true,
      role: true,
      studentId: true,
      memberships: {
        select: {
          entityId: true,
          role: true
        }
      }
    }
  });
  return user;
}
