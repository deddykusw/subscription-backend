<?php

namespace App\Http\Middleware;

/**
 * Backwards-compatible alias for AdminOnly.
 * The canonical class is AdminOnly; this file exists so that existing
 * references (e.g. in older bootstrap/app.php snapshots) do not break.
 */
class EnsureUserIsAdmin extends AdminOnly {}
