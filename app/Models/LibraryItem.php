<?php

namespace App\Models;

use App\Support\Library\ExternalContentUrl;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LibraryItem extends Model
{
    public const TYPES = [
        'article' => 'Стаття',
        'video' => 'Відео',
        'playlist' => 'Музичний плейліст',
        'instruction' => 'Інструкція',
    ];

    public const STATUSES = ['draft' => 'Чернетка', 'published' => 'Опубліковано'];

    protected $fillable = [
        'type', 'title', 'summary', 'cover_image_url', 'content_markdown',
        'external_url', 'status',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $item): void {
            foreach (['summary', 'cover_image_url', 'content_markdown', 'external_url'] as $field) {
                if ($item->$field === '') {
                    $item->$field = null;
                }
            }

            if ($item->status === 'published') {
                $item->published_at ??= now();
            } else {
                $item->published_at = null;
            }

            Validator::make($item->getAttributes(), [
                'type' => ['required', Rule::in(array_keys(self::TYPES))],
                'status' => ['required', Rule::in(array_keys(self::STATUSES))],
                'title' => ['required', 'string', 'max:255', function ($field, $value, $fail) {
                    if (trim($value) === '') {
                        $fail('Назва не може бути порожньою.');
                    }
                }],
                'summary' => ['nullable', 'string', 'max:2000'],
                'content_markdown' => ['nullable', 'string', 'max:200000'],
                'cover_image_url' => ['nullable', new PublicImageUrl],
                'external_url' => ['nullable', new ExternalContentUrl],
            ])->validate();

            if ($item->status !== 'published') {
                return;
            }
            if (in_array($item->type, ['video', 'playlist'], true) && empty($item->external_url)) {
                throw ValidationException::withMessages(['external_url' => 'Для цього типу потрібне HTTPS-посилання.']);
            }
            if (in_array($item->type, ['article', 'instruction'], true)
                && empty($item->external_url) && trim((string) $item->content_markdown) === '') {
                throw ValidationException::withMessages(['content_markdown' => 'Для публікації додайте текст або HTTPS-посилання.']);
            }
        });
    }
}
