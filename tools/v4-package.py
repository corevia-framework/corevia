"""Build versioned runtime/application artifacts from the Git index, without executing PHP or hooks."""
import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
GIT = ['git', '-c', 'safe.directory=' + ROOT.as_posix()]
RUNTIME_NAME = 'corevia-framework/runtime'
VERSION = '4.0.1'


def canonical(value):
    return (json.dumps(value, sort_keys=True, indent=2, ensure_ascii=True) + '\n').encode()


def selected(name):
    return (name.startswith(('packages/', 'starters/', 'resources/views/', 'config/settings/'))
            or name in {'LICENSE', 'composer.json', 'corevia', 'app/Http/HomeController.php',
                        'routes/web.php', 'routes/api.php', 'config/web.php', 'config/runtime.php',
                        'config/modules.php', 'public/assets/app.css', 'public/assets/app.js', 'public/assets/icon.svg',
                        'sdk/js/index.js', 'sdk/js/ai.js'})


def indexed_sources():
    entries = subprocess.check_output(GIT + ['ls-files', '--stage', '-z'], cwd=ROOT).split(b'\0')
    names = []
    for entry in entries:
        if not entry:
            continue
        metadata, wire = entry.split(b'\t', 1)
        name = wire.decode('utf-8')
        if not selected(name):
            continue
        mode, _, stage = metadata.decode().split()
        if mode not in {'100644', '100755'} or stage != '0':
            raise ValueError('Selected source must be a regular, unconflicted indexed file: ' + name)
        path = PurePosixPath(name)
        if path.is_absolute() or '..' in path.parts or any(c in name for c in '\r\n\\:'):
            raise ValueError('Unsafe indexed source path.')
        names.append(name)
    names.sort()
    batch = subprocess.run(GIT + ['cat-file', '--batch'], cwd=ROOT,
                           input=''.join(':' + name + '\n' for name in names).encode(),
                           capture_output=True, check=True).stdout
    cursor = 0
    result = {}
    for name in names:
        end = batch.index(b'\n', cursor)
        _, kind, size = batch[cursor:end].split()
        if kind != b'blob':
            raise ValueError('Expected indexed file content.')
        start = end + 1
        size = int(size)
        result[name] = batch[start:start + size]
        cursor = start + size + 1
    return result


def assemble():
    source = indexed_sources()
    manifest = json.loads(source['composer.json'])
    prefixes = {key: value for key, value in manifest['autoload']['psr-4'].items() if key != 'App\\'}
    runtime = {name: data for name, data in source.items()
               if name.startswith(('packages/', 'starters/'))
               or name in {'LICENSE', 'sdk/js/index.js', 'sdk/js/ai.js'}}
    runtime['composer.json'] = canonical({
        'name': RUNTIME_NAME, 'description': 'Corevia runtime and optional starter resources; V4 distribution',
        'type': 'library', 'license': 'MIT', 'require': manifest['require'],
        'suggest': manifest.get('suggest', {}), 'autoload': {'psr-4': prefixes},
        'bin': ['bin/corevia-new'],
    })
    runtime['bin/corevia-new'] = source['starters/application/new.php.stub']
    application = {name: data for name, data in source.items()
                   if name.startswith(('resources/views/', 'config/settings/'))
                   or name in {'LICENSE', 'corevia', 'app/Http/HomeController.php', 'routes/web.php',
                               'routes/api.php', 'config/web.php', 'config/runtime.php', 'config/modules.php',
                               'public/assets/app.css', 'public/assets/app.js', 'public/assets/icon.svg'}}
    for template, destination in {'autoload.php': 'autoload.php', 'bootstrap.php': 'config/bootstrap.php',
                                  'index.php': 'public/index.php', 'README.md': 'README.md',
                                  'tests.php': 'tests/run.php'}.items():
        application[destination] = source['starters/application/' + template + '.stub']
    application['composer.json'] = canonical({
        'name': 'corevia-app/application', 'description': 'Application skeleton for the Corevia V4 distribution',
        'type': 'project', 'license': 'MIT',
        'require': {'php': '>=8.2', 'ext-json': '*', RUNTIME_NAME: '^'+VERSION},
        'autoload': {'psr-4': {'App\\': 'app/'}},
        'repositories': [{'type': 'path', 'url': '../runtime',
                          'options': {'symlink': False, 'versions': {RUNTIME_NAME: VERSION}}}],
        'config': {'allow-plugins': False},
    })
    application['.gitignore'] = b'/vendor/\n/storage/*\n!/storage/.gitkeep\n.env\n.env.*\n!.env.example\nnode_modules/\n*.log\n'
    application['storage/.gitkeep'] = b''
    application['database/migrations/.gitkeep'] = b''
    baseline = 'd7429ed14356de39aaf8e98fa0993fc5c8f38337'
    old = subprocess.check_output(GIT + ['archive', '--format=zip', baseline, 'packages', 'autoload.php'], cwd=ROOT)
    with zipfile.ZipFile(io.BytesIO(old)) as incoming:
        legacy = {name: incoming.read(name).replace(b'\r\n', b'\n') for name in incoming.namelist() if not name.endswith('/')}
    runtime['v3-compatibility.json'] = canonical({'format': 1, 'commit': baseline,
        'autoload': hashlib.sha256(legacy['autoload.php']).hexdigest(),
        'packages': {name: hashlib.sha256(data).hexdigest() for name, data in legacy.items() if name.startswith('packages/')}})
    # Embed an application skeleton inside the library so installed CLI tools can compose new apps.
    for name, data in application.items():
        runtime['starters/application/skeleton/' + name] = data
    runtime['corevia-package.json'] = canonical({'format': 1, 'files': {
        name: hashlib.sha256(data).hexdigest() for name, data in sorted(runtime.items())}})
    return runtime, application


