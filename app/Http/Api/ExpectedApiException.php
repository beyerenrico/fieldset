<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * An ApiException for an expected, permanent state of a resource (e.g. 410 `document_anonymized` once the PDF
 * files of an anonymized submission were deleted): rendered like any other ApiException, never reported.
 */
final class ExpectedApiException extends ApiException implements ShouldntReport {}
