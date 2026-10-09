<?php

namespace App\Models;

use CodeIgniter\Model;

class SystemBackupModel extends Model
{
    protected $table            = 'system_backups';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'file_name',
        'file_path',
        'file_size_bytes',
        'backup_type',
        'backup_category',
        'tables_included',
        'google_drive_file_id',
        'google_drive_link',
        'google_drive_status',
        'google_drive_error',
        'status',
        'notes',
        'created_by',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Fetch all backups with creator information.
     */
    public function getBackupsWithUser(): array
    {
        return $this->select("system_backups.*, NULLIF(TRIM(CONCAT(COALESCE(users.first_name, ''), ' ', COALESCE(users.last_name, ''))), '') as creator_name, users.first_name, users.last_name, users.email as creator_email")
            ->join('users', 'users.id = system_backups.created_by', 'left')
            ->orderBy('system_backups.created_at', 'DESC')
            ->findAll();
    }

    /**
     * Get aggregate statistics on backups.
     */
    public function getBackupStats(): array
    {
        $totalCount = $this->countAllResults(false);
        $totalBytes = $this->selectSum('file_size_bytes')->first()['file_size_bytes'] ?? 0;
        $latest     = $this->orderBy('created_at', 'DESC')->first();
        $gdriveCount = $this->where('google_drive_status', 'uploaded')->countAllResults(false);

        return [
            'total_backups'        => (int) $totalCount,
            'total_size_bytes'     => (int) $totalBytes,
            'latest_backup_at'     => $latest ? $latest['created_at'] : null,
            'gdrive_synced_count'  => (int) $gdriveCount,
        ];
    }
}
