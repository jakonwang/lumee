<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;

/** Catch-all for unknown storefront paths, so URL redirects (StorefrontRedirects) get a chance. */
class NotFoundController extends Controller
{
    public function __invoke(): never
    {
        abort(404);
    }
}
