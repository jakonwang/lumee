<?php

namespace App\Http\Controllers\Storefront;

use App\Customers\CustomerSession;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Reviews\Reviews;
use App\Tenancy\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Write a review" form on the product page. */
class ReviewController extends Controller
{
    /**
     * Link from the review request email: if the order really contains this product,
     * open the review form with the buyer's name and email already filled in.
     */
    public function fromEmail(Request $request, string $handle): RedirectResponse
    {
        $product = Product::active()->where('handle', $handle)->firstOrFail();
        $token = (string) $request->query('order');
        $order = \Illuminate\Support\Str::isUuid($token)
            ? \App\Models\Order::where('token', $token)->whereHas('items', fn ($q) => $q->where('product_id', $product->id))->first()
            : null;

        $redirect = redirect("/products/{$product->handle}#reviews");
        if (! $order) {
            return $redirect;
        }
        $a = $order->shipping_address ?? [];

        return $redirect->with('review_prefill', [
            'author' => trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
            'email' => $order->email,
        ]);
    }

    public function store(Request $request, string $handle, CurrentStore $current, Reviews $reviews): RedirectResponse
    {
        $store = $current->getOrFail();
        abort_unless(Reviews::settings($store)['enabled'], 404);
        $product = Product::active()->where('handle', $handle)->firstOrFail();

        // Bots fill every field; people never see this one
        if (filled($request->input('website'))) {
            return redirect("/products/{$product->handle}#reviews")->with('review_status', __('Thank you! Your review has been submitted.'));
        }

        $data = $request->validateWithBag('review', [
            'author' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ], [
            'rating.required' => __('Please choose a star rating.'),
            'body.min' => __('Please write at least a few words.'),
            'photos.max' => __('You can add up to 3 photos.'),
            'photos.*.max' => __('Each photo must be smaller than 5 MB.'),
        ]);

        $review = $reviews->submit($store, $product, $data, $request->file('photos', []), CustomerSession::user());

        return redirect("/products/{$product->handle}#reviews")->with('review_status', $review->status === 'published'
            ? __('Thank you! Your review is now live.')
            : __('Thank you! Your review has been submitted and will appear once approved.'));
    }
}
