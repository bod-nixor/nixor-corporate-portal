import { describe, it, expect, vi } from "vitest";
import { canVolunteerRegisterForEndeavour, canVolunteerSeeEndeavour } from "../src/lib/visibility";
import type { UserWithMemberships } from "../src/lib/types";
import * as envModule from "../src/lib/env";

vi.mock("../src/lib/env", () => ({ env: { VISIBILITY_MODE: "RESTRICTED" } }));

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

describe("visibility", () => {
  it("restricts viewing when restricted mode", () => {
    (envModule as unknown as { env: { VISIBILITY_MODE: string } }).env.VISIBILITY_MODE = "RESTRICTED";
    expect(canVolunteerSeeEndeavour(volunteer, "entity-a")).toBe(true);
    expect(canVolunteerSeeEndeavour(volunteer, "entity-c")).toBe(false);
  });

  it("allows view for all in open mode", () => {
    (envModule as unknown as { env: { VISIBILITY_MODE: string } }).env.VISIBILITY_MODE = "OPEN";
    expect(canVolunteerSeeEndeavour(volunteer, "entity-c")).toBe(true);
  });

  it("only allows registration for membership entities", () => {
    expect(canVolunteerRegisterForEndeavour(volunteer, "entity-a")).toBe(true);
    expect(canVolunteerRegisterForEndeavour(volunteer, "entity-c")).toBe(false);
  });
});
