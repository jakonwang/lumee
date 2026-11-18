<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Admin\PartnerThemeController;
use App\Http\Controllers\Controller;
use App\Models\ThemeListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Platform admins review marketplace themes before merchants can install them. */
class ThemeReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return response()->json(['data' => ThemeListing::with('developer:id,name,email')
            ->when(in_array($status, ThemeListing::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest('updated_at')->limit(200)->get()
            ->map(fn (ThemeListing $t) => [...PartnerThemeController::present($t),
                'developer' => $t->developer ? ['name' => $t->developer->name, 'email' => $t->developer->email] : null])]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['listed', 'rejected', 'draft'])],
            'note' => ['nullable', 'string', 'max:300', 'required_if:decision,rejected'],
        ], ['note.required_if' => __('拒绝时请填写原因，开发者会看到')]);
        $listing = ThemeListing::findOrFail($id);
        abort_if($data['decision'] === 'listed' && ! $listing->package_path, 422, __('这个主题还没有上传主题包'));
        $listing->forceFill(['status' => $data['decision'], 'review_note' => $data['note'] ?? null])->save();

        return response()->json(['ok' => true, 'status' => $listing->status]);
    }

    /** Download the package to check it before approving. */
    public function download(int $id): StreamedResponse
    {
        $listing = ThemeListing::findOrFail($id);
        abort_unless($listing->package_path && Storage::disk('local')->exists($listing->package_path), 404);

        return Storage::disk('local')->download($listing->package_path, $listing->slug.'-v'.$listing->version.'.zip');
    }
}
