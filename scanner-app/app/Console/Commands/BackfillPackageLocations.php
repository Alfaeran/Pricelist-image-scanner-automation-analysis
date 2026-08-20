<?php

namespace App\Console\Commands;

use App\Models\ExtractedPackage;
use Illuminate\Console\Command;

/**
 * Repairs rows written before circle/region/branch were in $fillable and
 * before provider was normalized on the image path. Both defects left every
 * existing row invisible to the location filter, and split carriers such as
 * TSEL/TELKOMSEL across two trend series.
 */
class BackfillPackageLocations extends Command
{
    protected $signature = 'packages:backfill-locations {--branch= : Branch to assign to rows that have no location at all}';

    protected $description = 'Backfill circle/region/branch and normalize provider on existing extracted packages';

    /** Same map ProcessPricelistJob and ScannerController apply on write. */
    private const PROVIDER_ALIASES = [
        'SF' => 'SMARTFREN',
        'TSEL' => 'TELKOMSEL',
        '3ID' => '3',
        'BYU' => 'BY.U',
    ];

    public function handle(): int
    {
        $providerFixed = 0;

        foreach (self::PROVIDER_ALIASES as $alias => $canonical) {
            $providerFixed += ExtractedPackage::where('provider', $alias)
                ->update(['provider' => $canonical]);
        }

        $this->info("Normalized provider on {$providerFixed} rows.");

        $branch = $this->option('branch');

        if (!$branch) {
            $this->warn('No --branch given; skipping location backfill. Rerun with --branch="Surabaya" to assign one.');

            return self::SUCCESS;
        }

        $details = ExtractedPackage::locationDetails($branch);

        if (!$details['region']) {
            $this->error("Could not resolve a region for branch '{$branch}'.");

            return self::FAILURE;
        }

        $located = ExtractedPackage::whereNull('branch')->update([
            'circle' => $details['circle'],
            'region' => $details['region'],
            'branch' => $details['branch'],
        ]);

        $this->info("Assigned {$details['region']} / {$branch} to {$located} rows.");

        return self::SUCCESS;
    }
}
