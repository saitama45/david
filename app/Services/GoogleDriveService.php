<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Google\Service\Drive\Permission;

class GoogleDriveService
{
    public function uploadImage(UploadedFile $file): ?string
    {
        $fileName = time() . '_' . $file->getClientOriginalName();
        $fileContent = file_get_contents($file->getRealPath());

        // The put method returns a boolean for success.
        $success = Storage::disk('google')->put($fileName, $fileContent);

        // Use the generated $fileName as the path if the upload was successful.
        if ($success) {
            try {
                // Get the Masbug adapter instance from the Laravel Filesystem Adapter
                $masbugAdapter = Storage::disk('google')->getAdapter();

                // Get metadata using the adapter directly with the correct file path
                $metadata = $masbugAdapter->getMetadata($fileName);

                if (!isset($metadata['extra_metadata']['id'])) {
                    Log::error('Could not retrieve file ID from Google Drive metadata.');
                    return null;
                }
                $fileId = $metadata['extra_metadata']['id'];

                // Get the Google Drive service from the adapter
                $service = $masbugAdapter->getService();

                // Create and apply public permission
                $permission = new Permission();
                $permission->setType('anyone');
                $permission->setRole('reader');
                $service->permissions->create($fileId, $permission);

                // Get the public URL (webViewLink)
                $googleFile = $service->files->get($fileId, ['fields' => 'webViewLink']);
                return $googleFile->getWebViewLink();

            } catch (\Exception $e) {
                // Log the exception and return null
                Log::error('Failed to get Google Drive public URL: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    public function deleteImage(?string $imageUrl): bool
    {
        if (!$imageUrl) {
            return false;
        }

        // Extract file ID from URL
        // URL format: https://drive.google.com/file/d/{FILE_ID}/view?usp=drivesdk
        $fileId = null;
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $imageUrl, $matches)) {
            $fileId = $matches[1];
        }

        if (!$fileId) {
            Log::warning('Could not parse file ID from Google Drive URL: ' . $imageUrl);
            return false;
        }

        try {
            // Get the Masbug adapter instance from the Laravel Filesystem Adapter
            $masbugAdapter = Storage::disk('google')->getAdapter();
            // Get the Google Drive service from the adapter
            $service = $masbugAdapter->getService();

            // Use the service to delete the file by its ID
            $service->files->delete($fileId);

            Log::info('Successfully deleted old Google Drive file: ' . $fileId);
            return true;

        } catch (\Exception $e) {
            // Log errors, e.g., file not found (404), which is fine.
            Log::error('Failed to delete Google Drive file ' . $fileId . ': ' . $e->getMessage());
            // Return true even if deletion fails, so the main operation (like updating a record) can continue.
            return true;
        }
    }
}
