<?php
/*
    ===============================================================
        Micrologs — Setup Wizard
        File : setup.php (project root)
        Desc : One-time browser setup. Connects to DB, imports
               schema, creates your first project, shows keys.
               DELETE THIS FILE after setup is complete.
    ===============================================================
*/

// ── Safety check — refuse to run if env.php is still the example ──
$envPath  = __DIR__ . "/authorization/env.php";
$envReady = file_exists($envPath);

// ── Only load env if it exists ────────────────────────────────────
if ($envReady) {
    @include_once $envPath;
    $envReady = defined("DB_HOST") && DB_HOST !== "your_db_host";
}

// ── Handle POST ───────────────────────────────────────────────────
$result = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && $envReady) {
    $action = $_POST["action"] ?? "";

    if ($action === "check_db") {
        $result = checkDb();
    } elseif ($action === "import_schema") {
        $result = importSchema();
    } elseif ($action === "create_project") {
        $name    = trim($_POST["project_name"]    ?? "");
        $domains = trim($_POST["allowed_domains"] ?? "");
        $result  = createProject($name, $domains);
    }

    header("Content-Type: application/json");
    echo json_encode($result);
    exit();
}

// ── Functions ─────────────────────────────────────────────────────

function getConn() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        return null;
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

function checkDb(): array {
    $conn = getConn();
    if (!$conn) {
        return ["ok" => false, "message" => "Could not connect to database. Check DB_HOST, DB_USER, DB_PASS, DB_NAME in authorization/env.php."];
    }

    // Check which tables already exist
    $existing = [];
    $res = $conn->query("SHOW TABLES");
    while ($row = $res->fetch_row()) {
        $existing[] = $row[0];
    }

    $required = ["projects", "visitors", "sessions", "pageviews", "devices",
                 "locations", "error_groups", "error_events", "audit_logs",
                 "tracked_links", "link_clicks"];

    $missing = array_values(array_diff($required, $existing));

    $conn->close();

    return [
        "ok"      => true,
        "message" => "Database connection successful.",
        "missing" => $missing,
        "ready"   => empty($missing),
    ];
}

function importSchema(): array {
    $conn = getConn();
    if (!$conn) {
        return ["ok" => false, "message" => "Database connection failed."];
    }

    $schemaPath = __DIR__ . "/schema.sql";
    if (!file_exists($schemaPath)) {
        return ["ok" => false, "message" => "schema.sql not found in project root."];
    }

    $sql = file_get_contents($schemaPath);

    // Split on ; but not inside strings — simple approach that works for this schema
    $conn->multi_query($sql);

    $errors = [];
    do {
        if ($conn->error) {
            // Ignore "table already exists" — safe to re-run
            if (strpos($conn->error, "already exists") === false) {
                $errors[] = $conn->error;
            }
        }
    } while ($conn->next_result());

    $conn->close();

    if (!empty($errors)) {
        return ["ok" => false, "message" => "Schema import failed: " . implode("; ", $errors)];
    }

    return ["ok" => true, "message" => "Schema imported successfully. All tables created."];
}

