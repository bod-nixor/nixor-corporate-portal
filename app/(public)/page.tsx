import Link from "next/link";

export default function LandingPage() {
  return (
    <main className="mx-auto flex min-h-screen max-w-4xl flex-col items-center justify-center gap-6 px-6 text-center">
      <h1 className="text-4xl font-bold">Nixor Entities Endeavour Dashboard</h1>
      <p className="text-muted-foreground">
        Centralize volunteer engagement between Nixor entities and students. Please
        sign in with your Nixor College account to continue.
      </p>
      <Link
        className="rounded-md bg-primary px-4 py-2 text-primary-foreground shadow hover:bg-primary/90"
        href="/api/auth/signin"
      >
        Sign in with Google
      </Link>
    </main>
  );
}
