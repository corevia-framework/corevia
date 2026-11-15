"""Conservative PHP API-shape comparison with the recorded V3 source; not behavioral certification."""
import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
BASELINE = 'd7429ed14356de39aaf8e98fa0993fc5c8f38337'
GIT = ['git', '-c', 'safe.directory=' + ROOT.as_posix()]


def optional_final_extension(owner, previous, current):
    """Final classes cannot have application overrides invalidated by extra parameters."""
    if not owner['final'] or owner['kind'] != 'class':
        return False
    old_parameters, parameters = previous.get('parameters', []), current.get('parameters', [])
    return (len(parameters) > len(old_parameters)
            and parameters[:len(old_parameters)] == old_parameters
            and not any(parameter['variadic'] for parameter in old_parameters)
            and all(parameter['optional'] for parameter in parameters[len(old_parameters):])
            and {key: value for key, value in previous.items() if key != 'parameters'}
            == {key: value for key, value in current.items() if key != 'parameters'})


def additive_capabilities(previous, current, symbols):
    if previous['kind'] != 'class' or not set(previous['interfaces']).issubset(current['interfaces']):
        return False
    for name in set(current['interfaces']) - set(previous['interfaces']):
        interface = symbols.get(name)
        if interface is None or interface['kind'] != 'interface' or interface['constants']:
            return False
        for method, obligation in interface['methods'].items():
            owner = current
            while method not in owner['methods'] and owner['parent'] in symbols:
                owner = symbols[owner['parent']]
            implementation = owner['methods'].get(method)
            if implementation is None or implementation.get('abstract', True):
                return False
            ignored = {'abstract', 'final'}
            if ({key: value for key, value in implementation.items() if key not in ignored}
                    != {key: value for key, value in obligation.items() if key not in ignored}):
                return False
    return True


def compare(before, after):
    findings = []
    for symbol, old in before['symbols'].items():
        new = after['symbols'].get(symbol)
        if new is None:
            findings.append({'symbol': symbol, 'change': 'removed_symbol'})
            continue
        for field in ['kind', 'abstract', 'final', 'readonly', 'parent', 'interfaces', 'traits']:
            if old[field] != new[field]:
                if field == 'interfaces' and additive_capabilities(old, new, after['symbols']):
                    continue
                findings.append({'symbol': symbol, 'change': 'changed_' + field})
        for group in ['methods', 'properties', 'constants']:
            for name, shape in old[group].items():
                # Framework release metadata may advance independently of protocol constants.
                if name not in new[group]:
                    findings.append({'symbol': symbol, 'member': name, 'change': 'removed_' + group})
                else:
                    current = new[group][name]
                    if symbol == 'Corevia\\Core\\Version' and group == 'constants' and name == 'VERSION':
                        shape = {key: value for key, value in shape.items() if key != 'value'}
                        current = {key: value for key, value in current.items() if key != 'value'}
                    if shape != current and not (group == 'methods' and optional_final_extension(old, shape, current)):
                        findings.append({'symbol': symbol, 'member': name, 'change': 'changed_' + group})
            if group == 'methods':
                for name, shape in new[group].items():
                    if name not in old[group] and (old['kind'] in {'interface', 'trait'} or shape['abstract']):
                        findings.append({'symbol': symbol, 'member': name, 'change': 'new_implementation_obligation'})
    return findings


def snapshot(root):
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP is required for API capture.')
    result = subprocess.run([php, str(ROOT / 'tools/v4-api.php'), str(root)], text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError('PHP API capture failed: ' + result.stderr[-1500:])
    return json.loads(result.stdout)


def verify(baseline):
    sha = subprocess.check_output(GIT + ['rev-parse', '--verify', '--end-of-options', baseline + '^{commit}'], cwd=ROOT, text=True).strip()
    archive = subprocess.check_output(GIT + ['archive', '--format=zip', sha, 'packages', 'autoload.php'], cwd=ROOT)
    storage = ROOT / 'storage'
    storage.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='v4-api-', dir=storage) as directory:
        with zipfile.ZipFile(io.BytesIO(archive)) as files:
            for entry in files.infolist():
                path = PurePosixPath(entry.filename)
                if path.is_absolute() or '..' in path.parts or stat.S_ISLNK(entry.external_attr >> 16):
                    raise RuntimeError('Unsafe baseline archive member.')
            files.extractall(directory)
        before, after = snapshot(directory), snapshot(ROOT)
    findings = compare(before, after)
    fingerprints = {}
    for name, document in [('baseline', before), ('current', after)]:
        data = (json.dumps(document, sort_keys=True, indent=2) + '\n').encode()
        (storage / ('v4-api-' + name + '.json')).write_bytes(data)
        fingerprints[name] = hashlib.sha256(data).hexdigest()
    report = {'format': 1, 'baseline_commit': sha, 'passed': not findings,
              'baseline_symbols': len(before['symbols']), 'current_symbols': len(after['symbols']),
              'api_fingerprints': fingerprints,
              'findings': findings, 'limits': [
                  'API shapes only. Optional trailing parameters on final classes and declared capability interfaces with matching concrete implementations are additive; other signature/interface changes require review.',
                  'Method bodies, literal default values, persisted schemas, side effects and SDK wire behavior are not established by this check.',
                  'New concrete methods may conflict with application subclasses; application upgrade tests remain necessary.']}
    (storage / 'v4-api-verification.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', default=BASELINE)
    args = parser.parse_args()
    try:
        report = verify(args.baseline)
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + '\n')
    print(json.dumps(report, indent=2))
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
