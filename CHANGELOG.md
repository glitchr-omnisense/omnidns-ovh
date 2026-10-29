# Changelog

## [Unreleased]

### Fixed

- The rights the factory and the README list name DELETE on `/domain/zone/*`: `delete()` and an
  `update()` that changes a record's type call `DELETE /domain/zone/{zone}/record/{id}`, which an
  application made with the listed rights could not. No behaviour changed.
