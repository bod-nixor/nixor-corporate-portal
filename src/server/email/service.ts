import { emailProvider } from "../../lib/email-provider";
import { getPrismaClient } from "../../lib/prisma";
import { parentRegistrationTemplate, volunteerConsentReminderTemplate } from "./templates";

export async function sendParentRegistrationNotice(params: {
  parentEmail: string;
  volunteerName: string;
  endeavourTitle: string;
  startAt: string;
  venue: string;
  consentUrl: string;
}) {
  const prisma = getPrismaClient();
  const html = parentRegistrationTemplate(params);
  await emailProvider.send({
    to: params.parentEmail,
    subject: `${params.volunteerName} registered for ${params.endeavourTitle}`,
    html
  });
  await prisma.emailLog.create({
    data: {
      toEmail: params.parentEmail,
      template: "Parent_Registration_Notice",
      contextJson: params
    }
  });
}

export async function sendVolunteerConsentReminder(params: {
  volunteerEmail: string;
  volunteerName: string;
  endeavourTitle: string;
  consentUrl: string;
}) {
  const prisma = getPrismaClient();
  const html = volunteerConsentReminderTemplate(params);
  await emailProvider.send({
    to: params.volunteerEmail,
    subject: `Consent form pending for ${params.endeavourTitle}`,
    html
  });
  await prisma.emailLog.create({
    data: {
      toEmail: params.volunteerEmail,
      template: "Volunteer_Consent_Reminder",
      contextJson: params
    }
  });
}
