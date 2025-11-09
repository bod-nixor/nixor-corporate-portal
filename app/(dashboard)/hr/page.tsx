import { Suspense } from "react";
import { HRDashboard } from "../../../src/components/hr-dashboard";
import { getCurrentUser } from "../../../src/lib/session";
import { requireRole } from "../../../src/lib/authz";

export default async function HRPage() {
  const user = await getCurrentUser();
  try {
    requireRole(user, ["HR", "ADMIN"]);
  } catch (error) {
    return (
      <main className="p-6">
        <p>You are not authorized to view this page.</p>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-6xl space-y-6 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-semibold">HR Shortlisting</h1>
          <p className="text-sm text-muted-foreground">
            Review volunteer registrations and manage consent tasks.
          </p>
        </div>
      </header>
      <Suspense fallback={<p>Loading registrations…</p>}>
        <HRDashboard />
      </Suspense>
    </main>
  );
}
