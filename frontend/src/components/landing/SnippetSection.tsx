import { motion } from "framer-motion";

const snippet = `<script src="https://your-server.com/ml.js"
  data-key="pk_live_abc123"
  async>
</script>`;

export function SnippetSection() {
  return (
    <section className="py-24 bg-muted/30 border-t border-border/50">
      <div className="container mx-auto px-6">
        <motion.div
          initial={{ opacity: 0, y: 30 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ duration: 0.8 }}
          className="max-w-2xl mx-auto text-center"
        >
          <p className="font-mono text-xs text-primary tracking-widest uppercase mb-3">
            $ cat install.sh
          </p>
          <h2 className="text-3xl font-light text-foreground mb-8">
            One snippet. That's it.
          </h2>

          <div className="bg-card border border-border rounded-lg p-6 text-left font-mono text-sm">
            <div className="flex items-center gap-2 mb-4">
              <span className="w-3 h-3 rounded-full bg-destructive/60" />
              <span className="w-3 h-3 rounded-full bg-terminal-amber/60" />
              <span className="w-3 h-3 rounded-full bg-primary/60" />
            </div>
            <pre className="text-foreground/80 text-xs leading-relaxed overflow-x-auto">
              <code>{snippet}</code>
            </pre>
          </div>

          <p className="mt-6 text-muted-foreground text-sm font-light">
            Drop it on any site. Start collecting data in seconds.
          </p>
        </motion.div>
      </div>
    </section>
  );
}
