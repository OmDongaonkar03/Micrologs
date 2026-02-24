import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Terminal } from "lucide-react";

export function LandingNav() {
  return (
    <nav className="fixed top-0 left-0 right-0 z-50 border-b border-border/50 bg-background/80 backdrop-blur-md">
      <div className="container mx-auto px-6 h-14 flex items-center justify-between">
        <Link to="/" className="flex items-center gap-2 font-mono text-sm font-medium text-foreground">
          <Terminal className="w-4 h-4 text-primary" />
          <span>micrologs</span>
        </Link>
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="sm" className="font-mono text-xs" asChild>
            <Link to="/login">Login</Link>
          </Button>
          <Button size="sm" className="font-mono text-xs" asChild>
            <Link to="/register">Sign Up</Link>
          </Button>
        </div>
      </div>
    </nav>
  );
}
