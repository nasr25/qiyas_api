<?php

namespace App\Exceptions;

/**
 * Thrown by ComplianceNodeService when a hierarchy node violates program
 * scoping, parent/child type rules, or maximum depth — mapped to HTTP 422
 * in bootstrap/app.php.
 */
class InvalidHierarchyException extends \RuntimeException {}
