<?php

declare(strict_types=1);

namespace App\Evidence;

use Illuminate\Http\Request;

final readonly class RequestContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->ip(), $request->userAgent());
    }
}
