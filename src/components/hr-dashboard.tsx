"use client";

import { useEffect, useState } from "react";

interface CandidateRow {
  registrationId: string;
  endeavourTitle: string;
  volunteerName: string;
  volunteerEmail: string;
  studentId: string | null;
  participationCount: number;
  lastParticipationDate: string | null;
  status: string;
  notes: string;
}

export function HRDashboard() {
  const [rows, setRows] = useState<CandidateRow[]>([]);
  const [selected, setSelected] = useState<string[]>([]);

  useEffect(() => {
    async function load() {
      const response = await fetch("/api/hr/candidates");
      const data = await response.json();
      setRows(data.candidates);
    }
    load();
  }, []);

  async function bulkShortlist() {
    await fetch("/api/hr/shortlist", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ registrationIds: selected })
    });
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <button
          className="rounded bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50"
          disabled={selected.length === 0}
          onClick={bulkShortlist}
        >
          Bulk shortlist
        </button>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] table-fixed border-collapse text-left text-sm">
          <thead>
            <tr className="bg-muted text-xs uppercase tracking-wide">
              <th className="p-3">Select</th>
              <th className="p-3">Endeavour</th>
              <th className="p-3">Volunteer</th>
              <th className="p-3">Student ID</th>
              <th className="p-3">Participation count</th>
              <th className="p-3">Last participation</th>
              <th className="p-3">Status</th>
              <th className="p-3">Notes</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.registrationId} className="border-b">
                <td className="p-3">
                  <input
                    type="checkbox"
                    checked={selected.includes(row.registrationId)}
                    onChange={(event) => {
                      setSelected((prev) =>
                        event.target.checked
                          ? [...prev, row.registrationId]
                          : prev.filter((id) => id !== row.registrationId)
                      );
                    }}
                    aria-label={`Select ${row.volunteerName}`}
                  />
                </td>
                <td className="p-3">{row.endeavourTitle}</td>
                <td className="p-3">
                  <div className="flex flex-col">
                    <span>{row.volunteerName}</span>
                    <span className="text-xs text-muted-foreground">{row.volunteerEmail}</span>
                  </div>
                </td>
                <td className="p-3">{row.studentId ?? "—"}</td>
                <td className="p-3">{row.participationCount}</td>
                <td className="p-3">{row.lastParticipationDate ?? "—"}</td>
                <td className="p-3">{row.status}</td>
                <td className="p-3">
                  <textarea
                    className="w-full rounded border border-input bg-background p-2"
                    defaultValue={row.notes}
                    onBlur={async (event) => {
                      await fetch("/api/hr/notes", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                          registrationId: row.registrationId,
                          note: event.target.value
                        })
                      });
                    }}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
