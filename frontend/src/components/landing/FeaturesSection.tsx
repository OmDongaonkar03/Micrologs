import { Feature } from "@/components/ui/feature-with-advantages";

const features = [
  {
    title: "Visitor Tracking",
    description: "Page views, sessions, referrers. Everything you need, nothing you don't.",
  },
  {
    title: "Location Analytics",
    description: "Country, city, region breakdowns. Know where your users come from.",
  },
  {
    title: "Device Breakdowns",
    description: "Browser, OS, screen size. Understand your audience's tech stack.",
  },
  {
    title: "Shareable Links",
    description: "Track link clicks with custom slugs. UTM-compatible campaign tracking.",
  },
  {
    title: "Clean REST API",
    description: "Query everything programmatically. Embed analytics into your own platform.",
  },
  {
    title: "Self-Hostable",
    description: "PHP + MySQL. Deploy on your own servers. Zero third-party dependencies.",
  },
];

export function FeaturesSection() {
  return (
    <section id="features" className="border-t border-border/50">
      <Feature
        badge="$ cat features.log"
        title="Everything you need"
        description="Lightweight analytics that respects your users and your infrastructure."
        features={features}
      />
    </section>
  );
}
