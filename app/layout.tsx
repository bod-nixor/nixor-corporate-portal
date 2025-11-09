import "../styles/globals.css";
import { ReactNode } from "react";
import { Inter } from "next/font/google";
import { ThemeProvider } from "../src/components/theme-provider";

const inter = Inter({ subsets: ["latin"] });

export const metadata = {
  title: "Nixor Entities Endeavour Dashboard",
  description: "Connect volunteers with Nixor entities and endeavours"
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body className={`${inter.className} min-h-screen bg-background text-foreground`}>
        <ThemeProvider attribute="class" defaultTheme="system" enableSystem>
          {children}
        </ThemeProvider>
      </body>
    </html>
  );
}
