<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Copies legacy CV files from a public/remote media disk onto the private
 * local disk. The original file is never deleted; media rows are only
 * re-pointed after destination integrity has been verified.
 */
class MigratePrivateCvs extends Command
{
    protected $signature = 'media:migrate-private-cvs {--dry-run : Report actions without copying or updating rows} {--chunk=100}';

    protected $description = 'Copy existing CV media to the private local disk and re-point media rows safely';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $counts = ['migrated' => 0, 'already' => 0, 'dry_run' => 0, 'missing' => 0, 'failed' => 0];

        Media::query()
            ->where('model_type', Profile::class)
            ->where('collection_name', 'cv')
            ->orderBy('id')
            ->chunkById($chunk, function ($mediaRows) use ($dryRun, &$counts) {
                foreach ($mediaRows as $media) {
                    $sourceDiskName = $media->disk ?: config('media-library.disk_name', 'public');

                    if ($sourceDiskName === 'local') {
                        $counts['already']++;
                        continue;
                    }

                    $sourceConfig = config("filesystems.disks.{$sourceDiskName}");
                    $targetConfig = config('filesystems.disks.local');

                    if (($sourceConfig['driver'] ?? null) !== 'local'
                        || ($targetConfig['driver'] ?? null) !== 'local') {
                        $counts['failed']++;
                        $this->warn("Media {$media->id}: source/target disk must both be local filesystem disks (source: {$sourceDiskName}).");
                        continue;
                    }

                    try {
                        $path = $media->getPathRelativeToRoot();
                    } catch (\Throwable $e) {
                        $counts['failed']++;
                        $this->warn("Media {$media->id}: cannot resolve path ({$e->getMessage()}).");
                        continue;
                    }

                    $source = Storage::disk($sourceDiskName);
                    $target = Storage::disk('local');

                    if (! $source->exists($path)) {
                        $counts['missing']++;
                        $this->warn("Media {$media->id}: source file missing at {$sourceDiskName}:{$path}");
                        continue;
                    }

                    $sourcePath = $source->path($path);
                    $targetPath = $target->path($path);

                    if ($target->exists($path)) {
                        if ($this->hash($sourcePath) === $this->hash($targetPath)) {
                            if (! $dryRun) {
                                $media->forceFill(['disk' => 'local'])->save();
                            } else {
                                $counts['dry_run']++;
                            }
                            $counts['already']++;
                            $this->line("Media {$media->id}: destination already verified; " . ($dryRun ? 'would re-point row.' : 'row re-pointed.'));
                        } else {
                            $counts['failed']++;
                            $this->error("Media {$media->id}: destination exists with a different checksum; refusing to overwrite.");
                        }
                        continue;
                    }

                    if ($dryRun) {
                        $counts['dry_run']++;
                        $this->line("Media {$media->id}: would copy {$sourceDiskName}:{$path} -> local:{$path}");
                        continue;
                    }

                    try {
                        $target->makeDirectory(dirname($path));
                        $stream = $source->readStream($path);
                        if (! is_resource($stream)) {
                            throw new \RuntimeException('Could not open source stream.');
                        }
                        $target->writeStream($path, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }

                        if ($this->hash($sourcePath) !== $this->hash($targetPath)) {
                            throw new \RuntimeException('Destination checksum does not match source.');
                        }

                        $media->forceFill(['disk' => 'local'])->save();
                        $counts['migrated']++;
                        $this->info("Media {$media->id}: copied and verified; original retained at {$sourceDiskName}:{$path}.");
                    } catch (\Throwable $e) {
                        $counts['failed']++;
                        $this->error("Media {$media->id}: copy failed ({$e->getMessage()}).");
                    }
                }
            });

        $this->table(['Result', 'Count'], [
            ['Migrated', $counts['migrated']],
            ['Already private/verified', $counts['already']],
            ['Dry-run candidates', $counts['dry_run']],
            ['Missing source files', $counts['missing']],
            ['Failures', $counts['failed']],
        ]);

        if ($dryRun) {
            $this->warn('Dry run only: no files were copied, no media rows changed, and no originals were deleted.');
        } else {
            $this->warn('Originals were retained. Remove them manually only after a verified backup and review.');
        }

        return ($counts['missing'] + $counts['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new \RuntimeException("Cannot hash {$path}.");
        }

        return $hash;
    }
}
