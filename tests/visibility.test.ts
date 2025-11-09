import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  canVolunteerRegisterForEndeavour,
  canVolunteerSeeEndeavour,
  setVisibilityMode
} from "@/lib/visibility";
import type { UserWithMemberships } from "@/lib/types";
import * as envModule from "@/lib/env";

vi.mock("@/lib/env", () => ({ env: { VISIBILITY_MODE: "RESTRICTED" } }));

const volunteer: UserWithMemberships = {
  id: "vol",
  email: "vol@nixorcollege.edu.pk",
  name: "Volunteer",
  role: "VOLUNTEER",
  studentId: "S1",
  memberships: [
    { entityId: "entity-a", role: "VOLUNTEER" },
    { entityId: "entity-b", role: "ENTITY_MANAGER" }
  ]
};

const entityManager: UserWithMemberships = {
  id: "mgr",
  email: "manager@nixorcollege.edu.pk",
  name: "Manager",
  role: "ENTITY_MANAGER",
  studentId: null,
  memberships: [{ entityId: "entity-b", role: "ENTITY_MANAGER" }]
};

const hrUser: UserWithMemberships = {
  id: "hr",
  email: "hr@nixorcollege.edu.pk",
  name: "HR",
  role: "HR",
  studentId: null,
  memberships: []
};

beforeEach(() => {
  setVisibilityMode((envModule as unknown as { env: { VISIBILITY_MODE: "RESTRICTED" | "OPEN" } }).env.VISIBILITY_MODE);
});

describe("visibility", () => {
  it("restricts viewing when restricted mode", () => {
    setVisibilityMode("RESTRICTED");

    expect(canVolunteerSeeEndeavour(volunteer, "entity-a")).toBe(true);
    expect(canVolunteerSeeEndeavour(volunteer, "entity-c")).toBe(false);
  });

  it("allows view for all in open mode", () => {
    setVisibilityMode("OPEN");
    expect(canVolunteerSeeEndeavour(volunteer, "entity-c")).toBe(true);
  });

  it("allows entity managers to view endeavours they manage", () => {
    setVisibilityMode("RESTRICTED");

    expect(canVolunteerSeeEndeavour(entityManager, "entity-b")).toBe(true);
    expect(canVolunteerSeeEndeavour(entityManager, "entity-a")).toBe(false);
  });

  it("allows HR staff to view endeavours regardless of membership", () => {
    setVisibilityMode("RESTRICTED");

    expect(canVolunteerSeeEndeavour(hrUser, "entity-a")).toBe(true);
    expect(canVolunteerSeeEndeavour(hrUser, "entity-z")).toBe(true);
  });

  it("only allows registration for membership entities", () => {
    expect(canVolunteerRegisterForEndeavour(volunteer, "entity-a")).toBe(true);
    expect(canVolunteerRegisterForEndeavour(volunteer, "entity-c")).toBe(false);
  });
});
