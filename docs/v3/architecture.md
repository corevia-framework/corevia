# Enforced architecture boundaries

Run the development checks before committing:

```sh
cd tools
npm ci --ignore-scripts
cd ..
python tools/architecture.py
python tests/v3/architecture.py
```

They require Python 3.11+, PHP with its development tokenizer extension, Node 22 and the pinned Acorn development parser in `tools/package-lock.json`. These are development checks; the PHP runtime gains no Node dependency. They do not import or execute application PHP/JS/Python source. PHP is tokenized, Python uses its AST, and Acorn parses JavaScript imports/re-exports/CommonJS and dynamic imports, including nested expressions. TS declarations use Acorn's tokenizer and Dart uses a directive lexer, so comments and string contents are not mistaken for dependencies. Computed frontend imports fail; runtime TS/JSX source formats require adding a parser before use in these SDK directories.

CI runs the repository scan and negative fixtures. Those fixtures prove forbidden imports actually fail instead of merely checking today's clean repository.

| PHP package | Allowed Corevia dependencies |
| --- | --- |
| Core | Core |
| Protocol | Core, Protocol |
| HTTP | Core, Protocol, HTTP |
| Persistence | Core, Persistence |
| Realtime | Core, Protocol, Realtime |
| CLI | All framework packages |

No framework package may import `App`. The check covers grouped imports, fully qualified references and direct class-name literals used in reflection. Source namespaces must match their package. Executable includes are restricted to the existing audited configuration loader, PHP view loader and CLI composition loader; `eval` is forbidden. Policy lives in `tools/architecture_rules.json` and changes to it should be reviewed as architecture changes.

The Python SDK and generated binding runtimes can use the standard library and their own package, but cannot import application/server modules, third-party database drivers, `sqlite3` or `dbm`. Explicit dynamic imports/execution are rejected. Application Python model code can live outside this SDK layer and use its own ML dependencies; this check does not ban PyTorch/model libraries in your project.

Frontend SDK imports must stay within their own source root or the explicit external allowlist (`react` for JS; `dart:*` platform libraries for Dart). PHP server packages and application internals cannot be imported through a relative path, re-export, literal dynamic import or CommonJS require. Generated SDKs and compiler runtime templates are checked too. Source symlinks fail. Dependency caches, tests and vendored code are excluded from these owned-source checks.

These are static dependency rules. They do not prove arbitrary runtime behavior, inspect installed third-party implementations or replace code review of reflective/computed class names and audited loaders. Apps/configuration remain composition roots and may intentionally depend on framework packages; the core package may not reverse that dependency.
