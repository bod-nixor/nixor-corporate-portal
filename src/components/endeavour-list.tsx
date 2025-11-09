"use client";

import { useEffect, useState } from "react";
import { format } from "date-fns";
import { cn } from "../utils/cn";

interface EndeavourDto {
  id: string;
  title: string;
  entityName: string;
  entityId: string;
  startAt: string;
  endAt: string;
  venue: string;
  tags: string[];
  status?: string;
  requiresTransportPayment: boolean;
  maxVolunteers?: number | null;
  registrationStatus?: string;
  isEligible: boolean;
}

export function EndeavourList() {
  const [endeavours, setEndeavours] = useState<EndeavourDto[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      setLoading(true);
      const response = await fetch("/api/endeavours");
      const data = await response.json();
      setEndeavours(data.endeavours);
      setLoading(false);
    }
    load();
  }, []);

  if (loading) {
    return <p>Loading endeavours…</p>;
  }

  return (
    <div className="grid gap-4 md:grid-cols-2">
      {endeavours.map((endeavour) => (
        <article
          key={endeavour.id}
          className="rounded-lg border border-border bg-card p-4 shadow-sm"
          aria-label={endeavour.title}
        >
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold">{endeavour.title}</h2>
              <p className="text-xs uppercase tracking-wider text-muted-foreground">
                {endeavour.entityName}
              </p>
            </div>
            <span
              className={cn(
                "rounded-full px-3 py-1 text-xs",
                endeavour.registrationStatus === "CONFIRMED"
                  ? "bg-emerald-500/10 text-emerald-600"
                  : "bg-slate-500/10 text-slate-500"
              )}
            >
              {endeavour.registrationStatus ?? "Not registered"}
            </span>
          </div>
          <dl className="mt-3 grid gap-1 text-sm text-muted-foreground">
            <div className="flex justify-between">
              <dt>Date</dt>
              <dd>{format(new Date(endeavour.startAt), "PPpp")}</dd>
            </div>
            <div className="flex justify-between">
              <dt>Venue</dt>
              <dd>{endeavour.venue}</dd>
            </div>
            {endeavour.maxVolunteers && (
              <div className="flex justify-between">
                <dt>Capacity</dt>
                <dd>{endeavour.maxVolunteers}</dd>
              </div>
            )}
          </dl>
          <div className="mt-3 flex flex-wrap gap-2">
            {endeavour.tags.map((tag) => (
              <span key={tag} className="rounded-full bg-muted px-2 py-1 text-xs">
                {tag}
              </span>
            ))}
          </div>
          <button
            className="mt-4 w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
            disabled={!endeavour.isEligible}
            onClick={async () => {
              await fetch("/api/registrations", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ endeavourId: endeavour.id })
              });
            }}
          >
            {endeavour.isEligible ? "Register Interest" : "Ineligible"}
          </button>
        </article>
      ))}
      {endeavours.length === 0 && <p>No endeavours available.</p>}
    </div>
  );
}
