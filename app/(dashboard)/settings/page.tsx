import { AdminSettings } from "@/components/admin-settings";
import { getCurrentUser } from "@/lib/session";
import { requireRole } from "@/lib/authz";

export default async function SettingsPage() {
  const user = await getCurrentUser();
  try {
    requireRole(user, ["ADMIN"]);
  } catch (error) {
    return (
      <main className="p-6">
        <p>You are not authorized to view this page.</p>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-4xl space-y-6 p-6">
      <header>
        <h1 className="text-3xl font-semibold">Admin Settings</h1>
        <p className="text-sm text-muted-foreground">
          Configure visibility, quotas, and integrations.
        </p>
      </header>
      <AdminSettings />
    </main>
  );
}
