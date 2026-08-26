<?php

namespace App\Exceptions;

/**
 * Thrown when a workflow action's precondition no longer holds — e.g. a
 * reviewer acting on a submission another reviewer already decided, or an
 * action targeting a submission version that is no longer the active one.
 * Mapped to HTTP 409 in bootstrap/app.php.
 */
class WorkflowConflictException extends \RuntimeException {}
