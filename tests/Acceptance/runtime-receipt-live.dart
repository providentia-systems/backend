// Run against the guarded synthetic backend fixture, never a production service.
// Copy into the matching Client's test/integration directory and use flutter test.
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:providentia/core/database/app_database.dart';
import 'package:providentia/core/database/drift_household_repository.dart';
import 'package:providentia/core/database/drift_local_sync_repository.dart';
import 'package:providentia/core/synchronization/generated_sync_gateway.dart';
import 'package:providentia/core/synchronization/session_bound_sync_gateway.dart';
import 'package:providentia/core/synchronization/sync_coordinator.dart';
import 'package:providentia/core/synchronization/sync_models.dart';
import 'package:providentia/core/synchronization/sync_ports.dart';
import 'package:providentia/features/purchasing/domain/purchase_models.dart';
import 'package:providentia/features/purchasing/presentation/purchasing_controller.dart';
import 'package:providentia/features/reporting/application/household_report_service.dart';
import 'package:providentia/features/reporting/application/reporting_controller.dart';
import 'package:providentia/features/reporting/infrastructure/generated_household_report_repository.dart';
import 'package:providentia/features/shopping/presentation/shopping_controller.dart';
import 'package:providentia_api_client/providentia_api_client.dart';

void main() {
  final fixturePath = Platform.environment['PROVIDENTIA_RESPONSE_FIXTURE'];
  final base = Uri.parse(
    Platform.environment['PROVIDENTIA_RESPONSE_URL'] ?? '',
  );
  if (fixturePath == null ||
      base.scheme != 'http' ||
      base.host != '127.0.0.1') {
    throw StateError(
      'An explicit isolated loopback fixture and URL are required.',
    );
  }
  final fixture = _object(jsonDecode(File(fixturePath).readAsStringSync()));
  final session = _object(_object(fixture['sessions'])['alice']);
  final home = fixture['homeId']! as String;
  final headers = <String, String>{
    'Authorization': 'Bearer ${session['accessToken']}',
    'Content-Type': 'application/json',
  };

  for (final fault in [
    'lost-response',
    'malformed-success',
    'status-unavailable',
    'conflict',
  ]) {
    test(
      'real receipt $fault keeps one intent and truthful completion',
      () async {
        final client = _FaultClient(fault);
        addTearDown(client.close);
        final api = ProvidentiaApiClient(
          baseUri: base,
          httpClient: client,
          defaultHeaders: headers,
        );
        final response = await client.post(
          base.resolve('/api/v1/homes/$home/products'),
          headers: headers,
          body: jsonEncode({
            'privateName': 'Synthetic rice $fault',
            'unit': 'g',
          }),
        );
        expect(response.statusCode, 201);
        final product = _object(jsonDecode(response.body))['id']! as String;
        final directory = await Directory.systemTemp.createTemp(
          'providentia-receipt-live-',
        );
        addTearDown(() => directory.delete(recursive: true));
        final file = File('${directory.path}/projection.sqlite');
        var now = DateTime.now().toUtc();
        var database = AppDatabase(NativeDatabase(file));
        addTearDown(() => database.close());
        DriftHouseholdRepository repository() => DriftHouseholdRepository(
          database,
          deviceId: session['deviceId']! as String,
          originatingAccountId: session['userId']! as String,
          clock: () => now,
        );
        var household = repository();
        SyncCoordinator coordinator() => SyncCoordinator(
          local: DriftLocalSyncRepository(database),
          remote: SessionBoundSyncGateway(
            delegate: GeneratedSyncGateway(api),
            homeId: home,
            deviceId: session['deviceId']! as String,
            accountId: session['userId']! as String,
            isCurrent: () => true,
          ),
          connectivity: const _Online(),
          clock: () => now,
        );
        Future<void> sync() async {
          final outcome = await coordinator().synchronize(home);
          expect(
            outcome.status,
            SyncRunStatus.completed,
            reason: outcome.safeMessage,
          );
        }

        await sync();
        final receipt = await household.createReceiptDraft(
          PurchaseReceiptDraftRequest(
            homeId: home,
            purchaseDate: now,
            currency: 'NAD',
            total: Money(minorUnits: 2550, currency: 'NAD'),
          ),
        );
        await sync();
        final line = await household.addReceiptLine(
          PurchaseReceiptLineRequest(
            homeId: home,
            receiptId: receipt.entityId,
            rawDescription: 'Synthetic rice $fault',
            quantity: 1.25,
            unitPrice: Money(minorUnits: 2040, currency: 'NAD'),
            lineTotal: Money(minorUnits: 2550, currency: 'NAD'),
          ),
        );
        await sync();
        await household.approveReceiptLine(
          homeId: home,
          receiptId: receipt.entityId,
          lineId: line.entityId,
          homeProductId: product,
        );
        await sync();
        var controller = PurchasingController(
          repository: household,
          homeId: home,
          mayWrite: true,
        )..start();
        addTearDown(() => controller.dispose());
        await _until(() => controller.state.capture?.reviewComplete == true);
        expect(await controller.commitDraft(), isTrue);
        final queued = (await database.select(database.clientOperations).get())
            .singleWhere(
              (row) => row.operationType == 'purchasing.receipt.commit',
            );
        final operationId = queued.operationId;
        final immutablePayload = queued.payload;
        final cursorBefore = await DriftLocalSyncRepository(
          database,
        ).cursorForHome(home);
        if (fault == 'conflict') {
          final receiptUrl = base.resolve(
            '/api/v1/homes/$home/receipts/${receipt.entityId}',
          );
          final current = _object(
            jsonDecode((await client.get(receiptUrl, headers: headers)).body),
          );
          final edited = await client.put(
            receiptUrl,
            headers: headers,
            body: jsonEncode({
              'expectedRevision': current['revision'],
              'notes': 'Synthetic concurrent edit',
              for (final key in [
                'storeId',
                'purchaseDate',
                'currency',
                'totalAmount',
              ])
                key: current[key],
            }),
          );
          expect(edited.statusCode, 200);
          client.armed = true;
          final rejected = await coordinator().synchronize(home);
          expect(rejected.status, SyncRunStatus.uploadsBlocked);
          expect(rejected.safeMessage, contains('need attention'));
          final blocked =
              (await database.select(database.clientOperations).get())
                  .singleWhere((row) => row.operationId == operationId);
          expect(
            blocked.state,
            ClientOperationState.blockedConflict.storageValue,
          );
          expect(blocked.payload, immutablePayload);
          expect(client.commitPushes, 1);
          expect(controller.state.capture?.commitConfirmed, isFalse);
          expect(
            controller.state.captureNotice,
            isNot(contains('synchronized')),
          );
          await coordinator().synchronize(home);
          expect(
            client.commitPushes,
            1,
            reason: 'A conflict cannot blindly retry.',
          );
          final actual = _object(
            jsonDecode((await client.get(receiptUrl, headers: headers)).body),
          );
          expect(actual['status'], 'draft');
          final effects = _object(
            jsonDecode(
              (await client.get(
                base.resolve('/api/v1/homes/$home/stock-movements'),
                headers: headers,
              )).body,
            ),
          );
          expect(
            (effects['data']! as List)
                .map(_object)
                .where((row) => row['homeProductId'] == product),
            isEmpty,
          );
          return;
        }
        client.armed = true;
        client.corruptReadback = true;
        client.statusUnavailable = fault == 'status-unavailable';
        final interrupted = await coordinator().synchronize(home);
        expect(
          client.injected,
          isTrue,
          reason:
              'Fault must follow a real successful server commit: ${client.realCommitResult}',
        );
        expect(interrupted.status, SyncRunStatus.retryableFailure);
        expect(client.commitPushes, 1);
        if (client.statusUnavailable) {
          await _until(() => controller.state.capture != null);
          expect(controller.state.capture!.commitConfirmed, isFalse);
          expect(controller.state.capture!.commitAwaitingConfirmation, isTrue);
          now = now.add(const Duration(minutes: 5));
          final stillUncertain = await coordinator().synchronize(home);
          expect(stillUncertain.status, SyncRunStatus.retryableFailure);
          expect(
            client.commitPushes,
            1,
            reason: 'Unavailable status cannot authorize another push.',
          );
          client.statusUnavailable = false;
          now = now.add(const Duration(minutes: 5));
          final known = await coordinator().synchronize(home);
          expect(
            known.status,
            SyncRunStatus.retryableFailure,
            reason: 'Corrupt readback remains blocked.',
          );
        }
        await _until(() => controller.state.capture?.commitConfirmed == true);
        expect(controller.state.capture!.commitAwaitingConfirmation, isFalse);
        expect(controller.state.capture!.commitAwaitingReadback, isTrue);
        expect(controller.state.captureNotice, contains('confirmed'));
        expect(
          await DriftLocalSyncRepository(database).cursorForHome(home),
          cursorBefore,
          reason: 'Invalid receipt data must not advance the durable cursor.',
        );
        expect(await controller.commitDraft(), isTrue);
        final repeated =
            (await database.select(database.clientOperations).get())
                .where(
                  (row) => row.operationType == 'purchasing.receipt.commit',
                )
                .toList();
        expect(repeated, hasLength(1));
        expect(repeated.single.operationId, operationId);
        expect(repeated.single.payload, immutablePayload);
        expect(
          repeated.single.state,
          ClientOperationState.acknowledged.storageValue,
        );
        controller.dispose();
        await database.close();
        database = AppDatabase(NativeDatabase(file));
        household = repository();
        controller = PurchasingController(
          repository: household,
          homeId: home,
          mayWrite: true,
        )..start();
        await _until(() => controller.state.capture?.commitConfirmed == true);
        client.corruptReadback = false;
        await sync();
        await _until(
          () =>
              controller.state.capture == null &&
              controller.state.lines.any(
                (row) => row.receiptId == receipt.entityId,
              ),
        );
        expect(controller.state.captureError, isNull);
        expect(controller.state.safeError, isNull);
        expect(
          controller.state.captureNotice,
          'The receipt commit is synchronized.',
        );
        final purchase = controller.state.lines.singleWhere(
          (row) => row.receiptId == receipt.entityId,
        );
        expect(purchase.quantity, 1.25);
        expect(purchase.lineTotal!.minorUnits, 2550);
        expect(purchase.pendingSynchronization, isFalse);
        expect(client.commitPushes, 1);
        final moves = _object(
          jsonDecode(
            (await client.get(
              base.resolve('/api/v1/homes/$home/stock-movements'),
              headers: headers,
            )).body,
          ),
        );
        final effects = (moves['data']! as List<Object?>)
            .cast<Map<String, Object?>>()
            .where((row) => row['homeProductId'] == product)
            .toList();
        expect(effects, hasLength(1));
        expect(effects.single['quantityDelta'], isA<String>());
        expect(num.parse(effects.single['quantityDelta']! as String), 1.25);
        final report = ReportingController(
          service: HouseholdReportService(
            GeneratedHouseholdReportRepository(api),
          ),
          activeHomeId: home,
        );
        addTearDown(report.dispose);
        await report.load();
        expect(report.status, ReportingStatus.ready);
        expect(client.statusLookups, greaterThan(0));
        expect(client.corruptedPages, greaterThan(0));
      },
      timeout: const Timeout(Duration(minutes: 2)),
    );
  }

  test(
    'real shopping controller preserves readback and checked lifecycle',
    () async {
      final client = http.Client();
      addTearDown(client.close);
      final api = ProvidentiaApiClient(
        baseUri: base,
        httpClient: client,
        defaultHeaders: headers,
      );
      final directory = await Directory.systemTemp.createTemp(
        'providentia-shopping-live-',
      );
      addTearDown(() => directory.delete(recursive: true));
      final file = File('${directory.path}/projection.sqlite');
      var database = AppDatabase(NativeDatabase(file));
      addTearDown(() => database.close());
      DriftHouseholdRepository repository() => DriftHouseholdRepository(
        database,
        deviceId: session['deviceId']! as String,
        originatingAccountId: session['userId']! as String,
      );
      var household = repository();
      var controller = ShoppingController(repository: household, homeId: home)
        ..start();
      addTearDown(() => controller.dispose());
      Future<void> sync() async {
        final outcome = await SyncCoordinator(
          local: DriftLocalSyncRepository(database),
          remote: SessionBoundSyncGateway(
            delegate: GeneratedSyncGateway(api),
            homeId: home,
            deviceId: session['deviceId']! as String,
            accountId: session['userId']! as String,
            isCurrent: () => true,
          ),
          connectivity: const _Online(),
        ).synchronize(home);
        expect(
          outcome.status,
          SyncRunStatus.completed,
          reason: outcome.safeMessage,
        );
        final selected = controller.state.list?.id;
        final rows = await database.select(database.localRecords).get();
        final row = rows
            .where(
              (row) =>
                  row.entityType == 'shopping-list' && row.entityId == selected,
            )
            .firstOrNull;
        if (row != null) {
          await _until(() => controller.state.list?.revision == row.revision);
        }
        expect(controller.state.safeError, isNull);
      }

      await sync();
      expect(
        await controller.createList('Synthetic shopping lifecycle'),
        isTrue,
      );
      final listId = controller.state.list!.id;
      await sync();
      expect(
        await controller.addManual('Synthetic apples', quantity: 2),
        isTrue,
      );
      await sync();
      await _until(() => controller.state.list?.lines.length == 1);
      final lineId = controller.state.list!.lines.single.id;
      expect(controller.state.list!.lines.single.quantity, 2);
      expect(controller.state.list!.lines.single.checked, isFalse);
      await controller.toggle(lineId);
      await sync();
      await _until(() => controller.state.list!.lines.single.checked);
      expect(
        await controller.editLine(
          controller.state.list!.lines.single,
          name: 'Synthetic apples edited',
          quantity: 2.5,
        ),
        isTrue,
      );
      await sync();
      final url = base.resolve('/api/v1/homes/$home/shopping-lists/$listId');
      final actual = _object(
        jsonDecode((await client.get(url, headers: headers)).body),
      );
      final actualLine = _object((actual['lines']! as List).single);
      expect(actualLine['id'], lineId);
      expect(actualLine['checked'], isTrue);
      expect(actualLine['quantityToBuy'], isA<String>());
      expect(num.parse(actualLine['quantityToBuy']! as String), 2.5);
      expect(actualLine['description'], 'Synthetic apples edited');
      controller.dispose();
      await database.close();
      database = AppDatabase(NativeDatabase(file));
      household = repository();
      controller = ShoppingController(repository: household, homeId: home)
        ..start();
      await _until(() => controller.lists.any((list) => list.id == listId));
      controller.selectList(listId);
      expect(controller.state.list!.lines.single.checked, isTrue);
      expect(controller.state.list!.lines.single.quantity, 2.5);
      expect(
        await controller.editLine(
          controller.state.list!.lines.single,
          archived: true,
        ),
        isTrue,
      );
      await sync();
      await _until(() => controller.state.list!.activeLines.isEmpty);
      expect(
        await controller.editLine(
          controller.state.list!.lines.single,
          archived: false,
        ),
        isTrue,
      );
      await sync();
      await _until(() => controller.state.list!.activeLines.length == 1);
      expect(await controller.updateList(archived: true), isTrue);
      await sync();
      expect(await controller.updateList(archived: false), isTrue);
      await sync();
      final finalList = _object(
        jsonDecode((await client.get(url, headers: headers)).body),
      );
      expect(finalList['status'], 'open');
      expect((_object((finalList['lines']! as List).single))['id'], lineId);
    },
    timeout: const Timeout(Duration(minutes: 2)),
  );
}

