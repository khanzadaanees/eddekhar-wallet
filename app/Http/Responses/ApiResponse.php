<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    /**
     * @param  mixed  $data  JsonResource, ResourceCollection, array, or scalar
     */
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data === null) {
            return response()->json($payload, $status);
        }

        if ($data instanceof JsonResource) {
            $payload['data'] = $data->resolve();

            return response()->json($payload, $status);
        }

        if ($data instanceof AnonymousResourceCollection || $data instanceof ResourceCollection) {
            $resolved = $data->response()->getData(true);

            if (is_array($resolved) && array_key_exists('data', $resolved)) {
                $payload['data'] = $resolved['data'];

                if (isset($resolved['meta'])) {
                    $payload['meta'] = $resolved['meta'];
                }

                if (isset($resolved['links'])) {
                    $payload['links'] = $resolved['links'];
                }
            } else {
                $payload['data'] = $resolved;
            }

            return response()->json($payload, $status);
        }

        $payload['data'] = $data;

        return response()->json($payload, $status);
    }

    public static function paginated(
        string $message,
        LengthAwarePaginator $paginator,
        AnonymousResourceCollection $collection,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $collection->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(
        string $message,
        string $errorCode,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
