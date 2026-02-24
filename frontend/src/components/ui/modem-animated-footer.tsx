import React from "react";
import { Link } from "react-router-dom";
import { Terminal } from "lucide-react";
import { cn } from "@/lib/utils";

interface FooterLink {
  label: string;
  href: string;
}

interface SocialLink {
  icon: React.ReactNode;
  href: string;
  label: string;
}

interface FooterProps {
  brandName?: string;
  brandDescription?: string;
  socialLinks?: SocialLink[];
  navLinks?: FooterLink[];
  creatorName?: string;
  creatorUrl?: string;
  brandIcon?: React.ReactNode;
  className?: string;
}

export const Footer = ({
  brandName = "YourBrand",
  brandDescription = "Your description here",
  socialLinks = [],
  navLinks = [],
  creatorName,
  creatorUrl,
  brandIcon,
  className,
}: FooterProps) => {
  return (
    <footer className={cn("relative w-full overflow-hidden bg-background border-t border-border/50", className)}>
      <div className="relative z-10">
        <div className="container mx-auto px-6">
          <div className="py-16">
            <div className="flex flex-col items-center text-center gap-8">
              <div className="flex flex-col gap-4">
                <div className="flex items-center justify-center gap-2">
                  <span className="font-mono text-lg font-medium text-foreground">
                    {brandName}
                  </span>
                </div>
                <p className="text-sm text-muted-foreground max-w-md">
                  {brandDescription}
                </p>
              </div>

              {socialLinks.length > 0 && (
                <div className="flex gap-4">
                  {socialLinks.map((link, index) => (
                    <a
                      key={index}
                      href={link.href}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="p-2 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted/50 transition-colors"
                      aria-label={link.label}
                    >
                      {link.icon}
                    </a>
                  ))}
                </div>
              )}

              {navLinks.length > 0 && (
                <nav className="flex flex-wrap justify-center gap-6">
                  {navLinks.map((link, index) => (
                    <Link
                      key={index}
                      to={link.href}
                      className="text-sm text-muted-foreground hover:text-foreground transition-colors font-mono"
                    >
                      {link.label}
                    </Link>
                  ))}
                </nav>
              )}
            </div>
          </div>

          <div className="flex flex-col sm:flex-row items-center justify-between gap-4 py-6 border-t border-border/50">
            <p className="text-xs text-muted-foreground font-mono">
              ©{new Date().getFullYear()} {brandName}. All rights reserved.
            </p>
            {creatorName && creatorUrl && (
              <p className="text-xs text-muted-foreground">
                <a
                  href={creatorUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:text-foreground transition-colors"
                >
                  Crafted by {creatorName}
                </a>
              </p>
            )}
          </div>
        </div>

        {/* Large background text */}
        <div className="absolute inset-0 flex items-center justify-center pointer-events-none select-none overflow-hidden">
          <span className="text-[12vw] font-mono font-bold text-muted/20 whitespace-nowrap">
            {brandName.toUpperCase()}
          </span>
        </div>

        {/* Bottom logo */}
        <div className="flex justify-center pb-8">
          <div className="p-3 rounded-full border border-border/50 text-muted-foreground">
            {brandIcon || <Terminal className="w-5 h-5" />}
          </div>
        </div>

        {/* Bottom line */}
        <div className="h-px bg-gradient-to-r from-transparent via-primary/30 to-transparent" />

        {/* Bottom shadow */}
        <div className="h-1 bg-gradient-to-t from-primary/5 to-transparent" />
      </div>
    </footer>
  );
};
