<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_backup_contains_encrypted_database_dump_and_excludes_environment(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Esta verificación real requiere MySQL/MariaDB y mysqldump.');
        }
        Notification::fake();
        Storage::fake('backups');
        config([
            'backup.backup.name' => 'smartgate-backup-test',
            'backup.backup.password' => 'test-archive-password',
            'backup.backup.source.databases' => ['mysql'],
        ]);
        (new RunBackup)->handle();
        $files = Storage::disk('backups')->allFiles('smartgate-backup-test');
        $this->assertCount(1, $files);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('backups')->path($files[0])));
        $dumpFound = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $this->assertStringNotContainsString('.env', $name);
            if (str_ends_with($name, '.sql')) {
                $dumpFound = true;
                $this->assertNotSame(ZipArchive::EM_NONE, $zip->statIndex($index)['encryption_method']);
                $zip->setPassword('test-archive-password');
                $dump = $zip->getFromIndex($index);
                $this->assertIsString($dump);
                $this->assertStringContainsString('CREATE TABLE `rfid_assignments`', $dump);
                $this->assertStringContainsString('CREATE TABLE `activity_log`', $dump);
            }
        }
        $zip->close();
        $this->assertTrue($dumpFound);
        $this->assertDatabaseHas('activity_log', ['description' => 'backup.completed']);
        Storage::disk('backups')->delete($files);
    }
}
