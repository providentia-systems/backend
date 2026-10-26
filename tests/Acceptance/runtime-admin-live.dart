// Copied into the intentionally pinned Admin checkout and run with flutter test.
// All requests use its production transport/adapters against synthetic loopback data.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:providentia_admin/core/api/api_client.dart';
import 'package:providentia_admin/core/security/secure_id.dart';
import 'package:providentia_admin/features/access/access_repository.dart';
import 'package:providentia_admin/features/workspace/operator_inventory_repository.dart';

void main() {
  final fixturePath = Platform.environment['PROVIDENTIA_RESPONSE_FIXTURE'];
  final base = Uri.parse(
    Platform.environment['PROVIDENTIA_RESPONSE_URL'] ?? '',
  );
  if (fixturePath == null ||
      base.scheme != 'http' ||
      base.host != '127.0.0.1' ||
      base.userInfo.isNotEmpty ||
      base.hasQuery ||
      base.hasFragment) {
    throw StateError('An explicit isolated loopback fixture is required.');
  }
  final fixture = _object(jsonDecode(File(fixturePath).readAsStringSync()));
  final sessions = _object(fixture['sessions']);
  final alice = _object(sessions['alice']);
  final bob = _object(sessions['bob']);
  final home = fixture['homeId']! as String;
  final otherHome = fixture['otherHomeId']! as String;

  ApiClient authenticated(Map<String, Object?> session) {
    final api = ApiClient(
      baseUri: validateBackendUri(base),
      httpClient: http.Client(),
      accessTokenProvider: () => session['accessToken']! as String,
      ensureAccessToken: ({required bool force}) async => true,
      onAuthorizationLost: () => fail('The valid native session was rejected.'),
    );
    addTearDown(api.close);
    return api;
  }

  test(
    'native Admin writes, parses revisions and reloads persisted stock',
    () async {
      var api = authenticated(alice);
      var inventory = OperatorInventoryRepository(api);
      final categoryId = newUuidV4();
      final productId = newUuidV4();
      final categoryName = 'Synthetic native compatibility category';
      final revisedCategoryName = '$categoryName revised';
      const productName = 'Synthetic native compatibility product';
      const packText = 'Synthetic unit pack';

      // These are the same revision-bound repository methods used by the editors.
      await inventory.saveCategory(
        homeId: home,
        id: categoryId,
        expectedRevision: 0,
        reason: 'Synthetic adapter acceptance',
        name: categoryName,
      );
      final created = (await inventory.categories(
        home,
      )).singleWhere((row) => row.id == categoryId);
      expect(created.kind, OperatorInventoryKind.category);
      expect(created.name, categoryName);
      expect(created.status, 'active');
      expect(created.revision, 1);

      await inventory.saveCategory(
        homeId: home,
        id: categoryId,
        expectedRevision: created.revision,
        reason: 'Synthetic revision update',
        name: revisedCategoryName,
      );
      await expectLater(
        inventory.saveCategory(
          homeId: home,
          id: categoryId,
          expectedRevision: created.revision,
          reason: 'Synthetic stale edit must fail',
          name: 'Synthetic stale value',
        ),
        throwsA(
          isA<ApiException>().having(
            (error) => error.isConflict,
            'conflict',
            isTrue,
          ),
        ),
      );

      await inventory.saveProduct(
        homeId: home,
        id: productId,
        expectedRevision: 0,
        reason: 'Synthetic private product',
        setPrivateName: true,
        setPackText: true,
        setCategory: true,
        privateName: productName,
        packText: packText,
        homeCategoryId: categoryId,
      );
      final productRows = records(
        (await api.get(
          '/api/v1/admin/homes/$home/records/products',
        )).jsonObject,
      );
      final productRow = productRows.singleWhere(
        (row) => row['id'] == productId,
      );
      final product = OperatorInventoryRecord.fromRow(
        OperatorInventoryKind.product,
        productRow,
      );
      expect(product.name, productName);
      expect(product.packText, packText);
      expect(product.homeCategoryId, categoryId);
      expect(product.catalogBacked, isFalse);
      expect(product.revision, 1);

      // Only fixture setup uses the household adjustment endpoint. Admin stock
      // remains read-only; the production Admin transport still authenticates it.
      await api.post(
        '/api/v1/homes/$home/stock-adjustments',
        body: {
          'homeProductId': productId,
          'quantityDelta': '-2.12500001',
          'reason': 'Synthetic signed ledger evidence',
          'operationId': newUuidV4(),
        },
      );
      final stockPath = '/api/v1/admin/homes/$home/records/stock';
      final stock = records(
        (await api.get(stockPath)).jsonObject,
      ).singleWhere((row) => row['home_product_id'] == productId);
      expect(stock['home_id'], home);
      expect(stock['quantity'], '-2.12500001');
      expect(stock['unit'], 'units');
      expect(stock['name'], productName);
      expect(stock['brand'], '');
      expect(stock['pack_text'], packText);
      expect(stock['category_name'], revisedCategoryName);
      expect(stock['category_scope'], 'Local');
      expect(stock['catalog_reference'], 'Private product');
      expect(stock['home_product_status'], 'active');
      expect(stock['product_id'], isNull);
      expect(stock['pack_id'], isNull);
      expect(stock['catalog_product_status'], isNull);
      expect(stock['catalog_pack_status'], isNull);
      final revision = integer(stock['revision']);
      expect(revision, greaterThanOrEqualTo(1));

      // Closing the native transport cannot erase or replace server revisions.
      // Recreate the actual adapter and read authoritative data again.
      api.close();
      api = authenticated(alice);
      inventory = OperatorInventoryRepository(api);
      final persisted = (await inventory.categories(
        home,
      )).singleWhere((row) => row.id == categoryId);
      expect(persisted.name, revisedCategoryName);
      expect(persisted.revision, 2);
      final reloaded = records(
        (await api.get(stockPath)).jsonObject,
      ).singleWhere((row) => row['home_product_id'] == productId);
      expect(reloaded['quantity'], stock['quantity']);
      expect(reloaded['revision'], stock['revision']);
      expect(reloaded['name'], productName);
      expect(
        records(
          (await api.get(
            '/api/v1/admin/homes/$otherHome/records/stock',
          )).jsonObject,
        ).where((row) => row['home_product_id'] == productId),
        isEmpty,
      );
    },
  );

  test(
    'native Admin resource denial preserves the ordinary member session',
    () async {
      var forbidden = 0;
      var invalidated = 0;
      final api = ApiClient(
        baseUri: validateBackendUri(base),
        httpClient: http.Client(),
        accessTokenProvider: () => bob['accessToken']! as String,
        ensureAccessToken: ({required bool force}) async => true,
        onAuthorizationLost: () => invalidated++,
        onResourceForbidden: () => forbidden++,
      );
      addTearDown(api.close);
      await expectLater(
        api.get('/api/v1/admin/homes/$home/records/stock'),
        throwsA(
          isA<ApiException>().having(
            (error) => error.isForbidden,
            'forbidden',
            isTrue,
          ),
        ),
      );
      expect(forbidden, 1);
      expect(invalidated, 0);
      // Bob is a fixture home manager but has no operator grant.
      final authorized = await api.get('/api/v1/homes/$home/stock');
      expect(authorized.statusCode, 200);
      expect(authorized.jsonObject['data'], isA<List<Object?>>());
      expect(invalidated, 0);
    },
  );
}

Map<String, Object?> _object(Object? value) =>
    (value! as Map).cast<String, Object?>();
