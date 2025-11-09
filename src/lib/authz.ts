import { ForbiddenError, UnauthorizedError } from "./errors";
import type { UserWithMemberships } from "./types";

export function requireAuth<T extends UserWithMemberships | null | undefined>(
  user: T,
  allowedRoles: Array<UserWithMemberships["role"]>
): asserts user is UserWithMemberships {
  if (!user) {
    throw new UnauthorizedError();
  }
  if (!allowedRoles.includes(user.role)) {
    throw new ForbiddenError();
  }
}

export function requireRole(
  user: UserWithMemberships | null,
  allowedRoles: Array<UserWithMemberships["role"]>
): asserts user is UserWithMemberships {
  requireAuth(user, allowedRoles);
}

export function hasEntityMembership(user: UserWithMemberships | null, entityId: string) {
  if (!user) return false;
  return user.memberships.some((membership) => membership.entityId === entityId);
}

export function assertEntityManager(user: UserWithMemberships | null, entityId: string) {
  if (!user) throw new UnauthorizedError();
  const membership = user.memberships.find((m) => m.entityId === entityId);
  if (!membership || membership.role !== "ENTITY_MANAGER") {
    throw new ForbiddenError();
  }
}
