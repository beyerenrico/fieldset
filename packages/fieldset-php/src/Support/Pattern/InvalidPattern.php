<?php

declare(strict_types=1);

namespace Fieldset\Support\Pattern;

use RuntimeException;

/**
 * Raised by the parser for syntax outside the §3.4 subset.
 */
final class InvalidPattern extends RuntimeException {}
