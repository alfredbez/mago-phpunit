# Measure analyzer performance

Generate the fixtures, then run at least five measured rounds after one warmup:

```sh
php bench/generate.php
php bench/run-local.php --runs=7 --output=/tmp/mago-phpunit-benchmark.json
```

With the repository dependencies already installed, the same commands run in Docker:

```sh
docker run --rm -v "$PWD":/work -w /work php:8.4-cli php bench/generate.php
docker run --rm -v "$PWD":/work -w /work php:8.4-cli php bench/run-local.php --runs=7
```

The generator writes four ignored fixture files containing 32 classes and 768 tests. Every class has a shared four-row provider, dependency producer, and `setUp()` initializer. Each provider test checks two array keys and consumes a dependency. The suite uses its own Mago configuration and worker. `tests/run.php` remains independent.

The local script reports milliseconds for:

- `analyze`: the entire Mago process, including worker startup and IPC, using one analyzer thread and one extension worker. Every analysis must exit successfully and return zero issues.
- `data_provider_callbacks`: total time inside the actual `DataProviderArguments::analyze()` callbacks for all 32 classes, including metadata and expression-type queries.
- `method_index`: five batches of the provider's file-method index, once per class on each real source snapshot. Each batch starts with a fresh provider. Reflection invokes this private hotpath without changing its visibility. Loading snapshots is outside this timer. The subprocess uses a 512 MB limit for materialized SDK trees and verifies the resulting class and method counts.

Snapshots come from one unmeasured analysis. They are trusted local PHP serialization, stored in a unique temporary directory and removed after the run. Measured analyses do not write snapshots. Run benchmarks without concurrent test or build jobs. Compare the JSON samples, median, minimum, and maximum on the same machine and PHP version. These measurements describe this fixture workload, not every consumer project.

For the pytest integration:

```sh
python3 -m pip install 'pytest-codspeed==5.0.3'
python3 -B -m pytest -p no:cacheprovider bench/test_benchmarks.py --codspeed
```

CodSpeed measures analyzer and method-index subprocess walltime, including process startup and snapshot loading. The PHP timer additionally isolates index computation for local diagnosis. The workflow installs the committed Composer lockfile, uses PHP 8.4, and runs the CodSpeed action in walltime mode with OIDC. Compare runs with identical installed versions.

Configuration follows the [CodSpeed project schema](https://raw.githubusercontent.com/CodSpeedHQ/codspeed/refs/heads/main/schemas/codspeed.schema.json) and the [dotfiles workflow](https://github.com/alfredbez/dotfiles/blob/master/.github/workflows/codspeed.yml). The action uploads results when this workflow runs on GitHub. Local execution verifies the benchmark entrypoints without uploading a hosted result.
