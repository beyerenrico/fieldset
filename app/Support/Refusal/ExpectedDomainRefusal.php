<?php

declare(strict_types=1);

namespace App\Support\Refusal;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * A DomainRefusal for an expected, permanent state of a resource (e.g. 410 `document_anonymized` once the PDF
 * files of an anonymized submission were deleted): rendered like any other refusal, never reported.
 */
final class ExpectedDomainRefusal extends DomainRefusal implements ShouldntReport {}
