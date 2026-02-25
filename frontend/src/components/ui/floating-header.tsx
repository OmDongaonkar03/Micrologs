import React from "react";
import { Link } from "react-router-dom";
import { Terminal, MenuIcon } from "lucide-react";
import { Sheet, SheetContent, SheetFooter, SheetTrigger } from "@/components/ui/sheet";
import { Button, buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function FloatingHeader() {
  const [open, setOpen] = React.useState(false);

  const links = [
    { label: "Features", href: "#features" },
    { label: "Docs", href: "/docs" },
    { label: "Pricing", href: "#pricing" },
  ];

  return (
    <header className="fixed top-4 left-1/2 z-50 w-[95%] max-w-5xl -translate-x-1/2">
      <nav className="flex items-center justify-between rounded-xl border border-border/50 bg-background/80 px-4 py-2.5 backdrop-blur-md">
        {/* Brand */}
        <Link
          to="/"
          className="flex items-center gap-2 font-mono text-sm font-medium text-foreground"
        >
          <Terminal className="h-4 w-4 text-primary" />
          <span>micrologs</span>
        </Link>

        {/* Desktop links */}
        <div className="hidden items-center gap-6 lg:flex">
          {links.map((link) => (
            <Link
              key={link.label}
              to={link.href}
              className="font-mono text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
              {link.label}
            </Link>
          ))}
        </div>

        {/* Right side */}
        <div className="flex items-center gap-2">
          <Link
            to="/login"
            className={cn(
              buttonVariants({ variant: "ghost", size: "sm" }),
              "hidden font-mono text-xs lg:inline-flex"
            )}
          >
            Login
          </Link>
          <Link
            to="/register"
            className={cn(
              buttonVariants({ size: "sm" }),
              "hidden font-mono text-xs lg:inline-flex"
            )}
          >
            Get Started
          </Link>

          {/* Mobile menu */}
          <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setOpen(!open)}
                className="lg:hidden"
              >
                <MenuIcon className="h-4 w-4" />
              </Button>
            </SheetTrigger>
            <SheetContent side="right" className="bg-background border-border">
              <div className="flex flex-col gap-4 pt-8">
                {links.map((link) => (
                  <Link
                    key={link.label}
                    to={link.href}
                    onClick={() => setOpen(false)}
                    className="font-mono text-sm text-muted-foreground transition-colors hover:text-foreground"
                  >
                    {link.label}
                  </Link>
                ))}
              </div>
              <SheetFooter className="mt-8 flex flex-col gap-2">
                <Link
                  to="/login"
                  onClick={() => setOpen(false)}
                  className={cn(
                    buttonVariants({ variant: "outline" }),
                    "w-full font-mono text-xs"
                  )}
                >
                  Sign In
                </Link>
                <Link
                  to="/register"
                  onClick={() => setOpen(false)}
                  className={cn(
                    buttonVariants(),
                    "w-full font-mono text-xs"
                  )}
                >
                  Get Started
                </Link>
              </SheetFooter>
            </SheetContent>
          </Sheet>
        </div>
      </nav>
    </header>
  );
}
