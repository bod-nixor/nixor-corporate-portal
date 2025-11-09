import NextAuth, { type NextAuthOptions } from "next-auth";
import GoogleProvider from "next-auth/providers/google";
import { PrismaAdapter } from "@next-auth/prisma-adapter";
import { prisma } from "./prisma";
import { env } from "./env";
import { isEmailAllowed } from "./email-domain";
import { audit } from "./audit";

export const authOptions: NextAuthOptions = {
  adapter: PrismaAdapter(prisma),
  providers: [
    GoogleProvider({
      clientId: env.GOOGLE_CLIENT_ID,
      clientSecret: env.GOOGLE_CLIENT_SECRET,
      authorization: {
        params: { prompt: "select_account" }
      }
    })
  ],
  session: {
    strategy: "database",
    maxAge: 60 * 60 * 8,
    updateAge: 60 * 60
  },
  pages: {
    signIn: "/"
  },
  callbacks: {
    async signIn({ user, account, profile }) {
      const email = user.email ?? profile?.email;
      if (!email || !isEmailAllowed(email)) {
        throw new Error("Please sign in using your @nixorcollege.edu.pk email address.");
      }
      if (account?.providerAccountId) {
        await prisma.user.update({
          where: { id: user.id },
          data: {
            googleId: account.providerAccountId
          }
        }).catch((error) => {
          console.error("Failed to sync googleId", error);
        });
      }
      return true;
    },
    async session({ session, user }) {
      if (session.user) {
        session.user.id = user.id;
        session.user.role = user.role;
        session.user.studentId = user.studentId;
      }
      return session;
    }
  },
  events: {
    async signIn(message) {
      await audit({
        actorUserId: message.user.id,
        action: "auth.sign_in",
        metadata: { email: message.user.email }
      });
    }
  }
};

export const { handlers: authHandlers, auth, signIn, signOut } = NextAuth(authOptions);
