<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Security\ActivityLog;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings › Activity log: who did what in this store's admin (latest first). */
class ActivityLogController extends Controller
{
    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $page = DB::table('activity_logs')->where('activity_logs.store_id', $current->id())
            ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('activity_logs.user_id', (int) $id))
            ->when($request->query('area'), fn ($q, $a) => $q->where(fn ($w) => $w->where('activity_logs.action', 'like', addcslashes($a, '%_\\').'.%')
                ->when($a === 'auth', fn ($x) => $x->orWhere('activity_logs.action', 'like', 'security.%'))))
            ->select('activity_logs.*', 'users.name as user_name', 'users.email as user_email')
            ->orderByDesc('activity_logs.id')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        $page->getCollection()->transform(fn ($r) => [
            'id' => $r->id,
            'action' => $r->action,
            'label' => ActivityLog::label($r->action),
            'subject' => $r->subject,
            'user' => $r->user_name ? ['id' => $r->user_id, 'name' => $r->user_name, 'email' => $r->user_email] : null,
            'ip' => $r->ip,
            'created_at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
        ]);

        $people = DB::table('store_user')->join('users', 'users.id', '=', 'store_user.user_id')
            ->where('store_user.store_id', $current->id())->get(['users.id', 'users.name']);

        return response()->json([...$page->toArray(), 'people' => $people]);
    }
}
