"use client";

import { useEffect, useState } from "react";

interface SettingsState {
  visibilityMode: "RESTRICTED" | "OPEN";
  entities: Array<{ id: string; name: string; publishQuotaPer7d: number }>;
}

export function AdminSettings() {
  const [state, setState] = useState<SettingsState | null>(null);

  useEffect(() => {
    async function load() {
      const response = await fetch("/api/admin/settings");
      const data = await response.json();
      setState(data);
    }
    load();
  }, []);

  if (!state) {
    return <p>Loading settings…</p>;
  }

  return (
    <div className="space-y-6">
      <section>
        <h2 className="text-xl font-semibold">Visibility Mode</h2>
        <p className="text-sm text-muted-foreground">
          Choose how volunteers discover endeavours across entities.
        </p>
        <div className="mt-3 flex gap-3">
          {(["RESTRICTED", "OPEN"] as const).map((mode) => (
            <button
              key={mode}
              className={`rounded border px-3 py-2 text-sm ${
                state.visibilityMode === mode ? "border-primary" : "border-border"
              }`}
              onClick={async () => {
                await fetch("/api/admin/settings", {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({ visibilityMode: mode })
                });
                setState((prev) => (prev ? { ...prev, visibilityMode: mode } : prev));
              }}
            >
              {mode === "RESTRICTED" ? "Restricted View" : "Open View"}
            </button>
          ))}
        </div>
      </section>

      <section>
        <h2 className="text-xl font-semibold">Entity publish quotas</h2>
        <div className="space-y-3">
          {state.entities.map((entity) => (
            <div key={entity.id} className="flex items-center justify-between rounded border p-3">
              <div>
                <p className="font-medium">{entity.name}</p>
                <p className="text-sm text-muted-foreground">
                  {entity.publishQuotaPer7d} endeavours per 7 days
                </p>
              </div>
              <button
                className="rounded border border-border px-3 py-1 text-sm"
                onClick={async () => {
                  const quota = Number(window.prompt("New quota", entity.publishQuotaPer7d.toString()));
                  if (!Number.isFinite(quota)) return;
                  await fetch(`/api/admin/entities/${entity.id}/quota`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ publishQuotaPer7d: quota })
                  });
                  setState((prev) =>
                    prev
                      ? {
                          ...prev,
                          entities: prev.entities.map((item) =>
                            item.id === entity.id ? { ...item, publishQuotaPer7d: quota } : item
                          )
                        }
                      : prev
                  );
                }}
              >
                Edit
              </button>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}
