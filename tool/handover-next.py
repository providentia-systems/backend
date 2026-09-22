#!/usr/bin/env python3
"""Apply only the three reported coding-standard corrections."""
from pathlib import Path
p = Path('tests/Unit/Synchronization/SyncEnvelopeValidatorTest.php')
s = p.read_text().replace(
    '            self::assertSame(\\Providentia\\Synchronization\\Application\\SyncProblemClassifier::DEVICE_MISMATCH, $problem->type);',
    '''            self::assertSame(
                \\Providentia\\Synchronization\\Application\\SyncProblemClassifier::DEVICE_MISMATCH,
                $problem->type,
            );''')
p.write_text(s)
p = Path('tests/Unit/Synchronization/SyncProblemClassifierTest.php')
s = p.read_text().replace(
    "            $result = SyncProblemClassifier::result('operation', new Problem($httpStatus, 'Failure', 'Safe detail.', $type));",
    "            $problem = new Problem($httpStatus, 'Failure', 'Safe detail.', $type);\n            $result = SyncProblemClassifier::result('operation', $problem);")
s = s.replace(
    "            $result = SyncProblemClassifier::result('operation', new Problem($status, 'Driver', 'secret database credentials'));",
    "            $problem = new Problem($status, 'Driver', 'secret database credentials');\n            $result = SyncProblemClassifier::result('operation', $problem);")
p.write_text(s)
print('Reported overlong assertions and setup lines wrapped.')
