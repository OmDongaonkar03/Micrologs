const PlaceholderPage = ({ title }: { title: string }) => (
  <div className="flex items-center justify-center h-64">
    <div className="text-center">
      <p className="font-mono text-xs text-muted-foreground mb-2">$ micrologs {title.toLowerCase()}</p>
      <h1 className="text-lg font-light text-foreground">{title}</h1>
      <p className="text-xs text-muted-foreground mt-2">Coming soon.</p>
    </div>
  </div>
);

export default PlaceholderPage;
