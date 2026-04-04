<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngredientLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        return response()->json(
            $restaurant->ingredients()
                ->orderBy('name')
                ->get()
        );
    }

    public function bulkUpload(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => [
                'required',
                'file',
                'max:51200',
                function ($attribute, $value, $fail): void {
                    $ext = strtolower($value->getClientOriginalExtension());

                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true)) {
                        $fail('Ingredient library files must be images of type: jpg, jpeg, png, webp, heic, heif.');
                    }
                },
            ],
        ]);

        $uploaded = collect();

        /** @var UploadedFile[] $files */
        $files = $request->file('images', []);

        foreach ($files as $file) {
            $uploaded->push($this->storeIngredient($restaurant, $file));
        }

        return response()->json([
            'message' => 'Ingredient library updated successfully.',
            'uploaded_count' => $uploaded->count(),
            'ingredients' => $restaurant->ingredients()->orderBy('name')->get(),
        ], 201);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredients = $restaurant->ingredients()->get();
        $deletedCount = $ingredients->count();

        foreach ($ingredients as $ingredient) {
            $this->deleteStoredIngredientFile($ingredient);
            $ingredient->delete();
        }

        return response()->json([
            'message' => 'Ingredient library cleared successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user?->loadMissing('restaurant');

        if (! $user?->restaurant) {
            abort(403, 'No restaurant is linked to this account.');
        }

        return $user->restaurant;
    }

    private function storeIngredient(Restaurant $restaurant, UploadedFile $file): Ingredient
    {
        $ingredientName = $this->ingredientNameFromFile($file->getClientOriginalName());

        $restaurant->ingredients()
            ->where('name', $ingredientName)
            ->get()
            ->each(function (Ingredient $existingIngredient): void {
                $this->deleteStoredIngredientFile($existingIngredient);
                $existingIngredient->delete();
            });

        $originalName = basename((string) $file->getClientOriginalName()) ?: 'ingredient.jpg';
        $path = $file->storeAs(
            "ingredients/{$restaurant->id}",
            Str::uuid().'-'.$originalName,
            'public'
        );

        return $restaurant->ingredients()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $ingredientName,
            'storage_disk' => 'public',
            'file_path' => $path,
            'source_file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
        ]);
    }

    private function ingredientNameFromFile(string $fileName): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $withSpaces = str_replace('-', ' ', $baseName);

        return trim(preg_replace('/\s+/', ' ', $withSpaces) ?: $baseName);
    }

    private function deleteStoredIngredientFile(Ingredient $ingredient): void
    {
        if (! $ingredient->file_path) {
            return;
        }

        $disk = $ingredient->storage_disk ?: 'public';

        try {
            Storage::disk($disk)->delete($ingredient->file_path);
        } catch (\Throwable) {
            // Best-effort cleanup; keep the delete flow successful if the file is already gone.
        }
    }
}
