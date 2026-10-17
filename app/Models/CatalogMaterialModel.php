<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * CatalogMaterialModel
 *
 * Centralized master catalog of commonly used materials and supplies,
 * distinctly categorized and partitioned by sub-unit:
 * - FGMU: Facilities & Grounds Management Unit (Structural, MEPFS, Civil)
 * - LEAU: Landscaping & Environmental Aesthetics Unit (Landscaping, Arboriculture, Event Setup, Plants)
 */
class CatalogMaterialModel extends Model
{
    protected $table            = 'catalog_materials';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'unit_code',
        'category',
        'name',
        'unit',
        'default_price',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'unit_code'     => 'required|in_list[FGMU,LEAU]',
        'category'      => 'required|min_length[2]|max_length[60]',
        'name'          => 'required|min_length[2]|max_length[255]',
        'unit'          => 'required|max_length[50]',
        'default_price' => 'permit_empty|numeric|greater_than_equal_to[0]',
        'is_active'     => 'permit_empty|in_list[0,1]',
    ];

    /**
     * Comprehensive initial baseline of default materials by unit.
     */
    public const DEFAULT_CATALOG = [
        // =====================================================================
        // FGMU Materials Catalog
        // =====================================================================
        // Plumbing & Pipe Fittings
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Pipe 1/2 inch', 'unit' => 'lengths', 'default_price' => 120.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Pipe 3/4 inch', 'unit' => 'lengths', 'default_price' => 165.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Pipe 1 inch', 'unit' => 'lengths', 'default_price' => 220.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Elbow 1/2 inch', 'unit' => 'pcs', 'default_price' => 15.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Elbow 3/4 inch', 'unit' => 'pcs', 'default_price' => 20.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Tee 1/2 inch', 'unit' => 'pcs', 'default_price' => 18.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Coupling 1/2 inch', 'unit' => 'pcs', 'default_price' => 12.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Union 1/2 inch', 'unit' => 'pcs', 'default_price' => 45.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'PVC Cement (Solvent)', 'unit' => 'cans', 'default_price' => 85.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Teflon Tape', 'unit' => 'rolls', 'default_price' => 25.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Gate Valve 1/2 inch', 'unit' => 'pcs', 'default_price' => 280.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Ball Valve 1/2 inch', 'unit' => 'pcs', 'default_price' => 180.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Faucet (Lavatory)', 'unit' => 'pcs', 'default_price' => 350.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Faucet (Kitchen Hose Bibb)', 'unit' => 'pcs', 'default_price' => 220.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Angle Valve 1/2 inch', 'unit' => 'pcs', 'default_price' => 140.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Flexible Hose 1/2 inch', 'unit' => 'pcs', 'default_price' => 130.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'P-Trap (Lavatory)', 'unit' => 'pcs', 'default_price' => 190.00],
        ['unit_code' => 'FGMU', 'category' => 'Plumbing', 'name' => 'Floor Drain 4x4 Stainless', 'unit' => 'pcs', 'default_price' => 160.00],

        // Electrical
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'THHN Wire #12 (3.5mm²)', 'unit' => 'meters', 'default_price' => 38.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'THHN Wire #14 (2.0mm²)', 'unit' => 'meters', 'default_price' => 28.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'THHN Wire #10 (5.5mm²)', 'unit' => 'meters', 'default_price' => 55.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'PVC Conduit Pipe 1/2 inch', 'unit' => 'lengths', 'default_price' => 95.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Junction Box 4x4', 'unit' => 'pcs', 'default_price' => 35.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Utility Box 2x4', 'unit' => 'pcs', 'default_price' => 25.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Switch (Single Pole)', 'unit' => 'pcs', 'default_price' => 95.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Switch (3-Way)', 'unit' => 'pcs', 'default_price' => 135.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Outlet (Duplex Grounded)', 'unit' => 'pcs', 'default_price' => 160.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Circuit Breaker 20A Plug-in', 'unit' => 'pcs', 'default_price' => 240.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Circuit Breaker 30A Plug-in', 'unit' => 'pcs', 'default_price' => 260.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'Electrical Tape (Big)', 'unit' => 'rolls', 'default_price' => 45.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'LED Bulb 9W Daylight', 'unit' => 'pcs', 'default_price' => 110.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'LED Bulb 18W Daylight', 'unit' => 'pcs', 'default_price' => 180.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'LED Tube 18W (T8)', 'unit' => 'pcs', 'default_price' => 220.00],
        ['unit_code' => 'FGMU', 'category' => 'Electrical', 'name' => 'LED Panel Light 18W Surface', 'unit' => 'pcs', 'default_price' => 450.00],

        // Carpentry & Hardware
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Marine Plywood 1/4 inch', 'unit' => 'sheets', 'default_price' => 420.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Marine Plywood 1/2 inch', 'unit' => 'sheets', 'default_price' => 780.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Marine Plywood 3/4 inch', 'unit' => 'sheets', 'default_price' => 1250.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Gypsum Board 1/2 inch', 'unit' => 'sheets', 'default_price' => 390.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Metal Stud 2x3', 'unit' => 'lengths', 'default_price' => 160.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Black Screw 1 inch', 'unit' => 'boxes', 'default_price' => 180.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Concrete Nail 2 inch', 'unit' => 'kg', 'default_price' => 90.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Common Nail 2 inch', 'unit' => 'kg', 'default_price' => 75.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Common Nail 3 inch', 'unit' => 'kg', 'default_price' => 75.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Door Knob (Heavy Duty Lockset)', 'unit' => 'pcs', 'default_price' => 550.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Door Hinge 3.5x3.5 Stainless', 'unit' => 'pairs', 'default_price' => 140.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Door Closer Pneumatic', 'unit' => 'pcs', 'default_price' => 850.00],
        ['unit_code' => 'FGMU', 'category' => 'Carpentry', 'name' => 'Padlock Heavy Duty 50mm', 'unit' => 'pcs', 'default_price' => 280.00],

        // Masonry & Concrete
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Portland Cement (Type 1)', 'unit' => 'bags', 'default_price' => 250.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Washed Sand', 'unit' => 'cu.m.', 'default_price' => 850.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Crushed Gravel 3/4', 'unit' => 'cu.m.', 'default_price' => 950.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'CHB 4 inch', 'unit' => 'pcs', 'default_price' => 18.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Deformed Rebar 10mm', 'unit' => 'lengths', 'default_price' => 195.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Tie Wire #16', 'unit' => 'kg', 'default_price' => 85.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Tile Adhesive (25kg)', 'unit' => 'bags', 'default_price' => 290.00],
        ['unit_code' => 'FGMU', 'category' => 'Masonry', 'name' => 'Tile Grout (2kg)', 'unit' => 'bags', 'default_price' => 95.00],

        // Painting
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Flat Latex Paint (White)', 'unit' => 'gallons', 'default_price' => 620.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Semi-Gloss Latex Paint (White)', 'unit' => 'gallons', 'default_price' => 720.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Quick Drying Enamel (White)', 'unit' => 'gallons', 'default_price' => 780.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Epoxy Primer with Catalyst', 'unit' => 'gallons', 'default_price' => 850.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Paint Thinner', 'unit' => 'gallons', 'default_price' => 320.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Paint Roller 7 inch with Handle', 'unit' => 'pcs', 'default_price' => 95.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Paint Brush 2 inch', 'unit' => 'pcs', 'default_price' => 45.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Paint Brush 3 inch', 'unit' => 'pcs', 'default_price' => 65.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Sandpaper #120', 'unit' => 'pcs', 'default_price' => 18.00],
        ['unit_code' => 'FGMU', 'category' => 'Painting', 'name' => 'Polyester Putty with Hardener', 'unit' => 'kg', 'default_price' => 195.00],

        // Aircon & Refrigeration
        ['unit_code' => 'FGMU', 'category' => 'Aircon', 'name' => 'Refrigerant R-410A (11.3kg)', 'unit' => 'kg', 'default_price' => 480.00],
        ['unit_code' => 'FGMU', 'category' => 'Aircon', 'name' => 'Refrigerant R-32', 'unit' => 'kg', 'default_price' => 520.00],
        ['unit_code' => 'FGMU', 'category' => 'Aircon', 'name' => 'Capacitor Dual 50+5 uF', 'unit' => 'pcs', 'default_price' => 380.00],
        ['unit_code' => 'FGMU', 'category' => 'Aircon', 'name' => 'Aircon Insulation Tape (Aerotape)', 'unit' => 'rolls', 'default_price' => 160.00],

        // Welding & Metal Works
        ['unit_code' => 'FGMU', 'category' => 'Welding', 'name' => 'Welding Rod 6013 (Special)', 'unit' => 'kg', 'default_price' => 140.00],
        ['unit_code' => 'FGMU', 'category' => 'Welding', 'name' => 'Cutting Disc 4 inch', 'unit' => 'pcs', 'default_price' => 35.00],
        ['unit_code' => 'FGMU', 'category' => 'Welding', 'name' => 'Grinding Disc 4 inch', 'unit' => 'pcs', 'default_price' => 45.00],
        ['unit_code' => 'FGMU', 'category' => 'Welding', 'name' => 'Angle Bar 1/4 x 1-1/2 x 1-1/2', 'unit' => 'lengths', 'default_price' => 620.00],

        // General Supplies
        ['unit_code' => 'FGMU', 'category' => 'General', 'name' => 'Silicone Sealant (Clear)', 'unit' => 'tubes', 'default_price' => 165.00],
        ['unit_code' => 'FGMU', 'category' => 'General', 'name' => 'Vulca Seal Sealant', 'unit' => 'liters', 'default_price' => 240.00],
        ['unit_code' => 'FGMU', 'category' => 'General', 'name' => 'Contact Cement (Rugby)', 'unit' => 'cans', 'default_price' => 180.00],
        ['unit_code' => 'FGMU', 'category' => 'General', 'name' => 'Tox & Screw #8 Set', 'unit' => 'boxes', 'default_price' => 140.00],

        // =====================================================================
        // LEAU Materials Catalog (Landscaping & Environmental Aesthetics Unit)
        // =====================================================================
        // Landscaping & Grounds Maintenance
        ['unit_code' => 'LEAU', 'category' => 'Grounds Maintenance', 'name' => 'Nylon Trimmer Line 3.0mm (Grass Cutter)', 'unit' => 'rolls', 'default_price' => 350.00],
        ['unit_code' => 'LEAU', 'category' => 'Grounds Maintenance', 'name' => 'Grass Cutter Blade (Metal 2-Tooth)', 'unit' => 'pcs', 'default_price' => 220.00],
        ['unit_code' => 'LEAU', 'category' => 'Grounds Maintenance', 'name' => '2T Engine Oil for Brush Cutters', 'unit' => 'liters', 'default_price' => 185.00],
        ['unit_code' => 'LEAU', 'category' => 'Grounds Maintenance', 'name' => 'Regular Unleaded Gasoline (Equipment Fuel)', 'unit' => 'liters', 'default_price' => 65.00],
        ['unit_code' => 'LEAU', 'category' => 'Grounds Maintenance', 'name' => 'Heavy-Duty Garden Waste Sacks', 'unit' => 'pcs', 'default_price' => 25.00],
        ['unit_code' => 'LEAU', 'category' => 'Grounds Maintenance', 'name' => 'Rake Bamboo / Metal Teeth Replacement', 'unit' => 'pcs', 'default_price' => 120.00],

        // Seedlings & Plants
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Carabao Grass Sod (Square Foot)', 'unit' => 'sq.ft.', 'default_price' => 18.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Bermuda Grass Turf Sod', 'unit' => 'sq.ft.', 'default_price' => 25.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Bougainvillea Potted Shrub', 'unit' => 'pcs', 'default_price' => 150.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'San Francisco Plant (Codiaeum variegatum)', 'unit' => 'pcs', 'default_price' => 120.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Ficus Hedge Seedlings (Green Island)', 'unit' => 'pcs', 'default_price' => 45.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Palmera Palm Seedling (Areca)', 'unit' => 'pcs', 'default_price' => 280.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Yellow Bell Shrub Potted', 'unit' => 'pcs', 'default_price' => 95.00],
        ['unit_code' => 'LEAU', 'category' => 'Seedlings & Plants', 'name' => 'Ornamental Bamboo Cutting', 'unit' => 'pcs', 'default_price' => 75.00],

        // Soil, Fertilizers & Nursery
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Organic Garden Soil / Topsoil', 'unit' => 'bags', 'default_price' => 85.00],
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Vermicast Organic Fertilizer (50kg)', 'unit' => 'bags', 'default_price' => 320.00],
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Complete Fertilizer 14-14-14', 'unit' => 'kg', 'default_price' => 60.00],
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Urea Fertilizer 46-0-0', 'unit' => 'kg', 'default_price' => 55.00],
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Carbonized Rice Hull (CRH)', 'unit' => 'bags', 'default_price' => 110.00],
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Coco Coir Dust / Fiber', 'unit' => 'bags', 'default_price' => 95.00],
        ['unit_code' => 'LEAU', 'category' => 'Soil & Fertilizers', 'name' => 'Black Seedling Nursery Bags 4x6', 'unit' => 'packs', 'default_price' => 80.00],

        // Tree Care & Arboriculture
        ['unit_code' => 'LEAU', 'category' => 'Tree Care', 'name' => 'Chainsaw Bar & Chain Oil', 'unit' => 'liters', 'default_price' => 220.00],
        ['unit_code' => 'LEAU', 'category' => 'Tree Care', 'name' => 'Tree Wound Pruning Sealant / Tar', 'unit' => 'cans', 'default_price' => 290.00],
        ['unit_code' => 'LEAU', 'category' => 'Tree Care', 'name' => 'Heavy-Duty Tree Climbing / Pulling Rope (16mm)', 'unit' => 'meters', 'default_price' => 65.00],
        ['unit_code' => 'LEAU', 'category' => 'Tree Care', 'name' => 'Heavy Duty Burlap Wrap', 'unit' => 'rolls', 'default_price' => 380.00],
        ['unit_code' => 'LEAU', 'category' => 'Tree Care', 'name' => 'Tree Stake Bamboo Posts 2-meter', 'unit' => 'pcs', 'default_price' => 45.00],

        // Irrigation & Watering
        ['unit_code' => 'LEAU', 'category' => 'Irrigation', 'name' => 'Garden Hose 3/4 inch Heavy-Duty (50m)', 'unit' => 'rolls', 'default_price' => 1250.00],
        ['unit_code' => 'LEAU', 'category' => 'Irrigation', 'name' => 'Brass Adjustable Spray Nozzle', 'unit' => 'pcs', 'default_price' => 180.00],
        ['unit_code' => 'LEAU', 'category' => 'Irrigation', 'name' => 'Impact Lawn Sprinkler Head', 'unit' => 'pcs', 'default_price' => 250.00],
        ['unit_code' => 'LEAU', 'category' => 'Irrigation', 'name' => 'Stainless Hose Clamp 3/4 inch', 'unit' => 'pcs', 'default_price' => 25.00],

        // Stage & Event Decoration
        ['unit_code' => 'LEAU', 'category' => 'Event Decoration', 'name' => 'Floral Foam Brick (Oasis Wet)', 'unit' => 'boxes', 'default_price' => 380.00],
        ['unit_code' => 'LEAU', 'category' => 'Event Decoration', 'name' => 'Satin Table & Stage Skirting Fabric', 'unit' => 'meters', 'default_price' => 85.00],
        ['unit_code' => 'LEAU', 'category' => 'Event Decoration', 'name' => 'Artificial Palm Garland / Greenery Drapes', 'unit' => 'pcs', 'default_price' => 210.00],
        ['unit_code' => 'LEAU', 'category' => 'Event Decoration', 'name' => 'Pot Wrap Decorative Foil (Gold/Green)', 'unit' => 'rolls', 'default_price' => 140.00],
        ['unit_code' => 'LEAU', 'category' => 'Event Decoration', 'name' => 'Cable Ties Heavy Duty 12-inch', 'unit' => 'packs', 'default_price' => 110.00],
    ];

    /**
     * Retrieve all materials by unit code with fallback.
     */
    public function getByUnit(string $unitCode = 'ALL', bool $onlyActive = true): array
    {
        $normalizedUnit = strtoupper(trim($unitCode));

        try {
            $builder = $this->builder();
            if ($onlyActive) {
                $builder->where('is_active', 1);
            }
            if ($normalizedUnit !== 'ALL' && in_array($normalizedUnit, ['FGMU', 'LEAU'], true)) {
                $builder->where('unit_code', $normalizedUnit);
            }
            $results = $builder->orderBy('unit_code', 'ASC')
                               ->orderBy('category', 'ASC')
                               ->orderBy('name', 'ASC')
                               ->get()
                               ->getResultArray();

            if (!empty($results)) {
                return $results;
            }
        } catch (\Throwable $e) {
            // DB fallback
        }

        // Return baseline defaults if table is empty or inaccessible
        return array_values(array_filter(self::DEFAULT_CATALOG, function ($item) use ($normalizedUnit) {
            if ($normalizedUnit === 'ALL') {
                return true;
            }
            return $item['unit_code'] === $normalizedUnit;
        }));
    }

    /**
     * Seed baseline catalog materials if the table is empty.
     */
    public function seedDefaultsIfEmpty(): int
    {
        try {
            $count = $this->countAllResults();
            if ($count > 0) {
                return $count;
            }

            $seeded = 0;
            foreach (self::DEFAULT_CATALOG as $item) {
                $this->insert([
                    'unit_code'     => $item['unit_code'],
                    'category'      => $item['category'],
                    'name'          => $item['name'],
                    'unit'          => $item['unit'],
                    'default_price' => $item['default_price'],
                    'is_active'     => 1,
                ]);
                $seeded++;
            }
            return $seeded;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
