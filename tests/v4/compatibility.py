"""Negative fixtures for the API gate, followed by the real V3/current comparison."""
import copy
import importlib.util
from pathlib import Path

root = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('compatibility', root / 'tools/v4-compatibility.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
baseline = module.snapshot(root)
assert len(baseline['symbols']) > 100
assert not module.compare(baseline, copy.deepcopy(baseline))
changed = copy.deepcopy(baseline)
del changed['symbols']['Corevia\\Core\\Corevia']
assert module.compare(baseline, changed)[0]['change'] == 'removed_symbol'
changed = copy.deepcopy(baseline)
changed['symbols']['Corevia\\Core\\Corevia']['methods']['create']['parameters'][0]['name'] = 'renamed'
assert any(row['change'] == 'changed_methods' for row in module.compare(baseline, changed))
changed = copy.deepcopy(baseline)
del changed['symbols']['Corevia\\Core\\Corevia']['methods']['shutdown']
assert any(row['change'] == 'removed_methods' for row in module.compare(baseline, changed))
changed = copy.deepcopy(baseline)
interface = next(symbol for symbol, value in changed['symbols'].items() if value['kind'] == 'interface')
changed['symbols'][interface]['methods']['newRequirement'] = {'abstract': True}
assert any(row['change'] == 'new_implementation_obligation' for row in module.compare(baseline, changed))
changed = copy.deepcopy(baseline)
changed['symbols']['Corevia\\Core\\Version']['constants']['VERSION']['value'] = 'future-version'
assert not module.compare(baseline, changed)
del changed['symbols']['Corevia\\Core\\Version']['constants']['VERSION']
assert any(row['change'] == 'removed_constants' for row in module.compare(baseline, changed))
enum = next(value for value in baseline['symbols'].values() if value['kind'] == 'enum')
assert any(isinstance(value['value'], dict) and 'value' in value['value'] for value in enum['constants'].values())
report = module.verify(module.BASELINE)
assert report['passed'], report['findings']
print('PASS API removal/signature/interface negatives and real V3 baseline comparison; ' + str(report['baseline_symbols']) + ' baseline symbols')
