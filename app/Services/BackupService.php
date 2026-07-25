<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class BackupService
{
    public function run(): string
    {
        $backupPath = config('brms.backup_path', storage_path('app/backups'));
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $timestamp = now()->format('Y-m-d-H-i-s');
        $driver = config('database.default');

        if ($driver === 'sqlite') {
            // SQLite: copy the database file directly
            $filename = "brms-backup-{$timestamp}.sqlite";
            $dbPath = database_path('brms.sqlite');
            if (file_exists($dbPath)) {
                copy($dbPath, $backupPath . DIRECTORY_SEPARATOR . $filename);
            }
        } elseif ($driver === 'pgsql') {
            // PostgreSQL: use pg_dump to create a SQL dump
            $filename = "brms-backup-{$timestamp}.sql";
            $dumpPath = $backupPath . DIRECTORY_SEPARATOR . $filename;

            $dbConfig = config('database.connections.pgsql');
            $host = $dbConfig['host'] ?? '127.0.0.1';
            $port = $dbConfig['port'] ?? '5432';
            $database = $dbConfig['database'] ?? '';
            $username = $dbConfig['username'] ?? '';

            $envVars = '';
            if (!empty($dbConfig['password'])) {
                $envVars = "PGPASSWORD=" . escapeshellarg($dbConfig['password']) . ' ';
            }

            $cmd = sprintf(
                '%spg_dump -h %s -p %s -U %s -d %s --no-owner --no-acl -f %s 2>&1',
                $envVars,
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($dumpPath)
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !file_exists($dumpPath)) {
                throw new \RuntimeException('PostgreSQL backup failed: ' . implode("\n", $output));
            }
        } elseif ($driver === 'mysql') {
            // MySQL: use mysqldump
            $filename = "brms-backup-{$timestamp}.sql";
            $dumpPath = $backupPath . DIRECTORY_SEPARATOR . $filename;

            $dbConfig = config('database.connections.mysql');
            $host = $dbConfig['host'] ?? '127.0.0.1';
            $port = $dbConfig['port'] ?? '3306';
            $database = $dbConfig['database'] ?? '';
            $username = $dbConfig['username'] ?? '';

            $passwordArg = '';
            if (!empty($dbConfig['password'])) {
                $passwordArg = '-p' . escapeshellarg($dbConfig['password']);
            }

            $cmd = sprintf(
                'mysqldump -h %s -P %s -u %s %s %s > %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                $passwordArg,
                escapeshellarg($database),
                escapeshellarg($dumpPath)
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !file_exists($dumpPath)) {
                throw new \RuntimeException('MySQL backup failed: ' . implode("\n", $output));
            }
        } else {
            throw new \RuntimeException("Backup is not supported for driver: {$driver}");
        }

        // Log the backup
        $activityLogService = app(ActivityLogService::class);
        $activityLogService->log('backup_created', null, null, null,
            "Database backup created: {$filename}");

        return $filename;
    }

    public function cleanOld(int $keepDays = 30): void
    {
        $backupPath = config('brms.backup_path', storage_path('app/backups'));
        $files = array_merge(
            glob($backupPath . DIRECTORY_SEPARATOR . 'brms-backup-*.sql') ?: [],
            glob($backupPath . DIRECTORY_SEPARATOR . 'brms-backup-*.sqlite') ?: []
        );

        foreach ($files as $file) {
            $fileTime = filemtime($file);
            if ($fileTime < now()->subDays($keepDays)->timestamp) {
                unlink($file);
            }
        }
    }

    public function list(): array
    {
        $backupPath = config('brms.backup_path', storage_path('app/backups'));
        $files = array_merge(
            glob($backupPath . DIRECTORY_SEPARATOR . 'brms-backup-*.sql') ?: [],
            glob($backupPath . DIRECTORY_SEPARATOR . 'brms-backup-*.sqlite') ?: []
        );

        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created_at' => Carbon::createFromTimestamp(filemtime($file))->format('Y-m-d H:i:s'),
            ];
        }

        return array_reverse($backups);
    }
}
