export type UserWithMemberships = {
  id: string;
  email: string;
  name: string | null;
  role: string;
  studentId: string | null;
  memberships: Array<{
    entityId: string;
    role: string;
  }>;
};

export type VisibilityMode = "RESTRICTED" | "OPEN";
