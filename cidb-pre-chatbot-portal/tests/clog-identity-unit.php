<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Ticketing/ClogIdentityExtractor.php';

use Cidb\Ticketing\ClogIdentityExtractor;

function expect_identity(?string $actual, ?string $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message);
    }
}

expect_identity(
    ClogIdentityExtractor::extractNric("Name: Sample Person\nK/P: 900101-10-1234"),
    '900101101234',
    'A single labeled, date-valid CLOG NRIC should be extracted.'
);
expect_identity(
    ClogIdentityExtractor::extractNric(
        '<table><tr><td>NRIC:</td><td>900101-10-1234</td></tr></table>'
    ),
    '900101101234',
    'A labeled NRIC in an HTML table should be extracted.'
);
expect_identity(
    ClogIdentityExtractor::extractNric("NRIC: 900101-10-1234\nK/P: 900101101234"),
    '900101101234',
    'Repeated identical identity values should remain unambiguous.'
);
expect_identity(
    ClogIdentityExtractor::extractNric("NRIC: 900101-10-1234\nK/P: 900102-10-5678"),
    null,
    'Conflicting labeled identity values should require review.'
);
expect_identity(
    ClogIdentityExtractor::extractNric('Reference: 900101-10-1234'),
    null,
    'Unlabeled numbers must not be treated as an NRIC.'
);
expect_identity(
    ClogIdentityExtractor::extractNric('NRIC: 991332-10-1234'),
    null,
    'Invalid birth dates must not be treated as an NRIC.'
);

echo "CLOG identity extraction checks passed.\n";
