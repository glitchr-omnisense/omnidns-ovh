# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnidns` `^1.0` with no stability flag, so `composer require omnidns/ovh` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

### Added

- The usage policy as data: `policy.json` (omnisense/contracts' schema, read on 2026-11-10), named in
  `composer.json` (`extra.omnisense.policy`); the provider implements `DeclaresUsagePolicyInterface`
  and gives it to its `Capabilities`. The factory gives the provider its `Backoff`: before each call
  it waits while the provider asked to, and a 429 the SDK carries is a `UsageLimitReachedException`
  with its Retry-After, holding the next calls.

### Fixed

- An `UnreachableException` no longer carries the keys: Guzzle's exception, its previous, carried
  the request whose headers held the application and consumer keys, and every trace kept them in
  its arguments. The previous is a copy (the same class and code, the keys masked in its message,
  no request), no trace of the chain keeps its arguments. Needs omnisense/omnidns's `Http\Redaction`.
- The rights the factory and the README list name DELETE on `/domain/zone/*`: `delete()` and an
  `update()` that changes a record's type call `DELETE /domain/zone/{zone}/record/{id}`, which an
  application made with the listed rights could not. No behaviour changed.
