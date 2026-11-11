# Changelog

## Unreleased (1.x)

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

