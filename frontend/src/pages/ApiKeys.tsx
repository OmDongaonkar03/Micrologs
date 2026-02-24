import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Copy, Plus, Trash2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast";

interface ApiKey {
  id: string;
  publicKey: string;
  secretKey: string;
  domain: string;
  createdAt: string;
}

function generateKey(prefix: string) {
  const chars = "abcdefghijklmnopqrstuvwxyz0123456789";
  let key = prefix;
  for (let i = 0; i < 24; i++) key += chars[Math.floor(Math.random() * chars.length)];
  return key;
}

const ApiKeysPage = () => {
  const [keys, setKeys] = useState<ApiKey[]>([
    {
      id: "1",
      publicKey: "pk_live_demo1234567890abcdef",
      secretKey: "sk_live_demo1234567890abcdef",
      domain: "example.com",
      createdAt: "2025-02-20",
    },
  ]);
  const [domain, setDomain] = useState("");
  const [open, setOpen] = useState(false);
  const { toast } = useToast();

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast({ title: "Copied", description: "Copied to clipboard." });
  };

  const createKey = () => {
    if (!domain.trim()) return;
    const newKey: ApiKey = {
      id: Date.now().toString(),
      publicKey: generateKey("pk_live_"),
      secretKey: generateKey("sk_live_"),
      domain: domain.trim(),
      createdAt: new Date().toISOString().split("T")[0],
    };
    setKeys((prev) => [...prev, newKey]);
    setDomain("");
    setOpen(false);
    toast({ title: "API Key Created", description: `Key created for ${newKey.domain}` });
  };

  const deleteKey = (id: string) => {
    setKeys((prev) => prev.filter((k) => k.id !== id));
    toast({ title: "Deleted", description: "API key removed." });
  };

  return (
    <div className="max-w-4xl">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-light text-foreground">API Keys</h1>
          <p className="text-xs font-mono text-muted-foreground mt-1">
            $ micrologs keys --list
          </p>
        </div>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button size="sm" className="font-mono text-xs">
              <Plus className="w-3 h-3 mr-1" />
              New Key
            </Button>
          </DialogTrigger>
          <DialogContent className="bg-card border-border">
            <DialogHeader>
              <DialogTitle className="font-light text-base">Create API Key</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 mt-2">
              <div className="space-y-2">
                <Label className="text-xs font-mono">Allowed Domain</Label>
                <Input
                  placeholder="example.com"
                  value={domain}
                  onChange={(e) => setDomain(e.target.value)}
                  className="font-mono text-sm bg-muted border-border"
                />
              </div>
              <Button onClick={createKey} className="w-full font-mono text-xs">
                Generate Keys →
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      <Card className="bg-card border-border/50">
        <CardHeader className="pb-3">
          <CardTitle className="text-xs font-mono text-muted-foreground font-normal">
            Active Keys
          </CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow className="border-border/50 hover:bg-transparent">
                <TableHead className="font-mono text-xs">Domain</TableHead>
                <TableHead className="font-mono text-xs">Public Key</TableHead>
                <TableHead className="font-mono text-xs">Secret Key</TableHead>
                <TableHead className="font-mono text-xs">Created</TableHead>
                <TableHead className="font-mono text-xs w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {keys.map((key) => (
                <TableRow key={key.id} className="border-border/50">
                  <TableCell className="font-mono text-xs text-foreground">
                    {key.domain}
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1">
                      <code className="text-xs text-muted-foreground truncate max-w-[160px]">
                        {key.publicKey}
                      </code>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6"
                        onClick={() => copyToClipboard(key.publicKey)}
                      >
                        <Copy className="w-3 h-3" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1">
                      <code className="text-xs text-muted-foreground truncate max-w-[160px]">
                        {key.secretKey.substring(0, 12)}••••••••
                      </code>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6"
                        onClick={() => copyToClipboard(key.secretKey)}
                      >
                        <Copy className="w-3 h-3" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell className="font-mono text-xs text-muted-foreground">
                    {key.createdAt}
                  </TableCell>
                  <TableCell>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-6 w-6 text-destructive hover:text-destructive"
                      onClick={() => deleteKey(key.id)}
                    >
                      <Trash2 className="w-3 h-3" />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
              {keys.length === 0 && (
                <TableRow>
                  <TableCell colSpan={5} className="text-center py-8 text-xs text-muted-foreground font-mono">
                    No API keys yet. Create one to get started.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
};

export default ApiKeysPage;
