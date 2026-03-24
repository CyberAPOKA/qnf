<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class PlayerPhotoSeeder extends Seeder
{
    public function run(): void
    {
        $photosPath = database_path('seeders/photos');

        foreach (scandir($photosPath) as $folder) {
            if (in_array($folder, ['.', '..'])) {
                continue;
            }

            $user = User::where('phone', 'like', '%'.$folder)->first();

            if (! $user) {
                $this->command->warn("No user found for phone ending in {$folder}, skipping.");
                continue;
            }

            $folderPath = $photosPath.DIRECTORY_SEPARATOR.$folder;

            foreach (['front', 'side'] as $type) {
                $file = $this->findPhoto($folderPath, $type);

                if (! $file) {
                    $this->command->warn("No {$type} photo found for {$user->name} ({$folder}), skipping.");
                    continue;
                }

                $column = "photo_{$type}";

                // Delete old photo if exists
                if ($user->$column) {
                    Storage::disk('public')->delete($user->$column);
                }

                // Store new photo
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                $storagePath = "players/{$user->id}_{$type}.{$extension}";
                Storage::disk('public')->put($storagePath, file_get_contents($file));

                $user->$column = $storagePath;
            }

            $user->save();
            $this->command->info("Photos updated for {$user->name}");
        }
    }

    private function findPhoto(string $folderPath, string $type): ?string
    {
        foreach (['jpeg', 'jpg', 'png'] as $ext) {
            $file = $folderPath.DIRECTORY_SEPARATOR."{$type}.{$ext}";
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }
}
