import { env } from "./env";
import type { UserWithMemberships, VisibilityMode } from "./types";

let runtimeVisibilityMode: VisibilityMode = env.VISIBILITY_MODE;

export function getVisibilityMode(): VisibilityMode {
  return runtimeVisibilityMode;
}

export function setVisibilityMode(mode: VisibilityMode) {
  runtimeVisibilityMode = mode;
}

export function canVolunteerSeeEndeavour(
  user: UserWithMemberships,
  endeavourEntityId: string
) {
  if (user.role === "ADMIN" || user.role === "HR") {
    return true;
  }

  const memberships = user.memberships;

  if (
    memberships.some(
      (membership) =>
        membership.role === "ENTITY_MANAGER" && membership.entityId === endeavourEntityId
    )
  ) {
    return true;
  }

  if (getVisibilityMode() === "OPEN") {
    return true;
  }

  return memberships.some(
    (membership) =>
      membership.role === "VOLUNTEER" && membership.entityId === endeavourEntityId
  );
}

export function canVolunteerRegisterForEndeavour(
  user: UserWithMemberships,
  endeavourEntityId: string
) {
  const membership = user.memberships.find((m) => m.entityId === endeavourEntityId);
  return Boolean(membership && ["VOLUNTEER", "ENTITY_MANAGER"].includes(membership.role));
}
