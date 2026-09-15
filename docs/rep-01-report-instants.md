# REP-01: report timestamp instants

Confirmed SQL UTC instant fields are serialized with an explicit UTC offset.
Inventory balanceUpdatedAt and the adjacent consumption/suggestion instant
columns are normalized without altering purchase or price calendar dates.
The household adapter accepts the narrowly defined legacy SQL UTC format as
well as explicitly zoned ISO timestamps; unzoned ISO, malformed dates and
invalid values fail as invalid responses. Nulls remain absent.

Local reproduction failed before the server fix (SQL text rather than UTC ISO).
Local PHP 8.5.10 reporting suite: 5 tests, 52 assertions passed.
Local Flutter 3.44.7 / Dart 3.12.2 reporting adapter suite: 14 tests passed
separately with TZ=UTC, TZ=Africa/Windhoek and TZ=America/New_York.
Commands: vendor/bin/phpunit tests/Unit/Reporting/HomeReportServiceTest.php;
flutter test --no-pub test/features/reporting/generated_household_report_repository_test.dart.

This repair preserves the API 2.1.0 shape and generated contract digest.
Deploy backend before the paired client. The new client remains compatible
with the old report SQL timestamp representation. No migration or data rewrite.
Reverting the client is safe with the corrected backend; reverting only the
backend reintroduces timezone ambiguity for older clients.

This scoped regression proof is not full four-batch release acceptance.
Paired PRs: backend #23, client #19, admin #11; no merge/deployment authorized.
