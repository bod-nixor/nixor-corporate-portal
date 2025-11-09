import type { Prisma } from "@prisma/client";

export type UserWithMemberships = Prisma.UserGetPayload<{
  select: {
    id: true;
    email: true;
    name: true;
    role: true;
    studentId: true;
    memberships: {
      select: {
        entityId: true;
        role: true;
      };
    };
  };
}>;

export type VisibilityMode = "RESTRICTED" | "OPEN";
