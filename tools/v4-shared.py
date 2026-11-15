"""Build an upload bundle with public_html separated from private application/dependency files."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('package', ROOT / 'tools/v4-package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)
ALLOWED = {'app', 'config', 'routes', 'resources', 'public', 'vendor', 'contracts'}
ROOT_FILES = {'autoload.php', 'corevia', 'composer.json', 'composer.lock', '.corevia-application.json', '.corevia-auth-starter.json'}
DENIED = {'.git', 'node_modules', 'storage', 'tests', 'frontend', '__pycache__'}
BRIDGE = b'''<?php
declare(strict_types=1);
// Only this public_html directory is the web document root.
require dirname(__DIR__).'/private/corevia/public/index.php';
'''
REWRITE = b'''Options -Indexes
DirectoryIndex index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
'''


def build(application, output, preview=False):
    root = Path(application).resolve()
    if not (root / 'vendor/autoload.php').is_file() or not (root / 'public/index.php').is_file():
        raise ValueError('Provide a built application with installed Composer dependencies.')
    destination = package.output_path(output)
    files = {}
    total = 0
    for candidate in sorted(root.rglob('*')):
        relative = candidate.relative_to(root)
        if relative.parts[0] in DENIED or any(part in {'node_modules', '.git', '__pycache__'} for part in relative.parts) or relative.parts[0] not in ALLOWED | ROOT_FILES:
            continue
        if candidate.is_symlink():
            raise ValueError('Shared bundles cannot contain symlinks.')
        if not candidate.is_file():
            continue
        if candidate.name.startswith('.env') or candidate.suffix.lower() in {'.key', '.pem', '.secret', '.pfx', '.p12', '.sqlite', '.db'}:
            raise ValueError('Private runtime material must be provisioned separately, not bundled.')
        data = candidate.read_bytes()
        total += len(data)
        if total > 134217728:
            raise ValueError('Shared bundle exceeds 128MiB.')
        name = relative.as_posix()
        files['private/corevia/' + name] = data
        if relative.parts[0] == 'public':
            public = relative.relative_to('public').as_posix()
            if public == 'index.php':
                continue
            if any(part.startswith('.') for part in relative.parts[1:]) or candidate.suffix.lower() in {'.php', '.phtml', '.phar'}:
                raise ValueError('Public uploads may not contain additional executable PHP or hidden files.')
            files['public_html/' + public] = data
    files['private/corevia/storage/.gitkeep'] = b''
    files['public_html/index.php'] = BRIDGE
    files['public_html/.htaccess'] = REWRITE
    files['UPLOAD.md'] = b'''# Corevia shared-host bundle
Upload public_html/ as the web document root and keep private/ as its sibling outside that root.
Never upload this bundle's top-level directory as the public document root.
Provision secrets and existing storage/encryption keys separately; this archive excludes live state.
Preserve private/corevia/storage across code updates. Back up databases separately.
No Composer, Node, Python, daemon or root access is required for ordinary PHP pages on the target.
Apache rewrite support and HTTPS must be verified with your host. Other servers need equivalent routing.
Treat logs and configuration as private; review application code for inline secrets before distribution.
Python/model hosting, cron and persistent workers require explicit supported services.
'''
    inventory = {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())}
    report = {'format': 1, 'files': inventory, 'fingerprint': hashlib.sha256(package.canonical(inventory)).hexdigest(),
              'target_validated': False, 'live_state_included': False}
    if preview:
        return {'preview': True, 'file_count': len(files), 'fingerprint': report['fingerprint']}
    destination.mkdir(parents=True, exist_ok=False)
    for name, data in files.items():
        path = destination / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(0o600 if name.startswith('private/') else 0o644)
        if name.startswith('private/'):
            directory=path.parent
            while directory!=destination:
                directory.chmod(0o700);directory=directory.parent
    (destination / 'shared-host.zip').write_bytes(package.archive(files))
    (destination / 'manifest.json').write_bytes(package.canonical(report))
    return {'created': True, 'fingerprint': report['fingerprint'], 'target_validated': False, 'file_count': len(files)}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--application', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    try:
        print(json.dumps(build(args.application, args.output, args.dry_run), indent=2))
    except (OSError, ValueError) as error:
        parser.exit(1, str(error) + '\n')
