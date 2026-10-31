"""Prove forbidden edges fail without modifying repository source."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('corevia_architecture',ROOT/'tools/architecture.py')
architecture=importlib.util.module_from_spec(spec)
spec.loader.exec_module(architecture)
class ArchitectureTests(unittest.TestCase):
    def rejected(self,name,source,expected):
        with tempfile.TemporaryDirectory(prefix='corevia-architecture-') as temporary:
            root=Path(temporary);path=root/name;path.parent.mkdir(parents=True);path.write_text(source,encoding='utf-8')
            errors=architecture.check(root)
            self.assertTrue(any(expected in error for error in errors),errors)
    def test_core_imports(self):
        for source in [r'<?php namespace Corevia\Core; use App\Orders\OrdersModule;',r'<?php namespace Corevia\Core; $x=new \Corevia\Http\Router();',r'<?php namespace Corevia\Core; use Corevia\{Http\Router};',r"<?php namespace Corevia\Core; class_exists('App\\Orders\\OrdersModule');",r'<?php namespace Corevia\Core; use App as Application;']:
            self.rejected('packages/core/src/Invalid.php',source,'Forbidden dependency')
    def test_server_layer_imports(self):
        self.rejected('packages/protocol/src/Invalid.php',r'<?php namespace Corevia\Protocol; use Corevia\Persistence\DatabaseManager;','Forbidden dependency')
        self.rejected('packages/core/src/Invalid.php',r"<?php namespace Corevia\Core; require __DIR__.'/../../app/private.php';",'Executable includes')
        self.rejected('packages/core/src/Invalid.php',r"<?php namespace Corevia\Core; eval('$x=1;');",'eval bypasses')
    def test_python_adapter(self):
        for source in ['import sqlalchemy','from packages.persistence import DatabaseManager','import sqlite3','import importlib\nimportlib.import_module("pymysql")']:
            self.rejected('sdk/python/corevia/invalid.py',source,'Python')
    def test_frontend_imports(self):
        for source in ["import db from '../../packages/persistence/db.js';", "export {db} from '@corevia/server';", "const db=import('../../packages/db.js');", "const db=require('@corevia/server');", "const db=import(location.hash);", "const nested=`${import('../../packages/db.js')}`;"]:
            self.rejected('sdk/js/invalid.js',source,'SDK' if 'location' not in source else 'Computed imports')
        self.rejected('sdk/dart/lib/invalid.dart',"import '../../../packages/internal.dart';",'SDK')
        self.rejected('sdk/dart/lib/invalid.dart',"import 'local.dart' if (dart.library.io) '../../../packages/private.dart';",'SDK')
    def test_allowed_code_and_comments(self):
        with tempfile.TemporaryDirectory(prefix='corevia-architecture-') as temporary:
            root=Path(temporary)
            files={'packages/core/src/Valid.php':r'<?php namespace Corevia\Core; /* use App\Orders; */ $text="App\\Orders in documentation";', 'sdk/python/corevia/valid.py':'import json\nfrom . import scopes', 'sdk/js/valid.js':"// import x from '@corevia/server';\nimport './index.js';",'sdk/dart/lib/valid.dart':"import 'dart:async';\nimport 'local.dart';"}
            for name,source in files.items():
                path=root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(source,encoding='utf-8')
            self.assertEqual(architecture.check(root),[])
            (root/'sdk/js/valid.js').write_text('const documentation="import(unknown)"; const pattern=/import(anything)/;',encoding='utf-8')
            (root/'sdk/dart/lib/valid.dart').write_text('const documentation="import \'server.dart\';";',encoding='utf-8')
            self.assertEqual(architecture.check(root),[])
    def test_actual_repository(self):
        self.assertEqual(architecture.check(ROOT),[])
if __name__=='__main__':
    unittest.main(verbosity=2)
