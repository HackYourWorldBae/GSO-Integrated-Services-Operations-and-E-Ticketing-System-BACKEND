<?php

namespace App\Commands;

use App\Libraries\BackupService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AutoBackup extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'backup:auto';
    protected $description = 'Executes automated disaster recovery snapshot according to schedule or force flag';
    protected $usage       = 'backup:auto [--force]';
    protected $arguments   = [];
    protected $options     = [
        '--force' => 'Force snapshot creation regardless of schedule due state',
    ];

    public function run(array $params)
    {
        CLI::write('== GSO Disaster Recovery Automated Backup Runner ==', 'yellow');
        $force = array_key_exists('force', $params) || CLI::getOption('force');

        $backupService = new BackupService();
        $config        = $backupService->getScheduleConfig();

        CLI::write("Schedule Config: Frequency={$config['frequency']}, Day={$config['day']}, Time={$config['time']}, Enabled=" . ($config['enabled'] ? 'Yes' : 'No'));
        CLI::write("Next Run: {$config['next_run']}, Is Due: " . ($config['is_due'] ? 'YES' : 'NO'));
        CLI::write("Retention Limit: {$config['retention_limit']} snapshots (FIFO rolling deletion)");

        if (!$force && !$config['is_due']) {
            CLI::write('Automated backup is not due at this moment. Use --force to execute immediately.', 'cyan');
            return;
        }

        CLI::write('Starting disaster recovery snapshot generation...', 'green');
        $result = $backupService->runAutomatedBackup((bool) $force, null);

        if ($result['success']) {
            CLI::write('[SUCCESS] ' . $result['message'], 'green');
            if (!empty($result['backup']['file_name'])) {
                CLI::write('Snapshot file: ' . $result['backup']['file_name'], 'white');
            }
        } else {
            CLI::error('[FAILED] ' . $result['message']);
        }
    }
}