Map<String, Object?> _object(Object? value) =>
    (value! as Map).cast<String, Object?>();
Future<void> _until(bool Function() condition) async {
  for (var attempt = 0; attempt < 200; attempt++) {
    if (condition()) return;
    await Future<void>.delayed(const Duration(milliseconds: 10));
  }
  fail('Expected local projection state did not arrive.');
}

final class _Online implements ConnectivityProbe {
  const _Online();
  @override
  Future<ConnectivityResult> check() async => const ConnectivityResult.online();
}

final class _FaultClient extends http.BaseClient {
  _FaultClient(this.fault);
  final String fault;
  final http.Client _delegate = http.Client();
  bool armed = false,
      injected = false,
      corruptReadback = false,
      statusUnavailable = false;
  int commitPushes = 0, statusLookups = 0, corruptedPages = 0;
  Object? realCommitResult;
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final isCommit =
        armed &&
        request is http.Request &&
        request.url.path.endsWith('/sync/push') &&
        request.body.contains('purchasing.receipt.commit');
    if (isCommit) commitPushes++;
    if (armed && request.url.path.endsWith('/sync/operation-status')) {
      statusLookups++;
      if (statusUnavailable) {
        return http.StreamedResponse(
          Stream.value(utf8.encode('{"title":"Synthetic status unavailable"}')),
          503,
          headers: {'content-type': 'application/problem+json'},
        );
      }
    }
    final response = await _delegate.send(request);
    if (isCommit && !injected && fault != 'conflict') {
      final body = await response.stream.bytesToString();
      if (response.statusCode != 200) {
        return http.StreamedResponse(
          Stream.value(utf8.encode(body)),
          response.statusCode,
          headers: response.headers,
        );
      }
      final committed = _object(jsonDecode(body));
      realCommitResult = committed['results'];
      if (_object((committed['results']! as List).single)['status'] !=
          'accepted') {
        throw StateError('Real commit did not succeed: $realCommitResult');
      }
      injected = true;
      if (fault != 'malformed-success') {
        throw http.ClientException(
          'Synthetic lost response after completed server request.',
        );
      }
      final malformed = _object(jsonDecode(body))
        ..['results'] = 'synthetic malformed success';
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode(malformed))),
        response.statusCode,
        headers: response.headers,
      );
    }
    if (corruptReadback &&
        request.method == 'GET' &&
        (request.url.path.endsWith('/sync/pull') ||
            request.url.path.endsWith('/sync/bootstrap'))) {
      final decoded = jsonDecode(await response.stream.bytesToString());
      void corrupt(Object? value) {
        if (value is Map<String, Object?>) {
          if (value.containsKey('purchaseDate') &&
              value.containsKey('currency') &&
              value.containsKey('totalAmount')) {
            value['totalAmount'] = 25.5;
            corruptedPages++;
          }
          if (value.containsKey('approvalStatus') &&
              value.containsKey('quantity')) {
            value['quantity'] = 1.25;
            corruptedPages++;
          }
          for (final child in value.values) {
            corrupt(child);
          }
        } else if (value is List<Object?>) {
          for (final child in value) {
            corrupt(child);
          }
        }
      }

      corrupt(decoded);
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode(decoded))),
        response.statusCode,
        headers: response.headers,
      );
    }
    return response;
  }

  @override
  void close() => _delegate.close();
}
