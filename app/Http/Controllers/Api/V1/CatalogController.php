<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\GetCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Resources\Api\V1\OptionGroupResource;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('Katalog & produk')]
final class CatalogController extends Controller
{
    /**
     * Katalog lengkap.
     *
     * Kategori, produk aktif (beserta urutan grup opsinya), grup opsi + opsi, dan metode bayar
     * aktif dalam satu respons. Mendukung ETag: kirim If-None-Match untuk mendapat 304 bila
     * katalog tidak berubah (hemat kuota di jaringan kasir).
     */
    public function index(Request $request, GetCatalog $action): Response
    {
        $catalog = $action->handle();

        $data = [
            'categories' => CategoryResource::collection($catalog['categories'])->resolve($request),
            'products' => ProductResource::collection($catalog['products'])->resolve($request),
            'option_groups' => OptionGroupResource::collection($catalog['option_groups'])->resolve($request),
            'payment_methods' => PaymentMethodResource::collection($catalog['payment_methods'])->resolve($request),
        ];

        // ETag dari isi katalog (bukan request_id) agar stabil selama data tidak berubah
        $etag = '"'.sha1((string) json_encode($data)).'"';

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(304)->setEtag(trim($etag, '"'));
        }

        return ApiResponse::success($data)
            ->setEtag(trim($etag, '"'))
            ->setPrivate();
    }
}
