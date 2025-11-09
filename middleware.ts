import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { createSecureHeaders } from "./src/lib/security-headers";

const protectedPaths = ["/dashboard", "/hr", "/settings"];

export function middleware(request: NextRequest) {
  const response = NextResponse.next();
  for (const header of createSecureHeaders()) {
    response.headers.set(header.key, header.value);
  }

  if (protectedPaths.some((path) => request.nextUrl.pathname.startsWith(path))) {
    // rely on NextAuth middleware from app router to guard server-side; fallback redirect
    const sessionToken = request.cookies.get("next-auth.session-token") ?? request.cookies.get("__Secure-next-auth.session-token");
    if (!sessionToken) {
      return NextResponse.redirect(new URL("/", request.url));
    }
  }

  return response;
}

export const config = {
  matcher: ["/((?!_next|api/auth|static|favicon.ico).*)"]
};
