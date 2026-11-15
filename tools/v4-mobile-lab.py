"""Create a disposable Flutter Android host from current Corevia SDK sources; never deploys automatically."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess

ROOT=Path(__file__).resolve().parents[1]


def build(output,flutter,legacy_renderer=False):
    storage=(ROOT/'storage').resolve()
    if not output.is_absolute():output=ROOT/output
    resolved=output.resolve()
    if output.exists()or output.is_symlink()or resolved==storage or not resolved.is_relative_to(storage):
        raise ValueError('Choose a new private output directory inside repository storage.')
    for parent in output.parents:
        if parent.is_symlink():raise ValueError('Output may not traverse symlinks.')
        if parent==ROOT:break
    sources={}
    for path in sorted((ROOT/'sdk/dart/lib').rglob('*.dart')):
        if path.is_symlink():raise ValueError('SDK sources must be regular files.')
        sources[path.relative_to(ROOT).as_posix()]=path.read_bytes()
    for path in [ROOT/'LICENSE',ROOT/'sdk/dart/pubspec.yaml',ROOT/'examples/mobile/flutter/offline_notes_page.dart',*sorted((ROOT/'examples/mobile/lab').rglob('*.stub'))]:
        if path.is_symlink()or not path.is_file():raise ValueError('Expected a regular lab source.')
        sources[path.relative_to(ROOT).as_posix()]=path.read_bytes()
    os.umask(0o077);output.mkdir(parents=True,mode=0o700)
    key=output.parent/'corevia-lab-debug.keystore'
    key_receipt=output.parent/'corevia-lab-debug-key.json'
    if key.is_symlink()or key_receipt.is_symlink():raise ValueError('Lab signing files must be regular private files.')
    if key.exists():
        if not key_receipt.is_file():raise ValueError('Existing signing key has no lab ownership receipt.')
        ownership=json.loads(key_receipt.read_text(encoding='utf-8'))
        if ownership.get('fixture_only')is not True or ownership.get('sha256')!=hashlib.sha256(key.read_bytes()).hexdigest():raise ValueError('Lab signing ownership/hash mismatch.')
    else:
        if key_receipt.exists():raise ValueError('Lab signing receipt exists without its key; review it before regenerating.')
        keytool=shutil.which('keytool')
        if not keytool:
            candidate=Path(os.environ.get('JAVA_HOME',''))/'bin'/('keytool.exe'if os.name=='nt'else'keytool')
            if candidate.is_file():keytool=str(candidate)
        if not keytool:raise RuntimeError('Install a Java JDK with keytool before generating the Android lab.')
        subprocess.run([keytool,'-genkeypair','-noprompt','-keystore',str(key),'-storepass','android','-alias','androiddebugkey','-keypass','android','-dname','CN=Corevia Device Lab,O=Development Fixture,C=US','-keyalg','RSA','-keysize','2048','-validity','3650'],check=True,capture_output=True,timeout=60)
        key_receipt.write_text(json.dumps({'fixture_only':True,'sha256':hashlib.sha256(key.read_bytes()).hexdigest()},indent=2)+'\n',encoding='utf-8')
    env=dict(os.environ,CI='true',FLUTTER_SUPPRESS_ANALYTICS='true',PUB_CACHE=str(output.parent/'pub-cache'),GRADLE_USER_HOME=str(output.parent/'gradle-cache'))
    app=output/'app'
    subprocess.run([flutter,'create','--empty','--platforms=android','--org=dev.corevia.lab','--project-name=corevia_mobile_lab','--no-pub',str(app)],env=env,check=True,timeout=600)
    for name,wire in sources.items():
        if name=='LICENSE':
            destination=output/'corevia-sdk/LICENSE'
        elif name.startswith('sdk/dart/'):
            destination=output/'corevia-sdk'/name.removeprefix('sdk/dart/')
        elif name.endswith('offline_notes_page.dart'):
            destination=app/'lib/note_page.dart'
        else:
            destination=app/name.removeprefix('examples/mobile/lab/').removesuffix('.stub')
        destination.parent.mkdir(parents=True,exist_ok=True);destination.write_bytes(wire)
    (app/'pubspec.yaml').write_text('''name: corevia_mobile_lab
description: Synthetic Corevia native device acceptance fixture; not a production application.
publish_to: none
version: 0.0.0+1
environment:
  sdk: '>=3.3.0 <4.0.0'
dependencies:
  flutter:
    sdk: flutter
  corevia:
    path: ../corevia-sdk
dev_dependencies:
  flutter_test:
    sdk: flutter
  integration_test:
    sdk: flutter
flutter:
  uses-material-design: true
''',encoding='utf-8')
    (app/'analysis_options.yaml').write_text('analyzer:\n  exclude:\n    - build/**\n',encoding='utf-8')
    android_manifest=app/'android/app/src/main/AndroidManifest.xml'
    android_manifest.write_text(android_manifest.read_text(encoding='utf-8').replace('android:label="corevia_mobile_lab"','android:label="Corevia Lab"'),encoding='utf-8')
    if legacy_renderer:
        android_manifest.write_text(android_manifest.read_text(encoding='utf-8').replace('</application>','<meta-data android:name="io.flutter.embedding.android.EnableImpeller" android:value="false"/>\n    </application>'),encoding='utf-8')
    # Keep Flutter's standard NDK selection and native-library stripping.
    gradle=app/'android/app/build.gradle.kts'
    text=gradle.read_text(encoding='utf-8')
    gradle.write_text(text+'\nandroid {\n    signingConfigs { getByName("debug") { storeFile = rootProject.file("../../../corevia-lab-debug.keystore") } }\n}\n',encoding='utf-8')
    properties=app/'android/gradle.properties'
    lines=[line for line in properties.read_text(encoding='utf-8').splitlines()if not line.startswith(('org.gradle.jvmargs=','org.gradle.workers.max=','org.gradle.daemon='))]
    properties.write_text('\n'.join(lines)+'\norg.gradle.jvmargs=-Xmx3G -XX:MaxMetaspaceSize=1G -Dfile.encoding=UTF-8\norg.gradle.workers.max=2\norg.gradle.daemon=false\n',encoding='utf-8')
    debug=app/'android/app/src/debug';debug.mkdir(parents=True,exist_ok=True)
    (debug/'AndroidManifest.xml').write_text('''<manifest xmlns:android="http://schemas.android.com/apk/res/android">
<uses-permission android:name="android.permission.INTERNET"/>
</manifest>
''',encoding='utf-8')
    manifest={'format':1,'fixture_only':True,'native_device_tested':False,'generator_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'source_hashes':{name:hashlib.sha256(wire).hexdigest()for name,wire in sources.items()},'app':str(app),'sdk':str(output/'corevia-sdk')}
    manifest['android_renderer']='legacy-opengl'if legacy_renderer else'flutter-default'
    (output/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    return manifest


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',required=True)
    parser.add_argument('--flutter',default=shutil.which('flutter'))
    parser.add_argument('--legacy-renderer',action='store_true',help='Opt this disposable Android host into Flutter OpenGL rendering for compatibility checks')
    args=parser.parse_args()
    if not args.flutter:raise RuntimeError('Install the Flutter SDK on the build machine first.')
    print(json.dumps(build(Path(args.output),args.flutter,args.legacy_renderer),indent=2))


if __name__=='__main__':main()
