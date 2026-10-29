# Changelog

## [Unreleased]

### Fixed

- An `UnreachableException` no longer carries the keys: Guzzle's exception, its previous, carried
  the request whose headers held the application and consumer keys, and every trace kept them in
  its arguments. The previous is a copy (the same class and code, the keys masked in its message,
  no request), no trace of the chain keeps its arguments. Needs omnisense/omnidns's `Http\Redaction`.
- The rights the factory and the README list name DELETE on `/domain/zone/*`: `delete()` and an
  `update()` that changes a record's type call `DELETE /domain/zone/{zone}/record/{id}`, which an
  application made with the listed rights could not. No behaviour changed.
