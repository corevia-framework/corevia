"""Scan reachable Git history for private-key/provider-token patterns without printing matches."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
git = ['git', '-c', 'safe.directory=' + root.as_posix()]
commits = subprocess.check_output(git + ['rev-list', '--all'], cwd=root, text=True).splitlines()
pattern = r'(gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|sk-[A-Za-z0-9_-]{40,}|AKIA[A-Z0-9]{16}|-----BEGIN ([A-Z0-9]+ )?PRIVATE KEY-----)'
findings = set()
for commit in commits:
    result = subprocess.run(git + ['grep', '-I', '-n', '-E', '-e', pattern, commit, '--', '.'], cwd=root, text=True, capture_output=True)
    if result.returncode not in (0, 1):
        raise SystemExit('Git history scan failed: ' + result.stderr)
    for line in result.stdout.splitlines():
        findings.add(':'.join(line.split(':', 3)[:3]))
    paths = subprocess.check_output(git + ['ls-tree', '-r', '--name-only', commit], cwd=root, text=True).splitlines()
    for path in paths:
        name = Path(path).name
        if (name == '.env' or name.startswith('.env.') and name != '.env.example') or Path(path).suffix.lower() in {'.pem', '.key', '.p12', '.pfx'}:
            findings.add(commit + ':' + path)
if findings:
    for location in sorted(findings):
        print('REVIEW credential pattern or private file at ' + location)
    raise SystemExit(1)
print(f'PASS credential-pattern and private-file scan across {len(commits)} reachable commits (not an exhaustive secret audit)')