def archive(files):
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
        for name, content in sorted(files.items()):
            entry = zipfile.ZipInfo(name, date_time=(2000, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (0o100600 if name.startswith('private/') else (0o100755 if name in {'corevia','bin/corevia-new'} else 0o100644)) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            output.writestr(entry, content, compresslevel=9)
    return buffer.getvalue()


def output_path(value):
    path = Path(value)
    if not path.is_absolute():
        path = ROOT / path
    # Builds only create new artifacts under ignored storage, never into an application or vendor/.
    storage = ROOT / 'storage'
    if storage.is_symlink():
        raise ValueError('Build storage cannot be a symlink.')
    if not path.resolve().is_relative_to(storage.resolve()) or path.resolve() == storage.resolve():
        raise ValueError('Choose a new output directory beneath repository storage/.')
    for ancestor in [path, *path.parents]:
        if ancestor.is_symlink():
            raise ValueError('Output cannot follow symlinks.')
        if ancestor == ROOT:
            break
    if path.exists():
        raise ValueError('Refusing to overwrite an existing output directory.')
    return path


def build(directory, dry_run=False):
    destination = output_path(directory)
    runtime, application = assemble()
    filesets = {'runtime': runtime, 'application': application}
    inventories = {name: {path: hashlib.sha256(data).hexdigest() for path, data in sorted(files.items())}
                   for name, files in filesets.items()}
    fingerprint = hashlib.sha256(canonical(inventories)).hexdigest()
    result = {'format': 1, 'status': 'prepared', 'source': 'git-index',
              'package': RUNTIME_NAME, 'package_version': VERSION, 'fingerprint': fingerprint,
              'files': inventories}
    if dry_run:
        return {**{key: value for key, value in result.items() if key != 'files'},
                'dry_run': True, 'counts': {name: len(files) for name, files in filesets.items()}}
    destination.mkdir(parents=True, exist_ok=False)
    artifacts = {}
    for name, files in filesets.items():
        data = archive(files)
        (destination / (name + '.zip')).write_bytes(data)
        artifacts[name + '.zip'] = hashlib.sha256(data).hexdigest()
        for relative, content in files.items():
            target = destination / name / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            with target.open('xb') as stream:
                stream.write(content)
    result['artifacts'] = artifacts
    (destination / 'manifest.json').write_bytes(canonical(result))
    return {key: value for key, value in result.items() if key != 'files'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='storage/v4-preview')
    parser.add_argument('--dry-run', action='store_true')
    options = parser.parse_args()
    try:
        print(json.dumps(build(options.output, options.dry_run), indent=2))
    except (ValueError, OSError, KeyError, subprocess.CalledProcessError) as error:
        parser.exit(1, 'Build failed: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