function createProject(string $name, string $domainsRaw): array {
    if (empty($name)) {
        return ["ok" => false, "message" => "Project name is required."];
    }

    // Parse domains — one per line or comma-separated
    $parts = preg_split('/[\n,]+/', $domainsRaw);
    $domains = [];
    foreach ($parts as $d) {
        $d = trim($d);
        $d = preg_replace("#^https?://#", "", $d);
        $d = rtrim($d, "/");
        $d = strtolower($d);
        if (!empty($d)) {
            $domains[] = $d;
        }
    }
    $domains = array_values(array_unique($domains));

    if (empty($domains)) {
        return ["ok" => false, "message" => "At least one allowed domain is required."];
    }

    $conn = getConn();
    if (!$conn) {
        return ["ok" => false, "message" => "Database connection failed."];
    }

    $secretKey  = bin2hex(random_bytes(32));
    $publicKey  = bin2hex(random_bytes(16));
    $domainsStr = implode(",", $domains);

    $stmt = $conn->prepare(
        "INSERT INTO projects (name, secret_key, public_key, allowed_domains) VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        $conn->close();
        return ["ok" => false, "message" => "Failed to prepare statement: " . $conn->error];
    }

    $stmt->bind_param("ssss", $name, $secretKey, $publicKey, $domainsStr);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        return ["ok" => false, "message" => "Failed to create project: " . $err];
    }

    $id = (int) $conn->insert_id;
    $stmt->close();
    $conn->close();

    $appUrl = defined("APP_URL") ? rtrim(APP_URL, "/") : "https://yourdomain.com";

    return [
        "ok"         => true,
        "message"    => "Project created successfully.",
        "id"         => $id,
        "name"       => $name,
        "domains"    => $domains,
        "secret_key" => $secretKey,
        "public_key" => $publicKey,
        "app_url"    => $appUrl,
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Micrologs — Setup</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: #0f0f0f;
    color: #e0e0e0;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 48px 16px;
  }

  .wrap { width: 100%; max-width: 600px; }

  .logo {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #555;
    margin-bottom: 32px;
  }

  h1 { font-size: 24px; font-weight: 600; color: #fff; margin-bottom: 6px; }
  .sub { font-size: 14px; color: #666; margin-bottom: 40px; }

  .step {
    background: #161616;
    border: 1px solid #222;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 16px;
    transition: border-color .2s;
  }
  .step.active  { border-color: #333; }
  .step.done    { border-color: #1a3a1a; background: #111811; }
  .step.locked  { opacity: .4; pointer-events: none; }

  .step-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
  }

  .step-num {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #222;
    border: 1px solid #333;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 600; color: #888;
    flex-shrink: 0;
  }
  .step.done .step-num { background: #1a4a1a; border-color: #2a6a2a; color: #4caf50; }

  .step-title { font-size: 15px; font-weight: 600; color: #ccc; }
  .step.done .step-title { color: #4caf50; }

  label { display: block; font-size: 13px; color: #888; margin-bottom: 6px; }

  input[type=text], textarea {
    width: 100%;
    background: #111;
    border: 1px solid #2a2a2a;
    border-radius: 6px;
    color: #e0e0e0;
    font-size: 14px;
    padding: 10px 12px;
    outline: none;
    transition: border-color .15s;
    font-family: inherit;
  }
  input[type=text]:focus, textarea:focus { border-color: #444; }
  textarea { resize: vertical; min-height: 80px; }

  .hint { font-size: 12px; color: #555; margin-top: 6px; }

  button {
    margin-top: 16px;
    padding: 10px 20px;
    background: #1e1e1e;
    border: 1px solid #333;
    border-radius: 6px;
    color: #ccc;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
  }
  button:hover  { background: #252525; border-color: #444; color: #fff; }
  button:active { background: #1a1a1a; }

  .msg {
    margin-top: 12px;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 13px;
    display: none;
  }
  .msg.ok  { background: #0d1f0d; border: 1px solid #1a4a1a; color: #5cb85c; }
  .msg.err { background: #1f0d0d; border: 1px solid #4a1a1a; color: #e57373; }
  .msg.show { display: block; }

  .keys { margin-top: 20px; display: none; }
  .keys.show { display: block; }

  .key-block { margin-bottom: 16px; }
  .key-label { font-size: 12px; color: #666; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .06em; }

  .key-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .key-val {
    flex: 1;
    background: #111;
    border: 1px solid #222;
    border-radius: 6px;
    padding: 10px 12px;
    font-family: "SF Mono", "Fira Code", monospace;
    font-size: 12px;
    color: #a0d0a0;
    word-break: break-all;
  }

  .copy-btn {
    margin-top: 0;
    padding: 9px 14px;
    font-size: 12px;
    flex-shrink: 0;
  }

  .snippet-block {
    margin-top: 20px;
    background: #111;
    border: 1px solid #222;
    border-radius: 6px;
    padding: 16px;
  }
  .snippet-label { font-size: 12px; color: #666; margin-bottom: 10px; text-transform: uppercase; letter-spacing: .06em; }
  pre {
    font-family: "SF Mono", "Fira Code", monospace;
    font-size: 12px;
    color: #a0c0ff;
    white-space: pre-wrap;
    word-break: break-all;
  }

  .warning {
    margin-top: 32px;
    padding: 14px 16px;
    background: #1f1200;
    border: 1px solid #4a3000;
    border-radius: 8px;
    font-size: 13px;
    color: #cc9933;
    display: none;
  }
  .warning.show { display: block; }
  .warning strong { color: #ffaa44; }

  .env-warning {
    padding: 16px;
    background: #1f1200;
    border: 1px solid #4a3000;
    border-radius: 8px;
    font-size: 14px;
    color: #cc9933;
    margin-bottom: 24px;
  }
  .env-warning strong { color: #ffaa44; display: block; margin-bottom: 6px; }
  code {
    background: #1a1a1a;
    border: 1px solid #2a2a2a;
    border-radius: 4px;
    padding: 1px 6px;
    font-family: "SF Mono", "Fira Code", monospace;
    font-size: 12px;
    color: #a0c0ff;
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">Micrologs</div>
  <h1>Setup Wizard</h1>
  <p class="sub">Get up and running in three steps.</p>

  <?php if (!$envReady): ?>
  <div class="env-warning">
    <strong>⚠ env.php not configured yet</strong>
    Copy <code>authorization/.env.example.php</code> to <code>authorization/env.php</code>
    and fill in your database credentials and APP_URL before continuing.
  </div>
  <?php endif; ?>

  <!-- Step 1: DB Check -->
  <div class="step <?= $envReady ? 'active' : 'locked' ?>" id="step1">
    <div class="step-header">
      <div class="step-num" id="num1">1</div>
      <div class="step-title">Test database connection</div>
    </div>
    <p style="font-size:13px;color:#666;margin-bottom:16px;">
      Verifies your database credentials and checks which tables exist.
    </p>
    <button onclick="checkDb()">Test Connection</button>
    <div class="msg" id="msg1"></div>
  </div>

  <!-- Step 2: Import Schema -->
  <div class="step locked" id="step2">
    <div class="step-header">
      <div class="step-num" id="num2">2</div>
      <div class="step-title">Import schema</div>
    </div>
    <p style="font-size:13px;color:#666;margin-bottom:16px;">
      Creates all required tables. Safe to run on an existing database — existing tables are left untouched.
    </p>
    <button onclick="importSchema()">Import Schema</button>
    <div class="msg" id="msg2"></div>
  </div>

  <!-- Step 3: Create Project -->
  <div class="step locked" id="step3">
    <div class="step-header">
      <div class="step-num" id="num3">3</div>
      <div class="step-title">Create your first project</div>
    </div>

    <div style="margin-bottom:14px;">
      <label for="project_name">Project name</label>
      <input type="text" id="project_name" placeholder="My Website" maxlength="100">
    </div>

    <div>
      <label for="allowed_domains">Allowed domains</label>
      <textarea id="allowed_domains" placeholder="mywebsite.com&#10;staging.mywebsite.com&#10;localhost"></textarea>
      <div class="hint">One per line. No http:// needed. The snippet will only work on these domains.</div>
    </div>

    <button onclick="createProject()">Create Project</button>
    <div class="msg" id="msg3"></div>

    <!-- Keys output -->
    <div class="keys" id="keys">
      <div style="margin-bottom:20px;padding-top:20px;border-top:1px solid #222;">
        <div style="font-size:13px;font-weight:600;color:#fff;margin-bottom:4px;">Your project is ready.</div>
        <div style="font-size:13px;color:#666;">Store the secret key now — it will not be shown again.</div>
      </div>

      <div class="key-block">
        <div class="key-label">Secret Key — server-side only, never expose in frontend</div>
        <div class="key-row">
          <div class="key-val" id="secret_key"></div>
          <button class="copy-btn" onclick="copy('secret_key', this)">Copy</button>
        </div>
      </div>

      <div class="key-block">
        <div class="key-label">Public Key — safe for the JS snippet</div>
        <div class="key-row">
          <div class="key-val" id="public_key"></div>
          <button class="copy-btn" onclick="copy('public_key', this)">Copy</button>
        </div>
      </div>

      <div class="snippet-block">
        <div class="snippet-label">Tracking snippet — paste before &lt;/body&gt;</div>
        <pre id="snippet"></pre>
        <button class="copy-btn" style="margin-top:12px;" onclick="copySnippet(this)">Copy snippet</button>
      </div>
    </div>
  </div>

  <div class="warning" id="done-warning">
    <strong>⚠ Delete setup.php now.</strong><br>
    This file has no authentication. Anyone who can reach it can create projects in your database.
    Remove <code>setup.php</code> from your server before going live.
  </div>
</div>

<script>
async function post(action, body = {}) {
  const form = new FormData();
  form.append("action", action);
  for (const [k, v] of Object.entries(body)) form.append(k, v);
  const res = await fetch(window.location.href, { method: "POST", body: form });
  return res.json();
}

function showMsg(id, ok, text) {
  const el = document.getElementById(id);
  el.className = "msg show " + (ok ? "ok" : "err");
  el.textContent = text;
}

function markDone(stepId, numId) {
  document.getElementById(stepId).classList.remove("active");
  document.getElementById(stepId).classList.add("done");
  document.getElementById(numId).textContent = "✓";
}

function unlock(stepId) {
  document.getElementById(stepId).classList.remove("locked");
  document.getElementById(stepId).classList.add("active");
}

async function checkDb() {
  showMsg("msg1", true, "Connecting…");
  const r = await post("check_db");
  if (!r.ok) {
    showMsg("msg1", false, r.message);
    return;
  }
  if (r.ready) {
    showMsg("msg1", true, r.message + " All tables already exist.");
    markDone("step1", "num1");
    unlock("step2");
    // If schema already there, skip step 2
    markDone("step2", "num2");
    unlock("step3");
  } else {
    const missing = r.missing.join(", ");
    showMsg("msg1", true, r.message + " Missing tables: " + missing + ". Import schema to create them.");
    markDone("step1", "num1");
    unlock("step2");
  }
}

async function importSchema() {
  showMsg("msg2", true, "Importing…");
  const r = await post("import_schema");
  showMsg("msg2", r.ok, r.message);
  if (r.ok) {
    markDone("step2", "num2");
    unlock("step3");
  }
}

async function createProject() {
  const name    = document.getElementById("project_name").value.trim();
  const domains = document.getElementById("allowed_domains").value.trim();
  if (!name) { showMsg("msg3", false, "Project name is required."); return; }
  if (!domains) { showMsg("msg3", false, "At least one domain is required."); return; }

  showMsg("msg3", true, "Creating…");
  const r = await post("create_project", { project_name: name, allowed_domains: domains });

  if (!r.ok) { showMsg("msg3", false, r.message); return; }

  showMsg("msg3", true, r.message);
  markDone("step3", "num3");

  document.getElementById("secret_key").textContent = r.secret_key;
  document.getElementById("public_key").textContent  = r.public_key;

  const snippet = `<script\n  src="${r.app_url}/snippet/micrologs.js"\n  data-public-key="${r.public_key}"\n  data-environment="production"\n  async>\n<\/script>`;
  document.getElementById("snippet").textContent = snippet;

  document.getElementById("keys").classList.add("show");
  document.getElementById("done-warning").classList.add("show");
}

function copy(id, btn) {
  const text = document.getElementById(id).textContent;
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = "Copied";
    setTimeout(() => btn.textContent = "Copy", 1500);
  });
}

function copySnippet(btn) {
  const text = document.getElementById("snippet").textContent;
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = "Copied";
    setTimeout(() => btn.textContent = "Copy snippet", 1500);
  });
}
</script>
</body>
</html>