<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureInterfaceHToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = DB::table('interfaceh_api_tokens')->where('id', 1)->first();
        if (! $token || ! $token->active) {
            return response()->json([
                'success' => false,
                'message' => 'La API de interfazH no está configurada.',
            ], 503);
        }

        $provided = $request->bearerToken();
        if (! is_string($provided) || ! hash_equals($token->token_hash, hash('sha256', $provided))) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], 401);
        }

        return $next($request);
    }
}
