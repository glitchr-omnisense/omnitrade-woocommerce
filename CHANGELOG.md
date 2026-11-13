# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0` and `omnisense/omnitrade` `^1.0`, stable versions, where it
  required `@dev` ones; `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- README: the install line and a first call on a recorded answer (no account, nothing sent).

### Added
- `policy.json` (`extra.omnisense.policy`): no rate limit of the REST API's own (the shop's host may set
  one); verified 2026-11-10.
- The gateway's Backoff (omnisense/omnitrade's `Http\Throttle`): a 429 from the shop's host is a
  `RateLimitedException` with its `Retry-After`, never retried, the next calls held. Requires
  omnisense/contracts.
- The gateway's `tags` option (omnisense/omnitrade): the order's `meta_data` (under the payment's
  metadata, `site` the configuration's) and the refund's; `notify()` gives an order's back
  (`Notification::$tags`, without the merchant's reference and the `_` fields). Tests on recorded
  requests (`WooCommerceTagsTest`).

### Changed
- PHPStan at level max with an empty baseline (237 errors that predated it): WooCommerce's answers
  read through `Data` (internal: omnisense/contracts' `Http\Data` with reads that keep PHP's
  `(string)` and `(int)` of a scalar), the arrays typed. Types only: no signature changed, no
  behaviour either.

### Fixed
- A `ProviderException` no longer carries the consumer key and secret. The message quoted the HTTP
  client's (the whole URL), the previous was the client's own exception, and every trace of the
  chain kept the arguments of the calls under it (the request's options, the headers with the
  credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The
  message masks them as `***`, the previous is a copy of the client's exception (the same class and
  code), and no exception `Api` throws keeps its trace's arguments (`Omnitrade\Http\Redaction`).
