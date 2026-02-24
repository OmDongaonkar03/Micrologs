import { Check } from "lucide-react";
import { Badge } from "@/components/ui/badge";

interface FeatureItem {
  title: string;
  description: string;
}

interface FeatureProps {
  badge?: string;
  title?: string;
  description?: string;
  features?: FeatureItem[];
}

function Feature({
  badge = "Platform",
  title = "Something new!",
  description = "Managing a small business today is already tough.",
  features = [
    { title: "Easy to use", description: "We've made it easy to use and understand." },
    { title: "Fast and reliable", description: "We've made it fast and reliable." },
    { title: "Beautiful and modern", description: "We've made it beautiful and modern." },
    { title: "Easy to use", description: "We've made it easy to use and understand." },
    { title: "Fast and reliable", description: "We've made it fast and reliable." },
    { title: "Beautiful and modern", description: "We've made it beautiful and modern." },
  ],
}: FeatureProps) {
  return (
    <div className="w-full py-20 lg:py-40">
      <div className="container mx-auto px-6">
        <div className="flex flex-col gap-10">
          <div className="flex gap-4 flex-col items-start">
            <div>
              <Badge>{badge}</Badge>
            </div>
            <div className="flex gap-2 flex-col">
              <h2 className="text-3xl md:text-5xl tracking-tighter lg:max-w-xl font-regular text-left text-foreground">
                {title}
              </h2>
              <p className="text-lg lg:max-w-sm leading-relaxed tracking-tight text-muted-foreground text-left">
                {description}
              </p>
            </div>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
            {features.map((feature, index) => (
              <div key={index} className="flex flex-row gap-6 w-full items-start">
                <Check className="w-4 h-4 mt-2 text-primary shrink-0" />
                <div className="flex flex-col gap-1">
                  <p className="text-foreground text-md font-medium">{feature.title}</p>
                  <p className="text-muted-foreground text-sm">{feature.description}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

export { Feature };
