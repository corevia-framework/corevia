"""Prepare a static Composer development repository. Writes local artifacts only; never uploads."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path, PurePosixPath
import stat
from urllib.parse import urlsplit
import zipfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('package',ROOT/'tools/v4-package.py')
package=importlib.util.module_from_spec(spec);spec.loader.exec_module(package)


def prepare(distribution, output, base_url):
    address=urlsplit(base_url)
    if address.scheme!='https' or not address.hostname or address.username or address.password or address.query or address.fragment or any(ord(c)<33 for c in base_url):
        raise ValueError('Use a credential-free HTTPS publication base URL.')
    destination=package.output_path(output)
    source=Path(distribution)
    original=json.loads((source/'manifest.json').read_text())
    packages={};artifacts={}
    for stem,name in [('runtime','corevia-framework/runtime'),('application','corevia-framework/application')]:
        if hashlib.sha256((source/(stem+'.zip')).read_bytes()).hexdigest()!=original['artifacts'][stem+'.zip']:
            raise ValueError('Distribution archive fingerprint changed.')
        with zipfile.ZipFile(source/(stem+'.zip')) as archive:
            for entry in archive.infolist():
                relative=PurePosixPath(entry.filename)
                if relative.is_absolute() or '..' in relative.parts or '\\' in entry.filename or ':' in entry.filename or stat.S_ISLNK(entry.external_attr>>16):
                    raise ValueError('Unsafe distribution archive member.')
            files={entry.filename:archive.read(entry) for entry in archive.infolist() if not entry.is_dir()}
        manifest=json.loads(files['composer.json'])
        if stem=='application':
            manifest['name']=name
            manifest['repositories']=[{'type':'composer','url':base_url.rstrip('/')}]
            files['composer.json']=package.canonical(manifest)
        data=package.archive(files);filename=stem+'.zip';artifacts[filename]=data
        packages[name]={'dev-v4':{**manifest,'version':'dev-v4','dist':{'type':'zip','url':base_url.rstrip('/')+'/'+filename,'shasum':hashlib.sha1(data).hexdigest()}}}
    index={'packages':packages}
    destination.mkdir(parents=True,exist_ok=False)
    for name,data in artifacts.items():(destination/name).write_bytes(data)
    (destination/'packages.json').write_bytes(package.canonical(index))
    (destination/'checksums.sha256.json').write_bytes(package.canonical({name:hashlib.sha256(data).hexdigest() for name,data in artifacts.items()}))
    return {'prepared':True,'uploaded':False,'registry_registered':False,'version':'dev-v4','packages':list(packages)}


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--distribution',required=True);parser.add_argument('--output',required=True);parser.add_argument('--base-url',required=True)
    args=parser.parse_args()
    try:print(json.dumps(prepare(args.distribution,args.output,args.base_url),indent=2))
    except (OSError,ValueError) as error:parser.exit(1,str(error)+'\n')
