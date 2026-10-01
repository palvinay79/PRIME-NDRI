import express from "express";
import http from "http";
import { spawn, execSync, ChildProcess } from "child_process";
import path from "path";

const PORT = 3000;
const PHP_PORT = 8085;

let phpProcess: ChildProcess | null = null;

function ensurePhpInstalled() {
  try {
    execSync("which php", { stdio: "ignore" });
  } catch {
    console.log("[PHP Server] PHP not found. Auto-installing PHP packages...");
    try {
      execSync(
        'DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" php-cli php-sqlite3 php-mbstring php-curl php-xml php-zip',
        { stdio: "inherit" }
      );
      console.log("[PHP Server] PHP installation completed successfully.");
    } catch (err) {
      console.error("[PHP Server] Failed to install PHP:", err);
    }
  }
}

function startPhpServer() {
  ensurePhpInstalled();
  const routerPath = path.join(process.cwd(), "router.php");
  phpProcess = spawn("php", ["-S", `127.0.0.1:${PHP_PORT}`, routerPath], {
    cwd: process.cwd(),
    stdio: "inherit",
  });

  phpProcess.on("error", (err) => {
    console.error("[PHP Server] Error:", err);
  });

  phpProcess.on("exit", (code, signal) => {
    console.log(`[PHP Server] Exited with code ${code}, signal ${signal}`);
    phpProcess = null;
    // Auto-restart if server.ts is still running and not closing
    if (signal !== "SIGINT" && signal !== "SIGTERM") {
      console.log("[PHP Server] Restarting PHP server in 1 second...");
      setTimeout(() => {
        startPhpServer();
      }, 1000);
    }
  });
}

startPhpServer();

const app = express();

// Health check endpoint
app.get("/api/health", (req, res) => {
  res.json({ status: "ok", php_port: PHP_PORT, timestamp: new Date().toISOString() });
});

// Reverse proxy to PHP server
app.use((req, res) => {
  const options: http.RequestOptions = {
    hostname: "127.0.0.1",
    port: PHP_PORT,
    path: req.url,
    method: req.method,
    headers: {
      ...req.headers,
      host: `127.0.0.1:${PHP_PORT}`,
      "x-forwarded-for": req.socket.remoteAddress || "127.0.0.1",
      "x-forwarded-proto": "http",
    },
  };

  const phpReq = http.request(options, (phpRes) => {
    res.writeHead(phpRes.statusCode || 200, phpRes.headers);
    phpRes.pipe(res, { end: true });
  });

  phpReq.on("error", (err) => {
    console.error("[Proxy Error]", err.message);
    res.status(502).send(
      `<!DOCTYPE html><html><head><title>502 Backend Gateway</title><style>body{font-family:sans-serif;padding:2rem;text-align:center;}</style></head><body><h2>Connecting to Project Information Management and Evaluation System PHP Backend...</h2><p>${err.message}</p><p>Please refresh in a moment.</p></body></html>`
    );
  });

  req.pipe(phpReq, { end: true });
});

const server = app.listen(PORT, "0.0.0.0", () => {
  console.log(`Project Information Management and Evaluation System listening on http://0.0.0.0:${PORT}`);
});

function cleanup() {
  if (phpProcess) {
    phpProcess.kill();
    phpProcess = null;
  }
  server.close(() => {
    process.exit(0);
  });
}

process.on("SIGINT", cleanup);
process.on("SIGTERM", cleanup);
