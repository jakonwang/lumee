<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\OutreachEnrollment;
use App\Models\OutreachSuppression;
use App\Tenancy\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unsubscribe from a store's outreach emails. Opening the link shows a confirm button (link scanners in
 * mail systems open links by themselves); mail apps' one-click unsubscribe (RFC 8058) POSTs directly.
 */
class OutreachUnsubscribeController extends Controller
{
    public function show(CurrentStore $current, string $token): View
    {
        $e = OutreachEnrollment::where('token', $token)->firstOrFail();

        return view('storefront.outreach-unsubscribe', ['store' => $current->getOrFail(), 'token' => $token, 'done' => OutreachSuppression::blocks($e->email)]);
    }

    public function unsubscribe(Request $request, CurrentStore $current, string $token): View
    {
        $e = OutreachEnrollment::where('token', $token)->firstOrFail();
        OutreachSuppression::add($e->email, 'unsubscribed');
        OutreachEnrollment::where('email', $e->email)->whereIn('status', ['active', 'completed'])->update(['status' => 'unsubscribed', 'next_at' => null]);
        if ($e->lead) {
            $e->lead->activities()->create(['type' => 'note', 'body' => '对方退订了开发信（以后不会再发）']);
        }

        return view('storefront.outreach-unsubscribe', ['store' => $current->getOrFail(), 'token' => $token, 'done' => true]);
    }
}
