import { describe, it, expect } from "vitest";
import { requireAuth, requireRole, hasEntityMembership } from "@/lib/authz";
import type { UserWithMemberships } from "@/lib/types";
import { UnauthorizedError, ForbiddenError } from "@/lib/errors";

const baseUser: UserWithMemberships = {
  id: "1",
  email: "user@nixorcollege.edu.pk",
  name: "User",
  role: "VOLUNTEER",
  studentId: "S1",
  memberships: [
    { entityId: "entity-1", role: "VOLUNTEER" },
    { entityId: "entity-2", role: "ENTITY_MANAGER" }
  ]
};

describe("requireAuth", () => {
  it("throws Unauthorized when missing", () => {
    expect(() => requireAuth(null, ["VOLUNTEER"])).toThrow(UnauthorizedError);
  });

  it("throws Forbidden when role mismatched", () => {
    expect(() => requireAuth(baseUser, ["ADMIN"])).toThrow(ForbiddenError);
  });

  it("passes when role allowed", () => {
    expect(() => requireRole(baseUser, ["VOLUNTEER", "ADMIN"])).not.toThrow();
  });
});

describe("hasEntityMembership", () => {
  it("returns true when user is member", () => {
    expect(hasEntityMembership(baseUser, "entity-1")).toBe(true);
  });

  it("returns false when not member", () => {
    expect(hasEntityMembership(baseUser, "missing")).toBe(false);
  });
});
