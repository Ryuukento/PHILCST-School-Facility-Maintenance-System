<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep-days= : Number of days to keep old backup files}';

    protected $description = 'Create a MySQL database backup and cleanup old backups';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error('db:backup currently supports only the mysql connection.');
            return self::FAILURE;
        }

        $databaseConfig = config('database.connections.mysql', []);
        $databaseName = (string) ($databaseConfig['database'] ?? 'database');
        $backupDirectory = storage_path('app/backups/database');

        File::ensureDirectoryExists($backupDirectory);

        $timestamp = now()->format('Ymd_His');
        $backupFile = $backupDirectory . DIRECTORY_SEPARATOR . $databaseName . '_backup_' . $timestamp . '.sql';

        $mysqldumpPath = $this->resolveMysqldumpPath();
        $command = [
            $mysqldumpPath,
            '--host=' . (string) ($databaseConfig['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($databaseConfig['port'] ?? '3306'),
            '--user=' . (string) ($databaseConfig['username'] ?? 'root'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--result-file=' . $backupFile,
            $databaseName,
        ];

        $password = (string) ($databaseConfig['password'] ?? '');
        if ($password !== '') {
            $command[] = '--password=' . $password;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('Database backup failed.');
            $this->line(trim($process->getErrorOutput()) ?: trim($process->getOutput()));

            if (File::exists($backupFile)) {
                File::delete($backupFile);
            }

            return self::FAILURE;
        }

        $keepDaysOption = $this->option('keep-days');
        $keepDays = is_numeric($keepDaysOption)
            ? max(1, (int) $keepDaysOption)
            : 14;

        $deletedCount = $this->cleanupOldBackups($backupDirectory, $keepDays);

        $this->info('Database backup created successfully.');
        $this->line('Backup file: ' . $backupFile);
        $this->line('Cleanup removed ' . $deletedCount . ' old backup file(s).');

        return self::SUCCESS;
    }

    private function resolveMysqldumpPath(): string
    {
        $customPath = (string) env('DB_BACKUP_MYSQLDUMP_PATH', '');
        if ($customPath !== '') {
            return $customPath;
        }

        $xamppPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (File::exists($xamppPath)) {
            return $xamppPath;
        }

        return 'mysqldump';
    }

    private function cleanupOldBackups(string $backupDirectory, int $keepDays): int
    {
        $threshold = now()->subDays($keepDays)->getTimestamp();
        $deletedCount = 0;

        foreach (File::files($backupDirectory) as $file) {
            if ($file->getExtension() !== 'sql') {
                continue;
            }

            if ($file->getMTime() < $threshold) {
                File::delete($file->getPathname());
                $deletedCount++;
            }
        }

        return $deletedCount;
    }
}