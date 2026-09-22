#!/usr/bin/env python3
"""Extend existing end-to-end service regressions for the changed semantics."""
from pathlib import Path
p = Path('tests/Unit/Synchronization/SyncFailureClassificationTest.php')
s = p.read_text()
s = s.replace("                    404 => 'authorization_failure',", "                    404 => 'validation_error',")
if "string $type = 'about:blank'" not in s:
    s = s.replace(
        '    public function testBothProtocolsKeepFailureSemantics(int $protocol, int $httpStatus, string $outcome): void',
        '''    public function testBothProtocolsKeepFailureSemantics(
        int $protocol,
        int $httpStatus,
        string $outcome,
        string $type = 'about:blank',
        ?string $code = null,
    ): void''')
    s = s.replace("        $problem = new Problem($httpStatus, 'Synthetic failure', 'Synthetic domain or internal service detail');",
        "        $problem = new Problem($httpStatus, 'Synthetic failure', 'Synthetic domain or internal service detail', $type);")
    s = s.replace("            self::assertSame($outcome, $response['results'][0]['status']);", "            self::assertSame($outcome, $response['results'][0]['status']);\n            if ($code !== null) {\n                self::assertSame($code, $response['results'][0]['code']);\n            }\n            if ($httpStatus === 404 && $type === 'about:blank') {\n                self::assertSame('resource_unavailable', $response['results'][0]['code']);\n            }")
    s = s.replace('/** @return iterable<string, array{int, int, string}> */', '/** @return iterable<string, array{int, int, string, 3?: string, 4?: string}> */')
    marker = '''        foreach ([1, 2] as $protocol) {
'''
    s = s.replace(marker, marker + '''            foreach ([
                'sync_device_mismatch' => [403, 'device_binding_mismatch'],
                'sync_home_access_denied' => [404, 'home_access_denied'],
                'sync_permission_denied' => [404, 'permission_denied'],
            ] as $type => [$status, $code]) {
                yield "protocol $protocol / $type" => [
                    $protocol,
                    $status,
                    'authorization_failure',
                    'https://providentia.invalid/problems/' . $type,
                    $code,
                ];
            }
''', 1)
p.write_text(s)
print('Both protocols now test ordinary missing resources separately from explicit binding/access denials.')
