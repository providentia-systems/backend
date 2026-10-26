// Production Client adapters, isolated real HTTP and synthetic data only.
import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:providentia/core/database/app_database.dart';
import 'package:providentia/core/database/drift_local_sync_repository.dart';
import 'package:providentia/core/synchronization/generated_sync_gateway.dart';
import 'package:http/http.dart' as http;
import 'package:providentia/features/reporting/application/household_report_service.dart';
import 'package:providentia/features/reporting/infrastructure/generated_household_report_repository.dart';
import 'package:providentia_api_client/providentia_api_client.dart';

void main() {
  final path = Platform.environment['PROVIDENTIA_RESPONSE_FIXTURE'];
  final base = Uri.parse(
    Platform.environment['PROVIDENTIA_RESPONSE_URL'] ?? '',
  );
  if (path == null || base.scheme != 'http' || base.host != '127.0.0.1') {
    throw StateError('An explicit isolated loopback fixture is required.');
  }
  final fixture = _object(jsonDecode(File(path).readAsStringSync()));
  final session = _object(_object(fixture['sessions'])['alice']);
  final home = fixture['homeId']! as String;
  final headers = {
    'Authorization': 'Bearer ${session['accessToken']}',
    'Content-Type': 'application/json',
  };

  test(
    'production reports preserve unknown, zero, signed and partial facts',
    () async {
      final wire = http.Client();
      addTearDown(wire.close);
      Future<Map<String, Object?>> request(
        String method,
        String suffix, [
        Map<String, Object?>? body,
      ]) async {
        final outgoing = http.Request(
          method,
          base.resolve('/api/v1/homes/$home/$suffix'),
        )..headers.addAll(headers);
        if (body != null) outgoing.body = jsonEncode(body);
        final response = await http.Response.fromStream(
          await wire.send(outgoing),
        );
        expect(
          response.statusCode,
          inInclusiveRange(200, 299),
          reason: 'Synthetic $method $suffix failed',
        );
        return response.body.isEmpty
            ? <String, Object?>{}
            : _object(jsonDecode(response.body));
      }

      final unknown = await request('POST', 'products', {
        'privateName': 'Synthetic unknown stock',
        'unit': 'kg',
      });
      final zero = await request('POST', 'products', {
        'privateName': 'Synthetic recorded zero',
        'unit': 'kg',
      });
      final signed = await request('POST', 'products', {
        'privateName': 'Synthetic signed stock',
        'unit': 'kg',
      });
      for (final delta in ['1', '-1']) {
        await request('POST', 'stock-adjustments', {
          'homeProductId': zero['id'],
          'quantityDelta': delta,
          'reason': 'Synthetic zero evidence',
          'operationId': _uuid(),
        });
      }
      await request('POST', 'stock-adjustments', {
        'homeProductId': signed['id'],
        'quantityDelta': '-1.25',
        'reason': 'Synthetic signed evidence',
        'operationId': _uuid(),
      });
      final store = <String, Object?>{'id': fixture['compatibilityStoreId']};
      final date = DateTime.now().toUtc().toIso8601String().substring(0, 10);
      for (final total in ['8.50']) {
        final receipt = await request('POST', 'receipts', {
          'storeId': store['id'],
          'purchaseDate': date,
          'currency': 'NAD',
          'totalAmount': total,
        });
        late Map<String, Object?> current;
        final line = await request('POST', 'receipts/${receipt['id']}/lines', {
          'expectedReceiptRevision': receipt['revision'],
          'rawDescription': 'Synthetic price evidence',
          'quantity': '1',
          'lineTotal': '10',
        });
        await request(
          'POST',
          'receipts/${receipt['id']}/lines/${line['id']}/approve',
          {'homeProductId': signed['id'], 'expectedRevision': line['revision']},
        );
        current = await request('GET', 'receipts/${receipt['id']}');
        await request('POST', 'receipts/${receipt['id']}/commit', {
          'expectedRevision': current['revision'],
        });
      }
      final api = ProvidentiaApiClient(
        baseUri: base,
        httpClient: wire,
        defaultHeaders: headers,
      );
      final report = await GeneratedHouseholdReportRepository(
        api,
      ).load(homeId: home);
      final unknownFact = report.inventoryFacts.singleWhere(
        (row) => row.homeProductId == unknown['id'],
      );
      final zeroFact = report.inventoryFacts.singleWhere(
        (row) => row.homeProductId == zero['id'],
      );
      expect(unknownFact.factualQuantity, isNull);
      expect(unknownFact.quantityState, 'unknown');
      expect(unknownFact.stockUnit, 'kg');
      expect(unknownFact.limitations, isNotEmpty);
      expect(num.parse(zeroFact.factualQuantity!), 0);
      expect(zeroFact.quantityState, isNot('unknown'));
      final signedFact = report.inventoryFacts.singleWhere(
        (row) => row.homeProductId == signed['id'],
      );
      expect(num.parse(signedFact.factualQuantity!), -0.25);
      expect(signedFact.stockUnit, 'kg');
      // Later purchases retain the signed adjustment in the ledger.
      final movements = await request(
        'GET',
        'stock-movements?homeProductId=${signed['id']}',
      );
      expect(
        (movements['data']! as List)
            .map(_object)
            .any((row) => row['quantityDelta'] == '-1.25'),
        isTrue,
      );
      final purchases = report.purchaseTotals.singleWhere(
        (row) => row.storeId == store['id'],
      );
      expect(purchases.total, isNull);
      expect(purchases.pricingComplete, isFalse);
      expect(purchases.knownSubtotal, '8.5');
      expect(purchases.amountSource, 'partial');
      expect(purchases.receiptCount, 2);
      expect(purchases.completeReceiptCount, 1);
      expect(purchases.unknownReceiptCount, 1);
      final summary = await request('GET', 'purchase-summary');
      expect(summary['pricingComplete'], isFalse);
      expect(summary['spend'], isNull);
    },
  );

  test(
    'partial count eligibility survives authoritative sync and local restart',
    () async {
      final wire = http.Client();
      addTearDown(wire.close);
      final api = ProvidentiaApiClient(
        baseUri: base,
        httpClient: wire,
        defaultHeaders: headers,
      );
      final product = (await api.createHomeProduct(
        homeId: home,
        body: {
          'privateName': 'Synthetic partial count eligibility',
          'unit': 'kg',
        },
      )).requireObject();
      final observedAt = DateTime.now()
          .toUtc()
          .subtract(const Duration(minutes: 1))
          .toIso8601String();
      final session = (await api.startStockCountSession(
        homeId: home,
        body: {
          'locationId': null,
          'notes': 'Synthetic incomplete scope',
          'scopeComplete': false,
          'reliability': 'partial',
          'observedAt': observedAt,
        },
      )).requireObject();
      await api.putStockCountLine(
        homeId: home,
        sessionId: session['id']! as String,
        lineId: _uuid(),
        body: {
          'homeProductId': product['id'],
          'quantity': '0.5',
          'source': 'manual',
          'expectedRevision': 0,
        },
      );
      final current = (await api.getStockCountSession(
        homeId: home,
        sessionId: session['id']! as String,
      )).requireObject();
      await api.closeStockCountSession(
        homeId: home,
        sessionId: session['id']! as String,
        body: {
          'expectedRevision': current['revision'],
          'scopeComplete': false,
          'reliability': 'partial',
        },
      );
      final page = await GeneratedSyncGateway(api).bootstrap(homeId: home);
      final count = page.changes.singleWhere(
        (row) =>
            row.entityType == 'inventory-count-session' &&
            row.entityId == session['id'],
      );
      expect(count.payload['status'], 'closed');
      expect(count.payload['scopeComplete'], isFalse);
      expect(count.payload['reliability'], 'partial');
      final temporary = await Directory.systemTemp.createTemp(
        'providentia-count-adapter-',
      );
      addTearDown(() => temporary.delete(recursive: true));
      final file = File('${temporary.path}/projection.sqlite');
      var database = AppDatabase(NativeDatabase(file));
      await DriftLocalSyncRepository(
        database,
      ).applyPullPage(homeId: home, page: page);
      await database.close();
      database = AppDatabase(NativeDatabase(file));
      addTearDown(() => database.close());
      final persisted = (await database.select(database.localRecords).get())
          .singleWhere(
            (row) =>
                row.entityType == 'inventory-count-session' &&
                row.entityId == session['id'],
          );
      final payload = _object(jsonDecode(persisted.payload));
      expect(payload['scopeComplete'], isFalse);
      expect(payload['reliability'], 'partial');
      expect(payload['observedAt'], count.payload['observedAt']);
      final report = await GeneratedHouseholdReportRepository(
        api,
      ).load(homeId: home);
      final fact = report.inventoryFacts.singleWhere(
        (row) => row.homeProductId == product['id'],
      );
      expect(
        num.parse(fact.factualQuantity!),
        0,
        reason: 'An incomplete count does not replace the whole-home ledger.',
      );
      expect(fact.limitations, isNotEmpty);
      expect(fact.checkpointId, isNull);
    },
  );

  test(
    'a deliberately incompatible response fails the production parser',
    () async {
      final wire = _IncompatibleReportClient();
      addTearDown(wire.close);
      final api = ProvidentiaApiClient(
        baseUri: base,
        httpClient: wire,
        defaultHeaders: headers,
      );
      await expectLater(
        GeneratedHouseholdReportRepository(api).load(homeId: home),
        throwsA(isA<ReportRepositoryException>()),
      );
      expect(wire.injected, isTrue);
    },
  );
}

Map<String, Object?> _object(Object? value) =>
    (value! as Map).cast<String, Object?>();
String _uuid() {
  final random = DateTime.now().microsecondsSinceEpoch
      .toRadixString(16)
      .padLeft(12, '0');
  return '12345678-1234-4234-8234-${random.substring(random.length - 12)}';
}

final class _IncompatibleReportClient extends http.BaseClient {
  final _delegate = http.Client();
  bool injected = false;
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final response = await _delegate.send(request);
    if (request.url.path.endsWith('/reports/inventory') &&
        response.statusCode == 200) {
      final body = _object(jsonDecode(await response.stream.bytesToString()));
      final rows = body['data']! as List;
      expect(rows, isNotEmpty);
      _object(
        rows.first,
      ); // Prove the source response was a real structured row.
      (rows.first as Map<String, Object?>)['quantityState'] =
          'synthetic-incompatible-state';
      injected = true;
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode(body))),
        response.statusCode,
        headers: response.headers,
      );
    }
    return response;
  }

  @override
  void close() => _delegate.close();
}
