<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class SupabaseService
{
    protected $url;
    protected $key;
    protected $bucket;
    protected $client;

    public function __construct()
    {
        $this->url = env('SUPABASE_URL');
        $this->key = env('SUPABASE_KEY');
        $this->bucket = env('SUPABASE_BUCKET');
        
        $this->client = new Client([
            'base_uri' => $this->url,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
                'apikey' => $this->key,
            ]
        ]);
    }

    /**
     * Upload a file to Supabase Storage
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return string|null Public URL of the uploaded file
     */
    public function uploadFile(UploadedFile $file, $folder = 'uploads')
    {
        try {
            if (! $file) {
                Log::error('SupabaseService::uploadFile called with null $file');
                return null;
            }
            $filename = $folder . '/' . uniqid() . '_' . $file->getClientOriginalName();
            
            // Read file content
            $content = file_get_contents($file->getRealPath());

            // Upload to Supabase Storage
            // POST /storage/v1/object/{bucket}/{path}
            $response = $this->client->post("/storage/v1/object/{$this->bucket}/{$filename}", [
                'body' => $content,
                'headers' => [
                    'Content-Type' => $file->getMimeType(),
                    'x-upsert' => 'false' // Don't overwrite
                ],
                'http_errors' => false, // prevent Guzzle from throwing on 4xx/5xx so we can log body
            ]);

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($status === 200 || $status === 201) {
                return "{$this->url}/storage/v1/object/public/{$this->bucket}/{$filename}";
            }

            Log::error("Supabase Upload Failed - status: {$status}, body: {$body}");
            return null;

        } catch (\Exception $e) {
            Log::error('Supabase Upload Error: ' . $e->getMessage());
            return null;
        }
    }
}
