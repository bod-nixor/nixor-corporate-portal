// For server-only code we can rely on the CommonJS export from mjml.
// eslint-disable-next-line @typescript-eslint/no-var-requires
const mjml2html = require("mjml") as (
  markup: string,
  options?: Record<string, unknown>
) => { html: string };

export function parentRegistrationTemplate({
  volunteerName,
  endeavourTitle,
  startAt,
  venue,
  consentUrl
}: {
  volunteerName: string;
  endeavourTitle: string;
  startAt: string;
  venue: string;
  consentUrl: string;
}) {
  const { html } = mjml2html(`
  <mjml>
    <mj-body background-color="#f5f5f5">
      <mj-section>
        <mj-column>
          <mj-text font-size="20px" font-family="Helvetica" font-weight="bold">
            ${volunteerName} registered interest in ${endeavourTitle}
          </mj-text>
          <mj-text>
            Start: ${startAt}<br/>Venue: ${venue}
          </mj-text>
          <mj-button href="${consentUrl}">View consent requirements</mj-button>
        </mj-column>
      </mj-section>
    </mj-body>
  </mjml>`);
  return html;
}

export function volunteerConsentReminderTemplate({
  volunteerName,
  endeavourTitle,
  consentUrl
}: {
  volunteerName: string;
  endeavourTitle: string;
  consentUrl: string;
}) {
  const { html } = mjml2html(`
  <mjml>
    <mj-body>
      <mj-section>
        <mj-column>
          <mj-text>Hi ${volunteerName},</mj-text>
          <mj-text>
            Please complete the consent form for ${endeavourTitle} to secure your spot.
          </mj-text>
          <mj-button href="${consentUrl}">Complete Consent</mj-button>
        </mj-column>
      </mj-section>
    </mj-body>
  </mjml>`);
  return html;
}
