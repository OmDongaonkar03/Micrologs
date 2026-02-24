import { BackgroundPaths } from "@/components/ui/background-paths";
import { FeaturesSection } from "@/components/landing/FeaturesSection";
import { SnippetSection } from "@/components/landing/SnippetSection";
import { FooterSection } from "@/components/landing/FooterSection";
import { LandingNav } from "@/components/landing/LandingNav";

const Index = () => {
  return (
    <div className="min-h-screen bg-background">
      <LandingNav />
      <BackgroundPaths
        title="Micrologs"
        subtitle="Self-hostable analytics engine. Own your data."
      />
      <FeaturesSection />
      <SnippetSection />
      <FooterSection />
    </div>
  );
};

export default Index;
