import { useState } from "react";
import { Link } from "react-router-dom";
import {
  BookOpen,
  Code2,
  Database,
  Globe,
  Key,
  LayoutDashboard,
  Link2,
  MapPin,
  Monitor,
  Rocket,
  Server,
  Settings,
  Terminal,
  ChevronRight,
  Menu,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { cn } from "@/lib/utils";
import { Separator } from "@/components/ui/separator";

interface DocSection {
  id: string;
  title: string;
  icon: React.ElementType;
}

interface DocGroup {
  label: string;
  items: DocSection[];
}

const docNav: DocGroup[] = [
  {
    label: "Getting Started",
    items: [
      { id: "introduction", title: "Introduction", icon: BookOpen },
      { id: "quickstart", title: "Quick Start", icon: Rocket },
      { id: "installation", title: "Installation", icon: Server },
    ],
  },
  {
    label: "Core Concepts",
    items: [
      { id: "tracking", title: "Visitor Tracking", icon: Globe },
      { id: "analytics", title: "Analytics API", icon: LayoutDashboard },
      { id: "locations", title: "Location Data", icon: MapPin },
      { id: "devices", title: "Device Breakdown", icon: Monitor },
    ],
  },
  {
    label: "Integration",
    items: [
      { id: "snippet", title: "JS Snippet", icon: Code2 },
      { id: "api-keys", title: "API Keys", icon: Key },
      { id: "links", title: "Link Tracking", icon: Link2 },
    ],
  },
  {
    label: "Advanced",
    items: [
      { id: "self-hosting", title: "Self-Hosting", icon: Database },
      { id: "configuration", title: "Configuration", icon: Settings },
    ],
  },
];

const docContent: Record<string, { title: string; content: string[] }> = {
  introduction: {
    title: "Introduction",
    content: [
      "Micrologs is a self-hostable, plug-and-play analytics engine built with PHP and MySQL. It gives you full ownership of your visitor data with zero third-party dependencies.",
      "Drop a single JS snippet on any website and instantly get visitor tracking, location analytics, device breakdowns, and shareable link tracking — all queryable via a clean REST API.",
      "Unlike cloud analytics platforms, Micrologs runs on your own infrastructure. Your data never leaves your servers.",
    ],
  },
  quickstart: {
    title: "Quick Start",
    content: [
      "Get up and running with Micrologs in under 5 minutes.",
      "1. Clone the repository: `git clone https://github.com/micrologs/micrologs.git`",
      "2. Configure your database credentials in `config.php`",
      "3. Run the setup script: `php setup.php`",
      "4. Generate an API key from the dashboard",
      "5. Add the tracking snippet to your website's `<head>` tag",
      "That's it. Visitor data will start flowing into your dashboard immediately.",
    ],
  },
  installation: {
    title: "Installation",
    content: [
      "Micrologs requires PHP 8.1+ and MySQL 5.7+ (or MariaDB 10.3+).",
      "System requirements:\n- PHP 8.1 or higher\n- MySQL 5.7+ or MariaDB 10.3+\n- Apache or Nginx web server\n- Composer (for dependency management)",
      "Download the latest release from GitHub or install via Composer:\n`composer create-project micrologs/micrologs`",
      "Configure your web server to point the document root to the `/public` directory.",
    ],
  },
  tracking: {
    title: "Visitor Tracking",
    content: [
      "Micrologs automatically captures visitor sessions, page views, referrers, and engagement metrics with a lightweight, privacy-respecting JavaScript snippet.",
      "Each page view records: URL, referrer, timestamp, session ID, viewport size, and optional UTM parameters.",
      "The tracking snippet is under 2KB gzipped and loads asynchronously — zero impact on your page performance.",
    ],
  },
  analytics: {
    title: "Analytics API",
    content: [
      "Query all your analytics data via a clean REST API authenticated with your API keys.",
      "`GET /api/v1/pageviews` — List page views with filtering and pagination\n`GET /api/v1/visitors` — Unique visitor counts and sessions\n`GET /api/v1/referrers` — Top referrer sources\n`GET /api/v1/stats` — Aggregated dashboard stats",
      "All endpoints support date range filtering via `from` and `to` query parameters in ISO 8601 format.",
    ],
  },
  locations: {
    title: "Location Data",
    content: [
      "Micrologs resolves visitor IP addresses to geographic locations using a local GeoIP database — no external API calls required.",
      "Location data includes: country, region, city, and approximate coordinates. All resolution happens server-side and IPs are never stored in raw form.",
    ],
  },
  devices: {
    title: "Device Breakdown",
    content: [
      "Get detailed breakdowns of your visitors' devices including browser, operating system, screen resolution, and device category (desktop, mobile, tablet).",
      "User-agent parsing is done server-side using a regularly updated device detection library.",
    ],
  },
  snippet: {
    title: "JS Snippet",
    content: [
      "Add the Micrologs tracking snippet to your website by including a single `<script>` tag.",
      '```\n<script\n  defer\n  data-domain="yourdomain.com"\n  src="https://your-micrologs-instance.com/js/ml.js"\n></script>\n```',
      "The snippet automatically tracks page views, sessions, and referrers. No additional configuration needed.",
    ],
  },
  "api-keys": {
    title: "API Keys",
    content: [
      "API keys are used to authenticate requests to the Micrologs API. Each key pair consists of a public key (pk_live_*) and a secret key (sk_live_*).",
      "Public keys are safe to expose in client-side code and are domain-restricted. Secret keys should only be used server-side.",
      "Generate and manage API keys from the dashboard under Settings → API Keys.",
    ],
  },
  links: {
    title: "Link Tracking",
    content: [
      "Create shareable tracked links to measure click-through rates across campaigns, emails, and social media.",
      "Each tracked link records: click count, referrer, device info, location, and timestamp. Links can be customized with UTM parameters.",
    ],
  },
  "self-hosting": {
    title: "Self-Hosting",
    content: [
      "Micrologs is designed to be self-hosted. Deploy it on any VPS, dedicated server, or container platform that supports PHP and MySQL.",
      "Recommended hosting:\n- Any VPS with 1GB+ RAM\n- Docker (official image available)\n- Kubernetes via Helm chart",
      "For high-traffic sites (1M+ pageviews/month), we recommend at least 2GB RAM and SSD storage.",
    ],
  },
  configuration: {
    title: "Configuration",
    content: [
      "Micrologs is configured via environment variables or a `config.php` file.",
      "Key settings:\n- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — Database connection\n- `APP_URL` — Your Micrologs instance URL\n- `GEOIP_DB` — Path to GeoIP database file\n- `RETENTION_DAYS` — Data retention period (default: 365)",
    ],
  },
};

function SidebarNav({
  activeSection,
  onSelect,
}: {
  activeSection: string;
  onSelect: (id: string) => void;
}) {
  return (
    <nav className="space-y-6">
      {docNav.map((group) => (
        <div key={group.label}>
          <h4 className="mb-2 px-3 font-mono text-[10px] uppercase tracking-widest text-muted-foreground">
            {group.label}
          </h4>
          <div className="space-y-0.5">
            {group.items.map((item) => (
              <button
                key={item.id}
                onClick={() => onSelect(item.id)}
                className={cn(
                  "flex w-full items-center gap-2.5 rounded-md px-3 py-2 font-mono text-xs transition-colors",
                  activeSection === item.id
                    ? "bg-muted text-primary font-medium"
                    : "text-muted-foreground hover:bg-muted/50 hover:text-foreground"
                )}
              >
                <item.icon className="h-3.5 w-3.5 shrink-0" />
                <span>{item.title}</span>
                {activeSection === item.id && (
                  <ChevronRight className="ml-auto h-3 w-3 text-primary" />
                )}
              </button>
            ))}
          </div>
        </div>
      ))}
    </nav>
  );
}

const DocsPage = () => {
  const [activeSection, setActiveSection] = useState("introduction");
  const [mobileOpen, setMobileOpen] = useState(false);

  const currentDoc = docContent[activeSection] ?? docContent.introduction;

  const handleSelect = (id: string) => {
    setActiveSection(id);
    setMobileOpen(false);
  };

  return (
    <div className="min-h-screen bg-background">
      {/* Top bar */}
      <header className="sticky top-0 z-40 border-b border-border/50 bg-background/80 backdrop-blur-md">
        <div className="flex h-12 items-center gap-3 px-4">
          <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" className="h-7 w-7 md:hidden">
                <Menu className="h-4 w-4" />
              </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-72 bg-background border-border p-4 pt-10">
              <SidebarNav activeSection={activeSection} onSelect={handleSelect} />
            </SheetContent>
          </Sheet>

          <Link to="/" className="flex items-center gap-2 font-mono text-sm font-medium text-foreground">
            <Terminal className="h-4 w-4 text-primary" />
            <span>micrologs</span>
          </Link>
          <Separator orientation="vertical" className="h-4 bg-border" />
          <span className="font-mono text-xs text-muted-foreground">docs</span>

          <div className="ml-auto">
            <Link to="/dashboard">
              <Button variant="ghost" size="sm" className="font-mono text-xs">
                Dashboard
              </Button>
            </Link>
          </div>
        </div>
      </header>

      <div className="flex">
        {/* Desktop sidebar */}
        <aside className="hidden w-64 shrink-0 border-r border-border/50 md:block">
          <div className="sticky top-12 h-[calc(100vh-3rem)] overflow-y-auto p-4">
            <SidebarNav activeSection={activeSection} onSelect={handleSelect} />
          </div>
        </aside>

        {/* Content */}
        <main className="flex-1 min-w-0 px-6 py-10 md:px-12 lg:px-20">
          <div className="max-w-3xl">
            {/* Breadcrumb */}
            <div className="mb-6 flex items-center gap-2 font-mono text-[10px] text-muted-foreground">
              <Link to="/docs" className="hover:text-foreground transition-colors">
                docs
              </Link>
              <ChevronRight className="h-2.5 w-2.5" />
              <span className="text-foreground">{currentDoc.title.toLowerCase()}</span>
            </div>

            <h1 className="mb-2 text-2xl font-light text-foreground">{currentDoc.title}</h1>
            <p className="mb-8 font-mono text-xs text-muted-foreground">
              $ micrologs docs --section {activeSection}
            </p>

            <div className="space-y-6">
              {currentDoc.content.map((block, i) => {
                if (block.startsWith("```")) {
                  const code = block.replace(/```\n?/g, "").trim();
                  return (
                    <pre
                      key={i}
                      className="overflow-x-auto rounded-lg border border-border/50 bg-muted/50 p-4 font-mono text-xs text-foreground"
                    >
                      <code>{code}</code>
                    </pre>
                  );
                }
                if (block.includes("\n")) {
                  return (
                    <div key={i} className="space-y-1.5">
                      {block.split("\n").map((line, j) => {
                        if (line.startsWith("- ")) {
                          return (
                            <div key={j} className="flex gap-2 text-sm text-foreground/80">
                              <span className="font-mono text-primary">›</span>
                              <span>{line.slice(2)}</span>
                            </div>
                          );
                        }
                        return (
                          <p key={j} className="text-sm leading-relaxed text-foreground/80">
                            {line}
                          </p>
                        );
                      })}
                    </div>
                  );
                }
                if (block.match(/^\d\./)) {
                  return (
                    <p key={i} className="text-sm leading-relaxed text-foreground/80 font-mono">
                      {block}
                    </p>
                  );
                }
                return (
                  <p key={i} className="text-sm leading-relaxed text-foreground/80">
                    {block}
                  </p>
                );
              })}
            </div>
          </div>
        </main>
      </div>
    </div>
  );
};

export default DocsPage;
