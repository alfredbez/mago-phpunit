import json
import os
from pathlib import Path
import subprocess
import tempfile

import pytest

ROOT = Path(__file__).resolve().parents[1]
ANALYZE = ["php", "-r", "require 'bench/common.php'; analyzeBenchmark();"]


@pytest.fixture(scope="session")
def snapshots():
    with tempfile.TemporaryDirectory(prefix="mago-phpunit-bench-") as directory:
        subprocess.run(
            ANALYZE,
            cwd=ROOT,
            env={**os.environ, "BENCH_SNAPSHOT_DIR": directory},
            check=True,
            capture_output=True,
        )
        assert len(list(Path(directory).glob("*.snapshot"))) == 4
        yield directory


@pytest.mark.benchmark
def test_analyze(benchmark):
    benchmark(lambda: subprocess.run(ANALYZE, cwd=ROOT, check=True, capture_output=True))


@pytest.mark.benchmark
def test_method_index(benchmark, snapshots):
    def run():
        result = subprocess.run(
            ["php", "bench/provider.php", snapshots], cwd=ROOT, check=True, capture_output=True
        )
        assert json.loads(result.stdout)["checksum"] == 1280

    benchmark(run)
