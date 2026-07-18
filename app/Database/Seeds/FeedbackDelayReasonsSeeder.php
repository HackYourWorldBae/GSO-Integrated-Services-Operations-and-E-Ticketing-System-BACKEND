<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * FeedbackDelayReasonsSeeder
 *
 * Seeds the feedback_delay_reasons lookup table with the six predefined
 * reason codes that match the frontend evaluation form checkboxes.
 *
 * The `reason_code` field is the camelCase key the frontend sends in the
 * `delay_reasons` array of a feedback submission payload.
 *
 * Run via: php spark db:seed FeedbackDelayReasonsSeeder
 */
class FeedbackDelayReasonsSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            [
                'id'           => 1,
                'reason_code'  => 'personnelAbsent',
                'reason_label' => 'Assigned personnel was absent or unavailable',
            ],
            [
                'id'           => 2,
                'reason_code'  => 'extendedBreak',
                'reason_label' => 'Personnel took extended breaks during the repair/task',
            ],
            [
                'id'           => 3,
                'reason_code'  => 'additionalWork',
                'reason_label' => 'Unexpected additional work or complications arose',
            ],
            [
                'id'           => 4,
                'reason_code'  => 'lackDays',
                'reason_label' => 'Insufficient number of days allotted for the job scope',
            ],
            [
                'id'           => 5,
                'reason_code'  => 'lackMaterials',
                'reason_label' => 'Delay due to lack of replacement parts or materials',
            ],
            [
                'id'           => 6,
                'reason_code'  => 'lackSkills',
                'reason_label' => 'Required specialized tools or external expertise',
            ],
        ];

        $this->db->table('feedback_delay_reasons')->ignore(true)->insertBatch($reasons);
    }
}
