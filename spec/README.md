# Public PHPUnit conformance catalogue

`catalog.json` is the canonical case registry. IDs are stable and never reused.
Each case records public evidence, an independently written minimal PHP fixture,
runtime semantics, analyzer expectations, a negative control, priority, version
scope, coverage links, and status. References to existing tests do not imply
that every edge case in a topic has been checked.

## Status meanings

- `candidate`: normalized public evidence, no local behavior check yet.
- `runtime-verified`: PHPUnit behavior checked, Mago conformance not established.
- `red`: an executable Mago expectation fails on the recorded baseline.
- `partial`: existing tests cover part of the contract; listed gaps remain.
- `covered`: recorded Mago configuration passes positive and negative checks.

Coverage is version-scoped. A PHPStan or Psalm diagnostic name, type spelling,
or suppression policy is not itself PHPUnit semantics. Issues supply hypotheses,
not proof. For example, PHPStan issue 260 proposes replacing `isset` with a key
assertion, but PHPUNIT-A002 proves these differ for null-valued entries.

`tests/check-spec.php` checks IDs, required fields, source references, fixture
syntax, and local coverage paths. It does not certify the meaning of a source or
execute the catalogue snippets. `tests/runtime/RuntimeContractTest.php` tests
PHPUNIT-A001 through A006 against real PHPUnit. `tests/run.php` runs Mago with
and without the extension and compares diagnostics.

## Source inventory, first pass

The inventory is intentionally incomplete. No upstream analyzer implementation
or architecture was copied.

| Source | Read | Still to harvest |
| --- | --- | --- |
| PHPStan PHPUnit `8dbdc3a` | Function assertion fixtures; method fixture inventory; issues [198](https://github.com/phpstan/phpstan-phpunit/issues/198), [260](https://github.com/phpstan/phpstan-phpunit/issues/260) | Reordered named arguments, assertion functions, mock intersections, data-provider declaration tests |
| Psalm PHPUnit `01a0871` | TestCase acceptance scenarios for setup, `Before`, provider rows, defaults and variadics; issue [113](https://github.com/psalm/psalm-plugin-phpunit/issues/113) | Depends plus provider argument composition, external providers, traits, setup inheritance |
| PHPUnit 12.5.0 `fef037f` | Array-key public tests; versioned assertion and provider documentation; [6526](https://github.com/sebastianbergmann/phpunit/pull/6526) | Test doubles, exception timing, deprecations, annotation removal, PHPUnit 13 tests and release boundaries |
| Mago | [2383](https://github.com/carthage-software/mago/issues/2383), extension architecture and assertion contract | Rust worker ABI only if Rust remains a requirement |

Pinned full links live in `catalog.json`. Documentation and issue pages are
mutable. The repository README's version claims are useful navigation aids,
but must be checked against upstream tests or release evidence before adding
new version boundaries to the public specification.

## Conformance cycles

1. Inspect Git status and reproduce the recorded baseline.
2. Select the highest-priority small evidenced gap, not merely the newest source.
3. Normalize by semantic postcondition, subject, polarity, and version scope.
   Merge duplicate evidence into the existing ID; keep independent controls separate.
4. Add an observable Mago fixture and demonstrate the intended failure.
5. Make the smallest implementation change in the owning extension.
6. Run negative controls, a targeted mutation, and the supported version matrix.
7. Run the full existing suite, update the catalogue, then preserve the cycle
   as one local atomic change.

The completed cycles are [PHPUNIT-A003](cycles/001-A003.md) and
[PHPUNIT-D003](cycles/002-D003.md). PHPUNIT-A001
was already implemented in the existing extension, so the earlier Mago 1.56.1
native-only reproduction is not used to claim an extension defect.

`bash tests/version-matrix.sh OUTPUT_DIRECTORY` checks all four supported PHPUnit
major lines in disposable projects without changing the working lockfile.
`php tests/mutate-a003.php OUTPUT_DIRECTORY` runs four targeted mutations in an
isolated copy and fails if a mutant survives or fails outside the A003 check.
These commands are local checks, not publication or CI dispatch commands.
