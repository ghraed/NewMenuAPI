<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotentStaffMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('X-Idempotency-Key', ''));
        $user = $request->user();
        if ($key === '' || ! $user) {
            return $next($request);
        }

        $keyHash = hash('sha256', $key);
        $payloadHash = hash('sha256', json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'payload' => $request->json()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            return DB::transaction(function () use ($request, $next, $user, $keyHash, $payloadHash): Response {
                DB::table('staff_mutation_idempotencies')->insert([
                    'user_id' => $user->id,
                    'key_hash' => $keyHash,
                    'payload_hash' => $payloadHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }

                $body = json_decode((string) $response->getContent(), true);
                DB::table('staff_mutation_idempotencies')
                    ->where('user_id', $user->id)
                    ->where('key_hash', $keyHash)
                    ->update([
                        'response_status' => $response->getStatusCode(),
                        'response_body' => json_encode(is_array($body) ? $body : []),
                        'updated_at' => now(),
                    ]);

                return $response;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $record = DB::table('staff_mutation_idempotencies')
                ->where('user_id', $user->id)
                ->where('key_hash', $keyHash)
                ->first();
            if (! $record) {
                throw $exception;
            }
            if (! hash_equals((string) $record->payload_hash, $payloadHash)) {
                return response()->json(['message' => 'The idempotency key was already used for a different staff mutation.'], 409);
            }
            if (! $record->response_status) {
                return response()->json(['message' => 'The matching staff mutation is still being processed.'], 409);
            }

            $body = json_decode((string) $record->response_body, true);

            return new JsonResponse(is_array($body) ? $body : [], (int) $record->response_status);
        }
    }
}
