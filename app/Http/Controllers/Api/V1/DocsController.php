<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\OpenApiSpec;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Documentação pública da API: especificação OpenAPI e página de referência. */
final class DocsController extends Controller
{
    public function spec(): JsonResponse
    {
        return response()->json(OpenApiSpec::build(url('/')), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function page(): View
    {
        return view('api-docs');
    }
}
