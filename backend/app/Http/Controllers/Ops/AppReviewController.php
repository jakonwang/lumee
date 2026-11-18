<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform admins review third-party apps before they appear in the app store. */
class AppReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return response()->json(['data' => App::with('developer:id,name,email')
            ->when(in_array($status, App::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest('updated_at')->limit(200)->get()
            ->map(fn (App $a) => [
                'id' => $a->id, 'name' => $a->name, 'tagline' => $a->tagline, 'description' => $a->description, 'icon_url' => $a->icon_url,
                'app_url' => $a->app_url, 'redirect_uris' => $a->redirect_uris, 'scopes' => $a->scopes, 'status' => $a->status,
                'review_note' => $a->review_note, 'developer' => $a->developer ? ['name' => $a->developer->name, 'email' => $a->developer->email] : null,
                'installs' => $a->installations()->whereNull('uninstalled_at')->count(), 'updated_at' => $a->updated_at,
            ])]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['listed', 'rejected', 'draft'])],
            'note' => ['nullable', 'string', 'max:300', 'required_if:decision,rejected'],
        ], ['note.required_if' => __('拒绝时请填写原因，开发者会看到')]);
        $app = App::findOrFail($id);
        $app->forceFill(['status' => $data['decision'], 'review_note' => $data['note'] ?? null])->save();

        return response()->json(['ok' => true, 'status' => $app->status]);
    }
}
