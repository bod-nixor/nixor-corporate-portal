import type { DefaultSession } from "next-auth";

/* eslint-disable no-unused-vars */
declare module "next-auth" {
  interface Session {
    user?: {
      id: string;
      role: string;
      studentId?: string | null;
    } & DefaultSession["user"];
  }

  interface User {
    role: string;
    studentId?: string | null;
  }
}
/* eslint-enable no-unused-vars */
