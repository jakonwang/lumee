<?php

namespace App\Content;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OpenAI image editing (gpt-image-1) with the merchant's own API key. */
class OpenAiImages implements ImageGenerator
{
    public const ENDPOINT = 'https://api.openai.com/v1/images/edits';

    public function __construct(private string $key) {}

    public function edit(string $image, string $prompt, string $size): string
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($image) ?: 'image/png';
        $ext = ['image/jpeg' => 'jpg', 'image/webp' => 'webp'][$type] ?? 'png';
        $res = Http::withToken($this->key)->timeout(180)
            ->attach('image', $image, "product.$ext", ['Content-Type' => $type])
            ->post(self::ENDPOINT, ['model' => 'gpt-image-1', 'prompt' => $prompt, 'size' => $size, 'n' => 1]);
        if ($res->failed()) {
            $msg = (string) ($res->json('error.message') ?? $res->body());
            throw new RuntimeException(__('图片生成失败：:p0', ['p0' => mb_substr($msg, 0, 200)]));
        }
        $b64 = $res->json('data.0.b64_json');
        $bytes = is_string($b64) ? base64_decode($b64, true) : false;
        if (! $bytes) {
            throw new RuntimeException(__('图片生成服务没有返回图片'));
        }

        return $bytes;
    }
}
