import { Outlet } from "react-router-dom";
import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/dashboard/AppSidebar";
import { Terminal } from "lucide-react";

export function DashboardLayout() {
  return (
    <SidebarProvider>
      <AppSidebar />
      <div className="flex-1 flex flex-col min-h-screen min-w-0 w-full">
        <header className="h-12 flex items-center border-b border-border/50 px-4 gap-3">
          <SidebarTrigger />
          <div className="flex items-center gap-2 font-mono text-xs text-muted-foreground">
            <Terminal className="w-3 h-3 text-primary" />
            <span>micrologs</span>
          </div>
        </header>
        <main className="flex-1 p-6 w-full">
          <Outlet />
        </main>
      </div>
    </SidebarProvider>
  );
}
