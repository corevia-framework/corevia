"""Operator application loader. Dynamic application imports deliberately live outside the SDK."""
import argparse
import importlib
import json
import os
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "sdk/python"))
from corevia.model_server import ModelServer, ModelService


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--factory", required=True, help="Trusted module:factory; never populate from HTTP input")
    parser.add_argument("--port", type=int, default=8111)
    parser.add_argument("--model", default="local")
    parser.add_argument("--concurrency", type=int, default=1)
    args = parser.parse_args()
    if ":" not in args.factory:
        parser.error("Factory must be module:callable")
    module, name = args.factory.split(":", 1)
    factory = getattr(importlib.import_module(module), name)
    model = factory()
    service = ModelService(model, os.environ.get("MODEL_WORKER_TOKEN", ""), args.model, args.concurrency)
    try:
        server = ModelServer(("127.0.0.1", args.port), service)
    except BaseException:
        service.close()
        raise
    print(json.dumps({"ready": True, "port": server.server_port}), flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
