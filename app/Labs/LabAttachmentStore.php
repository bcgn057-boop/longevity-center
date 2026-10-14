<?php

namespace App\Labs;

use App\Models\LabReport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Keeps original lab report PDFs on the private lab_attachments disk,
 * encrypted with the app key. Files are named randomly, so a path reveals
 * nothing about the patient, and are never deleted.
 */
class LabAttachmentStore
{
    public const DISK = 'lab_attachments';

    /**
     * Encrypt and store an uploaded PDF.
     *
     * @return array{attachment_path: string, attachment_name: string, attachment_size: int, attachment_sha256: string}
     */
    public function store(UploadedFile $file): array
    {
        $contents = $file->getContent();
        $path = now()->format('Y/m').'/'.Str::uuid()->toString().'.pdf.enc';

        $this->disk()->put($path, Crypt::encryptString($contents));

        return [
            'attachment_path' => $path,
            'attachment_name' => self::safeName($file->getClientOriginalName()),
            'attachment_size' => strlen($contents),
            'attachment_sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * The original PDF, decrypted and checked against its checksum.
     *
     * @throws RuntimeException when the stored file no longer matches what was uploaded
     */
    public function read(LabReport $report): string
    {
        $contents = Crypt::decryptString((string) $this->disk()->get((string) $report->attachment_path));

        if (! hash_equals((string) $report->attachment_sha256, hash('sha256', $contents))) {
            throw new RuntimeException("The PDF attached to lab report #{$report->id} does not match its checksum.");
        }

        return $contents;
    }

    /**
     * The original PDF as a download that browsers and proxies must not
     * cache or reinterpret. The caller authorizes and audits.
     */
    public function download(LabReport $report): Response
    {
        $contents = $this->read($report);

        return new Response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                // Stored already reduced to safe ASCII, so it is its own fallback.
                (string) $report->attachment_name,
            ),
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove a file stored for a report that was then not saved.
     */
    public function discard(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * The uploaded name, reduced to safe characters for a download header.
     */
    public static function safeName(string $name): string
    {
        $base = Str::of(pathinfo($name, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->trim()->limit(100, '');

        return ($base->isEmpty() ? 'lab-report' : (string) $base).'.pdf';
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
