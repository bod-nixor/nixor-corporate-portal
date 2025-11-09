import nodemailer from "nodemailer";
import { env } from "./env";
import { logger } from "./logger";

export interface EmailPayload {
  to: string;
  subject: string;
  html: string;
}

export interface EmailProvider {
  send: (payload: EmailPayload) => Promise<void>;
}

class SMTPEmailProvider implements EmailProvider {
  private transporter = nodemailer.createTransport({
    host: env.SMTP_HOST,
    port: env.SMTP_PORT,
    secure: env.SMTP_PORT === 465,
    auth: {
      user: env.SMTP_USER,
      pass: env.SMTP_PASS
    }
  });

  async send(payload: EmailPayload) {
    await this.transporter.sendMail({
      from: env.SMTP_FROM,
      to: payload.to,
      subject: payload.subject,
      html: payload.html
    });
    logger.info({ to: payload.to }, "email.sent");
  }
}

class ConsoleEmailProvider implements EmailProvider {
  async send(payload: EmailPayload) {
    logger.info({ payload }, "email.mock");
  }
}

export const emailProvider: EmailProvider =
  process.env.NODE_ENV === "production" ? new SMTPEmailProvider() : new ConsoleEmailProvider();
