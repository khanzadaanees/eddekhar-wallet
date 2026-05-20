<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalApplicationRequest
{
    /**
     * Dispatch a JSON POST through the application kernel (no outbound HTTP).
     *
     * @param  array<string, mixed>  $data
     */
    public static function postJson(string $uri, array $data = []): Response
    {
        $request = Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        return app()->handle($request);
    }
}
