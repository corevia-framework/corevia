"""Local V3 acceptance evidence. Does not publish, send email or call hosted models."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]


def fingerprint():
    raw = subprocess.check_output(["git", "-c", "safe.directory=" + ROOT.as_posix(), "ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd=ROOT)
    digest = hashlib.sha256()
    for name in sorted(n for n in raw.split(b"\0") if n):
        path = ROOT / os.fsdecode(name)
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Missing or symlinked release source.")
        digest.update(name + b"\0" + hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--browsers", action="store_true", help="Also run all three auth storage modes and the AI browser fixture")
    args = parser.parse_args()
    start = fingerprint()
    runtime = tempfile.TemporaryDirectory(prefix="v3-verify-runtime-", dir=ROOT / "storage")
    environment = dict(os.environ, PYTHON=sys.executable, PYTHONPATH=str(ROOT / "sdk/python"), APP_ENV="development", COREVIA_STORAGE=runtime.name)
    environment.pop("COREVIA_DB_TESTS", None)  # Live services are a separate, explicitly isolated gate.
    environment.pop("COREVIA_AUTH_STACK", None)
    commands = [["php", "corevia", "test"], ["php", "tests/lint.php"], [sys.executable, "tools/architecture.py"], [sys.executable, "tests/v3/architecture.py"], ["node", "tools/build-auth-starters.mjs", "--check"], ["node", "tests/v3/smtp.mjs"], ["node", "tests/v3/http-client.mjs"], ["node", "tests/v3/ai-sdk.mjs"], [sys.executable, "-m", "unittest", "discover", "-s", "sdk/python/tests", "-v"], ["node", "tests/features/web.mjs"], ["node", "tests/v3/profiles.mjs"], ["node", "tests/integration.mjs"]]
    runs = [(command, {}) for command in commands]
    if args.browsers:
        runs += [(["node", "tests/v3/auth-browser.mjs"], {"COREVIA_AUTH_SQL": sql, "COREVIA_AUTH_CRYPTO": crypto}) for sql, crypto in [("0", "0"), ("0", "1"), ("1", "1")]]
        runs.append((["node", "tests/v3/ai-browser.mjs"], {}))
    results = []
    for command, extra in runs:
        print("RUN", " ".join(command), flush=True)
        result = subprocess.run(command, cwd=ROOT, env={**environment, **extra})
        results.append({"command": command, "passed": result.returncode == 0})
        if result.returncode:
            break
    unchanged = start == fingerprint()
    passed = len(results) == len(runs) and all(r["passed"] for r in results) and unchanged
    report = {"format": 1, "created_at": int(time.time()), "source_fingerprint": start, "passed": passed, "source_unchanged": unchanged, "browser_matrix": args.browsers, "results": results, "external": {"mysql_postgres_mongodb": False, "real_oauth": False, "real_smtp": False, "github_ci": False}}
    storage = ROOT / "storage"
    storage.mkdir(parents=True, exist_ok=True)
    path = storage / "v3-verification.json"
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(path)
    runtime.cleanup()
    print("PASS local V3 verification" if passed else ("FAIL verification evidence: source changed during checks; rerun on the final source" if not unchanged else "FAIL local V3 verification; see the failed command"), flush=True)
    return 0 if passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
