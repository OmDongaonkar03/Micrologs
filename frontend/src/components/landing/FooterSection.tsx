import { Footer } from "@/components/ui/modem-animated-footer";
import { Twitter, Github, Mail, Terminal } from "lucide-react";

const socialLinks = [
  { icon: <Github className="w-5 h-5" />, href: "https://github.com", label: "GitHub" },
  { icon: <Twitter className="w-5 h-5" />, href: "https://twitter.com", label: "Twitter" },
  { icon: <Mail className="w-5 h-5" />, href: "mailto:hello@micrologs.dev", label: "Email" },
];

const navLinks = [
  { label: "Features", href: "/#features" },
  { label: "Docs", href: "/#snippet" },
  { label: "Login", href: "/login" },
  { label: "Register", href: "/register" },
];

export function FooterSection() {
  return (
    <Footer
      brandName="micrologs"
      brandDescription="Self-hostable, open source analytics engine. Own your data, respect your users."
      socialLinks={socialLinks}
      navLinks={navLinks}
      creatorName="micrologs"
      creatorUrl="https://github.com"
      brandIcon={<Terminal className="w-5 h-5" />}
    />
  );
}
