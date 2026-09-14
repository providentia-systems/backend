// Isolated actual PHP HTTP and encrypted worker; no mocked server responses.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:providentia/features/data_governance/application/data_governance_service.dart';
import 'package:providentia/features/data_governance/domain/data_governance_models.dart';
import 'package:providentia/features/data_governance/infrastructure/generated_data_governance_repository.dart';
import 'package:providentia_api_client/providentia_api_client.dart';

void main() {
  test('real export worker and production adapter ${Platform.environment['PROVIDENTIA_STEP3_PHASE']}', () async {
    final path = Platform.environment['PROVIDENTIA_STEP2_FIXTURE']!;
    final fixture = jsonDecode(await File(path).readAsString()) as Map<String, dynamic>;
    final base = Uri.parse(Platform.environment['PROVIDENTIA_STEP2_URL']!);
    expect(base.host, '127.0.0.1');
    final home = fixture['homeId'] as String;
    final other = fixture['otherHomeId'] as String;
    final sessions = fixture['sessions'] as Map<String, dynamic>;
    final tokenA = sessions['alice']['accessToken'] as String;
    final tokenB = sessions['bob']['accessToken'] as String;
    final a = ProvidentiaApiClient(baseUri: base, defaultHeaders: {'Authorization': 'Bearer $tokenA'});
    final b = ProvidentiaApiClient(baseUri: base, defaultHeaders: {'Authorization': 'Bearer $tokenB'});
    addTearDown(a.close);
    addTearDown(b.close);
    final alice = GeneratedDataGovernanceRepository(a);
    final bob = GeneratedDataGovernanceRepository(b);
    final stateFile = File('$path.state.json');
    if (Platform.environment['PROVIDENTIA_STEP3_PHASE'] == 'request') {
      final account = await alice.requestAccountExport();
      final household = await alice.requestHomeExport(homeId: home);
      final cancelled = await bob.requestAccountExport();
      expect(account.status, DataGovernanceRequestStatus.queued);
      expect(household.downloadEligible, isFalse);
      await bob.cancelRequest(requestId: cancelled.id, expectedRevision: cancelled.revision);
      await stateFile.writeAsString(jsonEncode({'account': account.id, 'home': household.id, 'cancelled': cancelled.id}));
      await expectLater(alice.listHomeRequests(homeId: other), throwsA(isA<DataGovernanceRepositoryException>()));
      return;
    }
    final state = jsonDecode(await stateFile.readAsString()) as Map<String, dynamic>;
    final account = (await alice.listAccountRequests()).singleWhere((row) => row.id == state['account']);
    final household = (await alice.listHomeRequests(homeId: home)).singleWhere((row) => row.id == state['home']);
    for (final request in [account, household]) {
      expect(request.status, DataGovernanceRequestStatus.completed);
      expect(request.downloadEligible, isTrue);
      final artifact = await alice.retrieveExport(request, isCurrent: () => true);
      final object = jsonDecode(utf8.decode(artifact.bytes)) as Map<String, dynamic>;
      expect(object['scope'], request.scope.name);
      expect(object['requestId'], request.id);
      final data = object['data'] as Map<String, dynamic>;
      if (request.scope == DataGovernanceScope.home) {
        expect((data['home'] as List).single['id'], home);
      } else {
        expect((data['account'] as List).single['email'], 'alice@step2.example.test');
      }
      final bytes = artifact.bytes;
      artifact.dispose();
      expect(bytes.every((value) => value == 0), isTrue);
    }
    final cancelled = (await bob.listAccountRequests()).singleWhere((row) => row.id == state['cancelled']);
    expect(cancelled.status, DataGovernanceRequestStatus.cancelled);
    await expectLater(bob.retrieveExport(cancelled, isCurrent: () => true), throwsA(isA<DataGovernanceRepositoryException>()));
    await expectLater(bob.retrieveExport(household, isCurrent: () => true), throwsA(isA<DataGovernanceRepositoryException>()));
    final current = (await alice.listHomeRequests(homeId: home)).singleWhere((row) => row.id == household.id);
    final issued = await a.issueDataExportDownloadToken(requestId: current.id, body: {'expectedRevision': current.revision});
    expect(issued.headers['cache-control'], contains('no-store'));
    final token = issued.requireObject()['token'] as String;
    await expectLater(b.downloadDataExport(requestId: current.id, body: {'token': token}), throwsA(isA<ProvidentiaApiException>().having((error) => error.statusCode, 'foreign requester', 404)));
    final downloaded = await a.downloadDataExport(requestId: current.id, body: {'token': token});
    expect(downloaded.headers['cache-control'], contains('no-store'));
    await expectLater(a.downloadDataExport(requestId: current.id, body: {'token': token}), throwsA(isA<ProvidentiaApiException>().having((error) => error.statusCode, 'one-use token', 410)));
    // Consume a real token between issuance and retrieval to exercise the
    // production adapter's refresh path against actual 410 and revision CAS.
    final racingTransport = _ConsumeOnceTransport();
    final racingApi = ProvidentiaApiClient(baseUri: base, httpClient: racingTransport, defaultHeaders: {'Authorization': 'Bearer $tokenA'});
    addTearDown(racingApi.close);
    final recovered = await GeneratedDataGovernanceRepository(racingApi).retrieveExport(household, isCurrent: () => true);
    expect(racingTransport.tokenIssues, 2);
    expect(recovered.requestId, household.id);
    recovered.dispose();
    await expectLater(alice.retrieveExport(account, isCurrent: () => false), throwsA(isA<DataGovernanceRepositoryException>()));
  });
}

final class _ConsumeOnceTransport extends http.BaseClient {
  final http.Client _wire = http.Client();
  int tokenIssues = 0;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final response = await _wire.send(request);
    if (!request.url.path.endsWith('/download-token')) return response;
    tokenIssues++;
    if (tokenIssues != 1 || response.statusCode != 200) return response;
    final bytes = await response.stream.toBytes();
    final token = (jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>)['token'] as String;
    final consume = http.Request('POST', request.url.replace(path: request.url.path.replaceFirst('/download-token', '/download')));
    consume.headers.addAll(request.headers);
    consume.body = jsonEncode({'token': token});
    final consumed = await _wire.send(consume);
    expect(consumed.statusCode, 200);
    await consumed.stream.drain<void>();
    return http.StreamedResponse(Stream<List<int>>.value(bytes), response.statusCode, headers: response.headers, request: request);
  }

  @override
  void close() => _wire.close();
}
