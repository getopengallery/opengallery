<?php

namespace App\Http\Requests;

use App\Support\PhotoUploads;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One chunk of a photo upload (see App\Http\Controllers\PhotoUploadController).
 */
class StorePhotoChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PhotoUploads::allows($this->string('target')->value() ?: null, $this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'upload' => ['required', 'uuid'],
            'batch' => ['required', 'uuid'],
            'target' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (! PhotoUploads::hasAcceptedExtension((string) $value)) {
                    $fail(__('This file type is not supported.'));
                }
            }],
            'size' => ['required', 'integer', 'min:1', 'max:'.PhotoUploads::maxBytes()],
            'offset' => ['required', 'integer', 'min:0', 'lt:size'],
            // A little slack over the chunk size the browser was told to use.
            'chunk' => ['required', 'file', 'max:'.(int) ceil(PhotoUploads::chunkBytes() / 1024 * 1.1)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'size.max' => __('The file is larger than the upload limit of :mb MB.', ['mb' => (int) round(PhotoUploads::maxBytes() / 1024 / 1024)]),
        ];
    }
}
