import { NextResponse } from "next/server";
import { getCurrentUser } from "../../../src/lib/session";
import { requireRole } from "../../../src/lib/authz";
import { parentRegistrationTemplate } from "../../../src/server/email/templates";

export async function GET() {
  const user = await getCurrentUser();
  requireRole(user, ["ADMIN", "HR"]);
  const html = parentRegistrationTemplate({
    volunteerName: "John Doe",
    endeavourTitle: "Community Kitchen",
    startAt: new Date().toISOString(),
    venue: "Main Hall",
    consentUrl: "https://example.com"
  });
  return new NextResponse(html, {
    headers: { "content-type": "text/html" }
  });
}
