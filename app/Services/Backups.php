<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Sauvegarde complète de Waumini : la base (en SQL, sans mysqldump, souvent
 * interdit sur un hébergement mutualisé) et les fichiers envoyés (logos,
 * photos, justificatifs, audios), dans une archive datée, chiffrée si un mot
 * de passe est réglé. Les plus anciennes sont effacées.
 */
class Backups
{
    public function directory(): string
    {
        return storage_path('app/backups');
    }

    /** Fait une sauvegarde et renvoie le chemin de l'archive. */
    public function run(): string
    {
        File::ensureDirectoryExists($this->directory());
        $name = 'waumini-'.now()->format('Y-m-d-His').'.zip';
        $path = $this->directory().'/'.$name;
        $sql = $this->directory().'/.database-'.uniqid().'.sql';

        try {
            $this->dumpDatabase($sql);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Impossible de créer {$path}");
            }
            $password = (string) config('waumini.backups.password');
            if ($password !== '') {
                $zip->setPassword($password);
            }
            $add = function (string $file, string $entry) use ($zip, $password) {
                $zip->addFile($file, $entry);
                if ($password !== '') {
                    $zip->setEncryptionName($entry, ZipArchive::EM_AES_256);
                }
            };
            $add($sql, 'database.sql');
            $root = Storage::disk('local')->path('');
            foreach (Storage::disk('local')->allFiles() as $file) {
                if (! str_starts_with($file, 'livewire-tmp/')) {
                    $add($root.$file, 'fichiers/'.$file);
                }
            }
            $zip->setArchiveComment('Waumini · sauvegarde du '.now()->format('d/m/Y H:i').' · base : database.sql · fichiers : storage/app/private');
            $zip->close();
        } finally {
            File::delete($sql);
        }

        $this->copyToRemote($path, $name);
        $this->prune();

        return $path;
    }

    /** Les sauvegardes présentes, de la plus récente à la plus ancienne. */
    public function list(): Collection
    {
        File::ensureDirectoryExists($this->directory());

        return collect(File::files($this->directory()))->filter(fn ($f) => $f->getExtension() === 'zip')
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'date' => Carbon::createFromTimestamp($f->getMTime())])
            ->sortByDesc('date')->values();
    }

    public function path(string $name): ?string
    {
        $path = $this->directory().'/'.basename($name);

        return preg_match('/^waumini-[\d-]+\.zip$/', basename($name)) && File::exists($path) ? $path : null;
    }

    private function prune(): void
    {
        $this->list()->slice(max(1, (int) config('waumini.backups.keep', 14)))->each(fn ($b) => File::delete($this->directory().'/'.$b['name']));
    }

    /** Copie l'archive hors du serveur, si un disque distant est réglé (FTP, S3…). */
    private function copyToRemote(string $path, string $name): void
    {
        $disk = config('waumini.backups.disk');
        if ($disk) {
            Storage::disk($disk)->putFileAs('waumini', new \Illuminate\Http\File($path), $name);
        }
    }

    private function dumpDatabase(string $file): void
    {
        $pdo = DB::connection()->getPdo();
        $out = fopen($file, 'w');
        fwrite($out, '-- Waumini · sauvegarde du '.now()->toDateTimeString()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach (DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = array_values((array) $row)[0];
            $create = array_values((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))[1];
            fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            $columns = null;
            $batch = [];
            foreach (DB::table($table)->cursor() as $record) {
                $record = (array) $record;
                $columns ??= '`'.implode('`, `', array_keys($record)).'`';
                $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $record)).')';
                if (count($batch) === 200) {
                    fwrite($out, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($out, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }
}
