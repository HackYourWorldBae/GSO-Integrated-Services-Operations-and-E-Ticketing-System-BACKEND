<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * SsuLookupSeeder
 *
 * Seeds the three SSU lookup tables with the predefined options
 * that match the frontend multi-select dropdowns:
 *
 *   - ssu_incident_types  : Categories of security incidents
 *   - ssu_incident_issues : Types of information/impact reported
 *   - ssu_incident_roles  : Reporter's role in the incident
 *
 * Run via: php spark db:seed SsuLookupSeeder
 */
class SsuLookupSeeder extends Seeder
{
    public function run(): void
    {
        // -------------------------------------------------------------------
        // Incident Types (matches frontend 'incidents' multi-select)
        // -------------------------------------------------------------------
        $incidentTypes = [
            ['id' => 1, 'type_name' => 'Theft / Robbery'],
            ['id' => 2, 'type_name' => 'Vandalism / Property Damage'],
            ['id' => 3, 'type_name' => 'Physical Assault / Altercation'],
            ['id' => 4, 'type_name' => 'Trespassing / Unauthorized Entry'],
            ['id' => 5, 'type_name' => 'Road Accident / Vehicular Collision'],
            ['id' => 6, 'type_name' => 'Medical Emergency / Injury'],
            ['id' => 7, 'type_name' => 'Fire / Hazard Alert'],
            ['id' => 8, 'type_name' => 'Other Security Concern'],
        ];
        $this->db->table('ssu_incident_types')->ignore(true)->insertBatch($incidentTypes);

        // -------------------------------------------------------------------
        // Incident Issues / Information (matches frontend 'information' multi-select)
        // -------------------------------------------------------------------
        $incidentIssues = [
            ['id' => 1, 'issue_name' => 'Lost / Stolen Personal Belongings'],
            ['id' => 2, 'issue_name' => 'Damaged University Facilities / Equipment'],
            ['id' => 3, 'issue_name' => 'Safety Policy Violation'],
            ['id' => 4, 'issue_name' => 'Traffic Regulation Violation'],
            ['id' => 5, 'issue_name' => 'Suspicious Activity Observed'],
        ];
        $this->db->table('ssu_incident_issues')->ignore(true)->insertBatch($incidentIssues);

        // -------------------------------------------------------------------
        // Reporter Roles (matches frontend 'roles' multi-select)
        // -------------------------------------------------------------------
        $incidentRoles = [
            ['id' => 1, 'role_name' => 'Victim / Complainant'],
            ['id' => 2, 'role_name' => 'Eyewitness'],
            ['id' => 3, 'role_name' => 'Security Officer on Duty'],
            ['id' => 4, 'role_name' => 'Responding Personnel'],
        ];
        $this->db->table('ssu_incident_roles')->ignore(true)->insertBatch($incidentRoles);
    }
}
