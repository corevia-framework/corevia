import importlib.util
import json
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('generated_contract',root/'contracts/generated/python/__init__.py',submodule_search_locations=[str(root/'contracts/generated/python')])
module=importlib.util.module_from_spec(spec);sys.modules[spec.name]=module;spec.loader.exec_module(module)
client=module.Client(lambda name,payload,auth:{'message':'Hello '+payload['name']})
fixtures=json.loads((root/'contracts/fixtures.json').read_text(encoding='utf-8'))
for fixture in fixtures:
    valid=True
    try:getattr(client,{'input':'validate_input','output':'validate_output','event':'validate_event'}[fixture['kind']])(fixture['name'],fixture['value'])
    except ValueError:valid=False
    assert valid is fixture['valid'], fixture
assert client.action_demo_greet({'name':'Ada'})=={'message':'Hello Ada'}
assert client.requirements('demo.greet')[0]['scheme']=='session'
client.validate_error('demo.greet','UNAVAILABLE',True)
print(f'PASS {len(fixtures)} generated Python application contracts and typed client')
