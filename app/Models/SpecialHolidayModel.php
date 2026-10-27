<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeInterface;

/**
 * SpecialHolidayModel
 *
 * Manages institutional and official special non-working holiday dates.
 * These dates are dynamically integrated into the WorkCalendar to accurately
 * compute working days, elapsed duration, SLA targets, and ticket timelines.
 */
class SpecialHolidayModel extends Model
{
    protected $table            = 'special_holidays';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'holiday_date',
        'name',
        'type',
        'is_recurring',
        'is_active',
        'notes',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'holiday_date' => 'required',
        'name'         => 'required|min_length[2]|max_length[255]',
        'type'         => 'required|in_list[special_non_working,regular,local_suspension,institutional]',
        'is_recurring' => 'permit_empty',
        'is_active'    => 'permit_empty',
        'notes'        => 'permit_empty',
    ];


    /**
     * Ensure the special_holidays table exists in case migrations have not been run.
     */
    public function ensureTableExists(): void
    {
        try {
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
                    'holiday_date' => [
                        'type' => 'DATE',
                        'null' => false,
                    ],
                    'name' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 255,
                        'null'       => false,
                    ],
                    'type' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 50,
                        'default'    => 'special_non_working',
                    ],
                    'is_recurring' => [
                        'type'       => 'TINYINT',
                        'constraint' => 1,
                        'default'    => 0,
                    ],
                    'is_active' => [
                        'type'       => 'TINYINT',
                        'constraint' => 1,
                        'default'    => 1,
                    ],
                    'notes' => [
                        'type' => 'TEXT',
                        'null' => true,
                    ],
                    'created_at' => [
                        'type'    => 'TIMESTAMP',
                        'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
                    ],
                    'updated_at' => [
                        'type'    => 'TIMESTAMP',
                        'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
                    ],
                ]);
                $forge->addPrimaryKey('id');
                $forge->addKey(['holiday_date', 'is_active']);
                $forge->addKey('is_recurring');
                $forge->createTable($this->table, true, [
                    'ENGINE'          => 'InnoDB',
                    'DEFAULT CHARSET' => 'utf8mb4',
                    'COLLATE'         => 'utf8mb4_unicode_ci',
                ]);
            }
        } catch (\Throwable $e) {
            log_message('error', '[SpecialHolidayModel::ensureTableExists] ' . $e->getMessage());
        }
    }

    /**
     * Standard baseline special non-working holidays.
     */
    public const DEFAULT_SPECIAL_HOLIDAYS = [
        ['holiday_date' => '2026-02-25', 'name' => 'EDSA People Power Revolution Anniversary', 'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Holiday'],
        ['holiday_date' => '2026-08-21', 'name' => 'Ninoy Aquino Day',                         'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Holiday'],
        ['holiday_date' => '2026-11-01', 'name' => "All Saints' Day",                          'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Holiday'],
        ['holiday_date' => '2026-11-02', 'name' => "All Souls' Day",                           'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Day'],
        ['holiday_date' => '2026-12-08', 'name' => 'Feast of the Immaculate Conception',       'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Holiday'],
        ['holiday_date' => '2026-12-24', 'name' => 'Christmas Eve',                            'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Day'],
        ['holiday_date' => '2026-12-31', 'name' => "New Year's Eve",                          'type' => 'special_non_working', 'is_recurring' => 1, 'notes' => 'Special Non-Working Day'],
    ];

    /**
     * Retrieve all active holidays.
     */
    public function getActiveHolidays(): array
    {
        try {
            $results = $this->where('is_active', 1)
                            ->orderBy('holiday_date', 'ASC')
                            ->findAll();

            if (!empty($results)) {
                return $results;
            }
        } catch (\Throwable $e) {
            // DB fallback
        }

        return self::DEFAULT_SPECIAL_HOLIDAYS;
    }

    /**
     * Check if a specific date is configured as a special non-working holiday.
     */
    public function isSpecialHoliday(DateTimeInterface $date): bool
    {
        $ymd = $date->format('Y-m-d');
        $md  = $date->format('m-d');

        try {
            $directMatch = $this->where('holiday_date', $ymd)
                                ->where('is_active', 1)
                                ->countAllResults();

            if ($directMatch > 0) {
                return true;
            }

            // Check recurring holidays
            $recurringMatches = $this->where('is_recurring', 1)
                                     ->where('is_active', 1)
                                     ->findAll();

            foreach ($recurringMatches as $rec) {
                if (!empty($rec['holiday_date'])) {
                    $recMd = date('m-d', strtotime($rec['holiday_date']));
                    if ($recMd === $md) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fallback to defaults
            foreach (self::DEFAULT_SPECIAL_HOLIDAYS as $dh) {
                if ($dh['holiday_date'] === $ymd) {
                    return true;
                }
                if (!empty($dh['is_recurring']) && date('m-d', strtotime($dh['holiday_date'])) === $md) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Seed baseline special holidays if empty.
     */
    public function seedDefaultsIfEmpty(): int
    {
        try {
            $count = $this->countAllResults();
            if ($count > 0) {
                return $count;
            }

            $seeded = 0;
            foreach (self::DEFAULT_SPECIAL_HOLIDAYS as $h) {
                $this->insert([
                    'holiday_date' => $h['holiday_date'],
                    'name'         => $h['name'],
                    'type'         => $h['type'],
                    'is_recurring' => $h['is_recurring'],
                    'is_active'    => 1,
                    'notes'        => $h['notes'],
                ]);
                $seeded++;
            }
            return $seeded;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
