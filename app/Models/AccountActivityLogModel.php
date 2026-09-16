<?php

namespace App\Models;

use CodeIgniter\Model;

class AccountActivityLogModel extends Model
{
    protected $table         = 'account_activity_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'actor_id',
        'target_user_id',
        'event_type',
        'severity',
        'ip_address',
        'user_agent',
        'device_summary',
        'details',
        'metadata',
        'created_at',
    ];

    /**
     * Ensure the table exists in case migrations have not been run.
     */
    private function ensureTableExists(): void
    {
        $db = $this->db;
        if (!$db->tableExists($this->table)) {
            $forge = \Config\Database::forge();
            $forge->addField([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'actor_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                    'default'    => null,
                ],
                'target_user_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 36,
                    'null'       => true,
                    'default'    => null,
                ],
                'event_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => false,
                ],
                'severity' => [
                    'type'       => 'ENUM',
                    'constraint' => ['info', 'notice', 'warning', 'critical'],
                    'default'    => 'info',
                    'null'       => false,
                ],
                'ip_address' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 45,
                    'null'       => true,
                    'default'    => null,
                ],
                'user_agent' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'default'    => null,
                ],
                'device_summary' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => true,
                    'default'    => null,
                ],
                'details' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'metadata' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'created_at' => [
                    'type'    => 'TIMESTAMP',
                    'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                    'null'    => false,
                ],
            ]);
            $forge->addPrimaryKey('id');
            $forge->addKey('actor_id', false, false, 'idx_act_logs_actor');
            $forge->addKey('target_user_id', false, false, 'idx_act_logs_target');
            $forge->addKey('event_type', false, false, 'idx_act_logs_event');
            $forge->addKey('severity', false, false, 'idx_act_logs_severity');
            $forge->addKey('created_at', false, false, 'idx_act_logs_created');
            $forge->createTable($this->table, true, [
                'ENGINE'          => 'InnoDB',
                'DEFAULT CHARSET' => 'utf8mb4',
                'COLLATE'         => 'utf8mb4_unicode_ci',
            ]);
        }
    }

    /**
     * Parse human-readable client device and platform from User-Agent.
     */
    public static function parseDeviceSummary(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Unknown Client';
        }

        $browser = 'Browser';
        if (stripos($userAgent, 'Edg') !== false) {
            $browser = 'Edge';
        } elseif (stripos($userAgent, 'Chrome') !== false) {
            $browser = 'Chrome';
        } elseif (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (stripos($userAgent, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($userAgent, 'MSIE') !== false || stripos($userAgent, 'Trident') !== false) {
            $browser = 'Internet Explorer';
        }

        $platform = 'Device';
        if (stripos($userAgent, 'iPhone') !== false) {
            $platform = 'iPhone';
        } elseif (stripos($userAgent, 'iPad') !== false) {
            $platform = 'iPad';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $platform = 'Android';
        } elseif (stripos($userAgent, 'Windows') !== false) {
            $platform = 'Windows';
        } elseif (stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS') !== false) {
            $platform = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $platform = 'Linux';
        }

        return "{$browser} on {$platform}";
    }

    /**
     * Log an account or authentication activity event in full compliance with data privacy.
     *
     * @param array $params [
     *   'event_type'     => string (e.g. AUTH_LOGIN_SUCCESS, AUTH_LOGIN_FAILED),
     *   'severity'       => string ('info'|'notice'|'warning'|'critical'),
     *   'actor_id'       => ?string (actor user UUID),
     *   'target_user_id' => ?string (affected user UUID),
     *   'details'        => string,
     *   'metadata'       => ?array,
     *   'ip_address'     => ?string,
     *   'user_agent'     => ?string,
     * ]
     */
    public function logEvent(array $params): bool
    {
        try {
            $this->ensureTableExists();

            $userAgent = $params['user_agent'] ?? null;
            if (empty($userAgent) && function_exists('service')) {
                $req = service('request');
                if ($req && method_exists($req, 'getUserAgent')) {
                    $userAgent = substr($req->getUserAgent()->getAgentString() ?? '', 0, 255);
                }
            }

            $ipAddress = $params['ip_address'] ?? null;
            if (empty($ipAddress) && function_exists('service')) {
                $req = service('request');
                if ($req && method_exists($req, 'getIPAddress')) {
                    $ipAddress = $req->getIPAddress();
                }
            }

            $deviceSummary = $params['device_summary'] ?? self::parseDeviceSummary($userAgent);

            $metadata = $params['metadata'] ?? null;
            if (is_array($metadata)) {
                $metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $insertData = [
                'actor_id'       => !empty($params['actor_id']) ? $params['actor_id'] : null,
                'target_user_id' => !empty($params['target_user_id']) ? $params['target_user_id'] : null,
                'event_type'     => (string) ($params['event_type'] ?? 'UNKNOWN_EVENT'),
                'severity'       => in_array($params['severity'] ?? '', ['info', 'notice', 'warning', 'critical'], true) ? $params['severity'] : 'info',
                'ip_address'     => $ipAddress,
                'user_agent'     => $userAgent ? substr($userAgent, 0, 255) : null,
                'device_summary' => $deviceSummary ? substr($deviceSummary, 0, 100) : null,
                'details'        => !empty($params['details']) ? (string) $params['details'] : null,
                'metadata'       => $metadata,
                'created_at'     => date('Y-m-d H:i:s'),
            ];

            return (bool) $this->insert($insertData);
        } catch (\Throwable $e) {
            log_message('error', '[AccountActivityLogModel::logEvent] Failed to record audit log: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Query account activity logs with faceted filters and user detail hydration.
     */
    public function getFilteredLogs(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $this->ensureTableExists();
        $db = $this->db;

        $builder = $db->table($this->table)
            ->select('
                account_activity_logs.*,
                actor.first_name as actor_first_name,
                actor.last_name as actor_last_name,
                actor.email as actor_email,
                actor.role as actor_role,
                target.first_name as target_first_name,
                target.last_name as target_last_name,
                target.email as target_email,
                target.role as target_role,
                target.student_id_number as target_student_id
            ')
            ->join('users as actor', 'actor.id = account_activity_logs.actor_id', 'left')
            ->join('users as target', 'target.id = account_activity_logs.target_user_id', 'left');

        $this->applyFilters($builder, $filters);

        $logs = $builder->orderBy('account_activity_logs.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        // Count total
        $countBuilder = $db->table($this->table)
            ->join('users as actor', 'actor.id = account_activity_logs.actor_id', 'left')
            ->join('users as target', 'target.id = account_activity_logs.target_user_id', 'left');

        $this->applyFilters($countBuilder, $filters);
        $total = $countBuilder->countAllResults();

        return [
            'logs'  => $logs,
            'total' => $total,
        ];
    }

    /**
     * Apply filter criteria to query builder.
     */
    private function applyFilters(&$builder, array $filters): void
    {
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $builder->groupStart()
                ->like('account_activity_logs.event_type', $search)
                ->orLike('account_activity_logs.details', $search)
                ->orLike('account_activity_logs.ip_address', $search)
                ->orLike('account_activity_logs.device_summary', $search)
                ->orLike('actor.first_name', $search)
                ->orLike('actor.last_name', $search)
                ->orLike('actor.email', $search)
                ->orLike('target.first_name', $search)
                ->orLike('target.last_name', $search)
                ->orLike('target.email', $search)
                ->orLike('target.student_id_number', $search)
                ->groupEnd();
        }

        if (!empty($filters['severity']) && in_array($filters['severity'], ['info', 'notice', 'warning', 'critical'], true)) {
            $builder->where('account_activity_logs.severity', $filters['severity']);
        }

        if (!empty($filters['event_type'])) {
            $builder->where('account_activity_logs.event_type', $filters['event_type']);
        }

        if (!empty($filters['category'])) {
            switch ($filters['category']) {
                case 'auth':
                    $builder->like('account_activity_logs.event_type', 'AUTH_', 'after');
                    break;
                case 'account':
                    $builder->like('account_activity_logs.event_type', 'ACCOUNT_', 'after');
                    break;
                case 'security':
                    $builder->whereIn('account_activity_logs.event_type', ['AUTH_LOCKOUT', 'AUTH_UNLOCK', 'ACCOUNT_STATUS_CHANGED', 'ACCOUNT_DELETED', 'RBAC_UPDATED']);
                    break;
            }
        }

        if (!empty($filters['date_from'])) {
            $builder->where('account_activity_logs.created_at >=', $filters['date_from'] . ' 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            $builder->where('account_activity_logs.created_at <=', $filters['date_to'] . ' 23:59:59');
        }
    }
}
