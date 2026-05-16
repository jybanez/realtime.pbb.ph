import fs from "node:fs";
import path from "node:path";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { fileURLToPath } from "node:url";
import { startStaticServer } from "../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs";

const execFileAsync = promisify(execFile);
const browserCandidates = [
  "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
  "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
  "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe",
];

const browserPath = browserCandidates.find((candidate) => {
  try {
    return candidate && fs.existsSync(candidate);
  } catch {
    return false;
  }
});

if (!browserPath) {
  console.error("Sandbox smoke test failed: no supported browser executable found.");
  process.exit(1);
}

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(currentDir, "..");
const server = await startStaticServer({
  rootDir: path.join(repoRoot, "public"),
  port: 41751,
});

try {
  const smokeUrl = `${server.origin}/tests/sandbox.shell.smoke.html`;
  const { stdout, stderr } = await execFileAsync(browserPath, [
    "--headless=new",
    "--disable-gpu",
    "--virtual-time-budget=12000",
    "--dump-dom",
    smokeUrl,
  ], { maxBuffer: 1024 * 1024 * 8 });

  const output = `${stdout}\n${stderr}`;
  if (/data-status="pass"/.test(output) && /\bPASS\b/.test(output)) {
    console.log("Sandbox shell smoke test passed.");
  } else {
    console.error("Sandbox shell smoke test failed.");
    console.error(output);
    process.exitCode = 1;
  }
} finally {
  await server.close();
}
