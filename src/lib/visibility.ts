import { env } from "./env";
import type { UserWithMemberships } from "./types";

export function getVisibilityMode() {
  return env.VISIBILITY_MODE;
}

export function canVolunteerSeeEndeavour(
  user: UserWithMemberships,
  endeavourEntityId: string
) {
  const memberships = user.memberships.filter((m) => m.role === "VOLUNTEER");
  if (getVisibilityMode() === "OPEN") {
    return true;
  }
  return memberships.some((membership) => membership.entityId === endeavourEntityId);
}

export function canVolunteerRegisterForEndeavour(
  user: UserWithMemberships,
  endeavourEntityId: string
) {
  const membership = user.memberships.find((m) => m.entityId === endeavourEntityId);
  return Boolean(membership && ["VOLUNTEER", "ENTITY_MANAGER"].includes(membership.role));
}
