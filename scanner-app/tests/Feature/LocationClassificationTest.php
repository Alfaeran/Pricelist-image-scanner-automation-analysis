<?php

namespace Tests\Feature;

use App\Models\ExtractedPackage;
use App\Models\Pricelist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationClassificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The web-app merge added circle/region/branch columns and passed them to
     * create(), but never added them to $fillable - so Eloquent discarded every
     * value and location filters on /api/trends matched nothing.
     */
    public function test_circle_region_and_branch_survive_mass_assignment(): void
    {
        $pricelist = Pricelist::create(['filename' => 'test.zip', 'status' => 'processed']);

        $package = ExtractedPackage::create([
            'pricelist_id' => $pricelist->id,
            'provider' => 'TELKOMSEL',
            'price' => 50000,
            'gb' => 10,
            'days' => 30,
            'yield_val' => 5000,
            'category' => 'Bulanan (Standar)',
            'circle' => 'Java Bali Nusra',
            'region' => 'Central Java',
            'branch' => 'Semarang',
        ]);

        $this->assertSame([
            'circle' => 'Java Bali Nusra',
            'region' => 'Central Java',
            'branch' => 'Semarang',
        ], $package->fresh()->only(['circle', 'region', 'branch']));
    }

    public function test_branches_map_onto_the_region_hierarchy(): void
    {
        $this->assertSame('Central Java', ExtractedPackage::locationDetails('Semarang')['region']);
        $this->assertSame('East Java', ExtractedPackage::locationDetails('Surabaya')['region']);
        $this->assertSame('Bali Nusra', ExtractedPackage::locationDetails('Denpasar')['region']);
        $this->assertSame('Java Bali Nusra', ExtractedPackage::locationDetails('Semarang')['circle']);
    }

    /**
     * The trend endpoint filters on circle/region/branch. These assertions pin
     * the two shapes that previously returned nothing: a location filter, and a
     * date range evaluated against image_timestamp.
     */
    public function test_trend_endpoint_filters_by_location_and_date(): void
    {
        $pricelist = Pricelist::create(['filename' => 'test.zip', 'status' => 'processed']);

        $base = [
            'pricelist_id' => $pricelist->id,
            'price' => 50000,
            'gb' => 10,
            'days' => 30,
            'yield_val' => 5000,
            'category' => 'Bulanan (Standar)',
            'circle' => 'Java Bali Nusra',
        ];

        ExtractedPackage::create($base + [
            'provider' => 'TELKOMSEL',
            'region' => 'Central Java',
            'branch' => 'Semarang',
            'image_timestamp' => '2026-07-22 10:00:00',
        ]);
        ExtractedPackage::create($base + [
            'provider' => 'XL',
            'region' => 'East Java',
            'branch' => 'Surabaya',
            'image_timestamp' => '2026-07-22 10:00:00',
        ]);

        $this->assertSame(
            1,
            $this->getJson('/api/trends?region=Central Java')->json('kpi.total_packages'),
            'Region filter must exclude the East Java row.'
        );

        $this->assertSame(
            2,
            $this->getJson('/api/trends?start_date=2026-07-01&end_date=2026-07-31')->json('kpi.total_packages'),
            'A range containing both timestamps must keep both rows.'
        );

        $this->assertSame(
            0,
            $this->getJson('/api/trends?start_date=2026-01-01&end_date=2026-01-31')->json('kpi.total_packages'),
            'A range outside both timestamps must exclude everything.'
        );
    }

    public function test_a_missing_branch_yields_no_location(): void
    {
        $this->assertSame(
            ['circle' => null, 'region' => null, 'branch' => null],
            ExtractedPackage::locationDetails(null)
        );
    }
}
