import { Suspense } from "react";
import { EndeavourList } from "@/components/endeavour-list";
import { getCurrentUser } from "@/lib/session";
import { requireAuth } from "@/lib/authz";

export default async function DashboardPage() {
  const user = await getCurrentUser();
  try {
    requireAuth(user, ["VOLUNTEER", "ENTITY_MANAGER", "HR", "ADMIN"]);
  } catch (error) {
    return (
      <main className="p-6">
        <p>You are not authorized to view this page.</p>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-5xl space-y-6 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-semibold">Upcoming Endeavours</h1>
          <p className="text-sm text-muted-foreground">
            Register for new opportunities and track your application status.
          </p>
        </div>
      </header>
      <Suspense fallback={<p>Loading endeavours…</p>}>
        <EndeavourList />
      </Suspense>
    </main>
  );
}
