<?php

namespace App\Content;

/**
 * An image generation service. Lumée sends a product photo and a description of the picture to
 * make; the service returns the new image. Providers are swappable (SceneImages::generator()):
 * add a class implementing this interface and register it in PROVIDERS.
 */
interface ImageGenerator
{
    /**
     * @param  string  $image  product photo (PNG / JPEG / WebP bytes)
     * @param  string  $size  "1024x1536" (portrait) | "1024x1024" | "1536x1024"
     * @return string the generated image (PNG / JPEG / WebP bytes)
     *
     * @throws \RuntimeException with a merchant-readable message
     */
    public function edit(string $image, string $prompt, string $size): string;
}
