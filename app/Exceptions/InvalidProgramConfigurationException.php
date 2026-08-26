<?php

namespace App\Exceptions;

/**
 * Thrown by ProgramConfigurationService when a configuration write fails
 * category-specific validation — mapped to HTTP 422 in bootstrap/app.php.
 */
class InvalidProgramConfigurationException extends \RuntimeException {}
