// Run only through step2-http-dart.sh against its isolated production API.
// This is deliberately NOT a MockClient or a hand-written response fixture.
import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import 'package:providentia/core/database/app_database.dart';
import 'package:providentia/core/database/drift_household_repository.dart';
import 'package:providentia/core/database/drift_local_sync_repository.dart';
import 'package:providentia/core/synchronization/generated_sync_gateway.dart';
import 'package:providentia/features/ai_integration/infrastructure/generated_server_ai_repository.dart';
import 'package:providentia/features/catalog/domain/catalog_models.dart';
import 'package:providentia/features/catalog/infrastructure/generated_catalog_contribution_repository.dart';
import 'package:providentia/features/catalog_import/infrastructure/generated_catalog_import_transport.dart';
import 'package:providentia/features/inventory/infrastructure/generated_home_item_master_source.dart';
import 'package:providentia_api_client/providentia_api_client.dart';

void main() {
  final fixturePath = Platform.environment['PROVIDENTIA_STEP2_FIXTURE'];
  final phase = Platform.environment['PROVIDENTIA_STEP2_PHASE'];
  test(
    'Step 2 live PHP-to-Dart $phase',
    () async {
      final fixture =
          jsonDecode(await File(fixturePath!).readAsString())
              as Map<String, dynamic>;
      final base = Uri.parse(Platform.environment['PROVIDENTIA_STEP2_URL']!);
      expect(base.scheme, 'http');
      expect(base.host, '127.0.0.1');
      final home = fixture['homeId'] as String;
      final other = fixture['otherHomeId'] as String;
      final sessions = fixture['sessions'] as Map<String, dynamic>;
      final tokenA = sessions['alice']['accessToken'] as String;
      final tokenB = sessions['bob']['accessToken'] as String;
      final alice = ProvidentiaApiClient(
        baseUri: base,
        defaultHeaders: {'Authorization': 'Bearer $tokenA'},
      );
      final bob = ProvidentiaApiClient(
        baseUri: base,
        defaultHeaders: {'Authorization': 'Bearer $tokenB'},
      );
      addTearDown(alice.close);
      addTearDown(bob.close);
      final wire = http.Client();
      addTearDown(wire.close);
      Future<Map<String, Object?>> request(
        String method,
        String path,
        Map<String, Object?>? body, {
        int status = 200,
        String? token,
        Map<String, String> headers = const {},
      }) async {
        final req = http.Request(method, base.resolve(path));
        req.headers.addAll({
          'Authorization': 'Bearer ${token ?? tokenA}',
          'Content-Type': 'application/json',
          ...headers,
        });
        if (body != null) req.body = jsonEncode(body);
        final response = await http.Response.fromStream(await wire.send(req));
        expect(
          response.statusCode,
          status,
          reason: '$method $path: ${response.body}',
        );
        if (response.body.isEmpty) return {};
        return jsonDecode(response.body) as Map<String, Object?>;
      }

      final aiA = GeneratedServerAiRepository(alice);
      final aiB = GeneratedServerAiRepository(bob);
      final prefix = '/api/v1/homes/$home';
      if (phase == 'empty' || phase == 'unavailable') {
        for (final repo in [aiA, aiB]) {
          final workspace = await repo.loadWorkspace(homeId: home);
          expect(workspace.settings.transmissionPlan, isNull);
          expect(workspace.settings.availableProviders, isEmpty);
          if (phase == 'empty') expect(workspace.profiles, isEmpty);
        }
        return;
      }
      final stateFile = File('$fixturePath.state.json');
      late Map<String, dynamic> state;
      if (phase == 'write') {
        final before = await GeneratedSyncGateway(
          alice,
        ).bootstrap(homeId: home);
        final consentRepo = GeneratedCatalogContributionRepository(alice);
        var consent = await consentRepo.loadConsent(homeId: home);
        expect(consent.revision, 0);
        for (var mask = 0; mask < 8; mask++) {
          final flags = [(mask & 1) != 0, (mask & 2) != 0, (mask & 4) != 0];
          consent = await consentRepo.updateConsent(
            homeId: home,
            update: CatalogSharingConsentUpdate(
              shareProductIdentity: flags[0],
              shareProductImages: flags[1],
              shareStorePrices: flags[2],
              expectedRevision: consent.revision,
            ),
          );
          final reopened = await GeneratedCatalogContributionRepository(
            alice,
          ).loadConsent(homeId: home);
          expect([
            reopened.shareProductIdentity,
            reopened.shareProductImages,
            reopened.shareStorePrices,
          ], flags);
          expect(reopened.revision, mask + 1);
        }
        await consentRepo.updateConsent(
          homeId: home,
          update: CatalogSharingConsentUpdate(
            shareProductIdentity: false,
            shareProductImages: false,
            shareStorePrices: false,
            expectedRevision: 8,
          ),
        );
        final catalog = fixture['catalog'] as Map<String, dynamic>;
        final importer = GeneratedCatalogImportTransport(alice);
        final records = <Map<String, Object?>>[
          {
            'recordType': 'home_product',
            'name': catalog['name']['name'],
            'packText': 'Original name-only wording',
          },
          {
            'recordType': 'home_product',
            'productId': catalog['product']['productId'],
            'packText': 'Original family-only wording',
          },
          {
            'recordType': 'home_product',
            'packId': catalog['pack']['packId'],
            'packText': 'Explicit source pack wording',
          },
          {
            'recordType': 'home_product',
            'barcode': 'STEP2-EXACT-PACK',
            'packText': 'Barcode source wording',
          },
        ];
        final staged = await importer.stage(
          homeId: home,
          idempotencyKey: 'step2-live-import',
          records: records,
        );
        expect(staged.validCount, 4);
        expect(staged.errorCount, 0);
        final replay = await importer.stage(
          homeId: home,
          idempotencyKey: 'step2-live-import',
          records: records,
        );
        expect(replay.id, staged.id);
        final confirmed = await importer.confirm(
          homeId: home,
          importId: staged.id,
          expectedRevision: staged.revision,
        );
        expect(confirmed.importedCount, 4);
        final fetched = await GeneratedCatalogImportTransport(
          alice,
        ).fetch(homeId: home, importId: staged.id);
        expect(fetched.importedCount, 4);
        final items = await GeneratedHomeItemMasterSource(
          alice,
        ).loadAll(homeId: home);
        final family = items.singleWhere(
          (item) =>
              item.productId == catalog['product']['productId'] &&
              item.packId == null,
        );
        expect(family.packSize, 'Original family-only wording');
        expect(
          items.where((item) => item.hasUnresolvedCatalogPack),
          hasLength(2),
        );
        final packOnly = await request('POST', '$prefix/products', {
          'packId': catalog['name']['packId'],
          'originalPackText': 'Pack-only direct creation wording',
        }, status: 201);
        final private = await request('POST', '$prefix/products', {
          'privateName': 'Synthetic private item',
          'originalPackText': 'Private original wording',
        }, status: 201);
        await request(
          'POST',
          '$prefix/stock-adjustments',
          {
            'homeProductId': family.id,
            'quantityDelta': '7.5',
            'reason': 'Synthetic count',
          },
          status: 201,
          headers: {'Idempotency-Key': 'step2-live-quantity'},
        );
        final delta = await GeneratedSyncGateway(
          alice,
        ).pull(homeId: home, afterCursor: before.pageCursor);
        final productChanges = delta.changes
            .where((change) => change.entityType == 'inventory-home-product')
            .toList();
        expect(productChanges.length, greaterThanOrEqualTo(6));
        for (final change in productChanges) {
          if (change.payload['packId'] != null)
            expect(change.payload['productId'], isNotNull);
        }
        await request('PUT', '$prefix/ai/settings', {
          'mode': 'server_proxy',
          'provider': 'ollama',
          'model': 'synthetic',
          'expectedRevision': 0,
        });
        expect(
          (await aiA.loadWorkspace(homeId: home)).settings.transmissionPlan,
          isNull,
        );
        Future<String> profile(String label, String owner, String token) async {
          final result = await request(
            'POST',
            '$prefix/ai/profiles',
            {
              'label': label,
              'ownerScope': owner,
              'provider': 'ollama',
              'model': label,
              'endpoint': 'http://127.0.0.1:19999/$label',
              'estimatedCostMicros': 0,
              'expectedRevision': 0,
            },
            token: token,
            status: 201,
          );
          return result['id']! as String;
        }

        final privateA = await profile('alice-private', 'private', tokenA);
        final privateB = await profile('bob-private', 'private', tokenB);
        Map<String, Object?> policy(String id) => {
          'extractionProfileIds': [id],
          'validationProfileId': null,
          'maxAttempts': 2,
          'maxTotalTokens': 10000,
          'maxEstimatedCostMicros': 100000,
          'expectedRevision': 0,
        };
        await request(
          'PUT',
          '$prefix/ai/orchestration-policy',
          policy(privateA),
          status: 422,
        );
        final shared = await profile('shared-profile', 'home', tokenA);
        await request('PUT', '$prefix/ai/orchestration-policy', policy(shared));
        final a = await aiA.loadWorkspace(homeId: home);
        final b = await aiB.loadWorkspace(homeId: home);
        expect(a.policy.extractionProfileIds, [shared]);
        expect(b.policy.extractionProfileIds, [shared]);
        expect(a.settings.transmissionPlan!.primary.profileId, privateA);
        expect(b.settings.transmissionPlan!.primary.profileId, privateB);
        expect(a.profiles.any((profile) => profile.id == privateB), isFalse);
        expect(b.profiles.any((profile) => profile.id == privateA), isFalse);
        await request(
          'DELETE',
          '$prefix/ai/profiles/$privateA?expectedRevision=1',
          null,
          token: tokenB,
          status: 404,
        );
        await request(
          'PUT',
          '$prefix/ai/orchestration-policy',
          policy(shared),
          status: 409,
        );
        await request('PUT', '$prefix/ai/profiles/$privateB', {
          'label': 'bob-private',
          'ownerScope': 'private',
          'provider': 'ollama',
          'model': 'bob-revised',
          'endpoint': 'http://127.0.0.1:19999/bob-private',
          'estimatedCostMicros': 0,
          'expectedRevision': 1,
        }, token: tokenB);
        final changed = await aiB.loadWorkspace(homeId: home);
        expect(
          changed.settings.transmissionPlan!.sha256,
          isNot(b.settings.transmissionPlan!.sha256),
        );
        final upload =
            http.MultipartRequest(
                'POST',
                base.resolve('$prefix/ai/extractions'),
              )
              ..headers['Authorization'] = 'Bearer $tokenB'
              ..fields.addAll({
                'kind': 'stock',
                'transmissionConsent': 'true',
                'transmissionPlanHash': b.settings.transmissionPlan!.sha256,
                'selectedProfileId': privateB,
              })
              ..files.add(
                http.MultipartFile.fromBytes(
                  'image',
                  base64Decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=',
                  ),
                  filename: 'synthetic.png',
                  contentType: MediaType('image', 'png'),
                ),
              );
        final rejected = await http.Response.fromStream(
          await wire.send(upload),
        );
        expect(rejected.statusCode, 409, reason: rejected.body);
        await request(
          'GET',
          '/api/v1/homes/$other/ai/settings',
          null,
          status: 404,
        );
        state = {
          'familyId': family.id,
          'productId': family.productId,
          'packOnlyId': packOnly['id'],
          'privateId': private['id'],
          'privateA': privateA,
          'privateB': privateB,
          'shared': shared,
        };
        await stateFile.writeAsString(jsonEncode(state));
      } else {
        state =
            jsonDecode(await stateFile.readAsString()) as Map<String, dynamic>;
      }
      final consent = await GeneratedCatalogContributionRepository(
        alice,
      ).loadConsent(homeId: home);
      expect(consent.revision, 9);
      expect(
        [
          consent.shareProductIdentity,
          consent.shareProductImages,
          consent.shareStorePrices,
        ],
        [false, false, false],
      );
      final a = await aiA.loadWorkspace(homeId: home);
      final b = await aiB.loadWorkspace(homeId: home);
      expect(a.settings.transmissionPlan!.primary.profileId, state['privateA']);
      expect(b.settings.transmissionPlan!.primary.profileId, state['privateB']);
      expect(b.settings.transmissionPlan!.primary.model, 'bob-revised');
      final file = File('$fixturePath.device.sqlite');
      final database = AppDatabase(NativeDatabase(file));
      try {
        final repository = DriftHouseholdRepository(database);
        if (phase == 'read') {
          final oldItems = await repository.watchItems(homeId: home).first;
          expect(
            oldItems
                .singleWhere((item) => item.id == state['familyId'])
                .currentQuantity,
            7.5,
          );
        }
        final bootstrap = await GeneratedSyncGateway(
          bob,
        ).bootstrap(homeId: home);
        await DriftLocalSyncRepository(
          database,
        ).replaceWithBootstrap(homeId: home, page: bootstrap);
        await repository.replaceCatalogItemMaster(
          homeId: home,
          items: await GeneratedHomeItemMasterSource(bob).loadAll(homeId: home),
        );
        final items = await repository.watchItems(homeId: home).first;
        final family = items.singleWhere(
          (item) => item.id == state['familyId'],
        );
        expect(family.productId, state['productId']);
        expect(family.packId, isNull);
        expect(family.hasUnresolvedCatalogPack, isTrue);
        expect(family.packSize, 'Original family-only wording');
        expect(family.currentQuantity, 7.5);
        expect(
          items
              .singleWhere((item) => item.id == state['privateId'])
              .canonicalName,
          'Synthetic private item',
        );
        expect(
          items.singleWhere((item) => item.id == state['packOnlyId']).productId,
          isNotNull,
        );
        expect(await database.select(database.clientOperations).get(), isEmpty);
      } finally {
        await database.close();
      }
    },
    skip: fixturePath == null
        ? 'Requires the isolated live backend matrix.'
        : false,
    timeout: const Timeout(Duration(minutes: 4)),
  );
}
