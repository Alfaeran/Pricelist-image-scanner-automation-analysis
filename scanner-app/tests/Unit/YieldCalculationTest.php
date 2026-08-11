<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Controllers\ScannerController;
use ReflectionMethod;

/**
 * Unit test untuk logika yield calculation (isUnlimitedPackage + calculateYield).
 * 
 * 11 kasus uji berdasarkan data lapangan dan analisis boundary:
 * - 2 kasus bug utama (Smartfren & IM3 FUP harian)
 * - 6 kasus sanity (paket normal yang tidak boleh berubah)
 * - 3 kasus boundary
 */
class YieldCalculationTest extends TestCase
{
    private ScannerController $controller;
    private ReflectionMethod $isUnlimitedMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ScannerController();
        
        // Buka akses ke private method isUnlimitedPackage() untuk testing
        $this->isUnlimitedMethod = new ReflectionMethod(ScannerController::class, 'isUnlimitedPackage');
        $this->isUnlimitedMethod->setAccessible(true);
    }

    private function callIsUnlimited(string $name, float $gb, int $days, int $price): bool
    {
        return $this->isUnlimitedMethod->invoke($this->controller, $name, $gb, $days, $price);
    }

    // ════════════════════════════════════════════════════════════════
    // Bug Fix Cases — Kasus nyata yang sebelumnya menghasilkan yield "meledak"
    // ════════════════════════════════════════════════════════════════

    /**
     * Smartfren Unlimited Harian FUP 1GB/hari, 28 hari — Rp78.000
     * Sebelum fix: Rp78.000/GB (salah, memperlakukan 1GB sebagai kuota total)
     * Sesudah fix: ~Rp2.786/GB (benar, 78000 / (1 * 28) = 2786)
     */
    public function test_smartfren_fup_harian_28_hari(): void
    {
        $yield = $this->controller->calculateYield('SMARTFREN — Paket Data', 1.0, 28, 78000);
        $this->assertEquals(2786, $yield);
        $this->assertTrue($this->callIsUnlimited('SMARTFREN — Paket Data', 1.0, 28, 78000));
    }

    /**
     * IM3 Voucher FUP 1GB/hari, 30 hari — Rp42.000
     * Sebelum fix: Rp42.000/GB (salah)
     * Sesudah fix: Rp1.400/GB (benar, 42000 / (1 * 30) = 1400)
     */
    public function test_im3_fup_harian_30_hari(): void
    {
        $yield = $this->controller->calculateYield('IM3 — Voucher', 1.0, 30, 42000);
        $this->assertEquals(1400, $yield);
        $this->assertTrue($this->callIsUnlimited('IM3 — Voucher', 1.0, 30, 42000));
    }

    // ════════════════════════════════════════════════════════════════
    // Sanity Cases — Paket normal yang TIDAK BOLEH berubah
    // ════════════════════════════════════════════════════════════════

    /**
     * Paket kecil legit: 1GB/30hr, Rp20.000 — yield wajar di Rp20.000/GB
     * Ini BUKAN FUP karena yield-nya (20000) < threshold (30000)
     */
    public function test_paket_kecil_legit_1gb_30hr(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 1.0, 30, 20000);
        $this->assertEquals(20000, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 1.0, 30, 20000));
    }

    /**
     * Paket kecil legit #2: 2GB/30hr, Rp45.000 — yield Rp22.500/GB
     */
    public function test_paket_kecil_legit_2gb_30hr(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 2.0, 30, 45000);
        $this->assertEquals(22500, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 2.0, 30, 45000));
    }

    /**
     * Bulk non-FUP normal: 25GB/30hr, Rp50.000 — yield Rp2.000/GB
     * GB > 5, jadi Rule 2 tidak berlaku.
     */
    public function test_bulk_non_fup_normal(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 25.0, 30, 50000);
        $this->assertEquals(2000, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 25.0, 30, 50000));
    }

    /**
     * Unlimited FUP dengan GB total besar (bukan harian): 30GB/30hr, Rp100.000
     * GB > 5, jadi Rule 2 tidak berlaku. Yield = ceil(100000/30) = 3334
     */
    public function test_unlimited_fup_gb_total_besar(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 30.0, 30, 100000);
        $this->assertEquals(3334, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 30.0, 30, 100000));
    }

    /**
     * Sachet harian normal: 1GB/1hr, Rp3.000 — yield Rp3.000/GB
     * Masa aktif 1 hari, yield 3000 > MIN_PLAUSIBLE (50), jadi bukan unlimited.
     */
    public function test_sachet_harian_normal(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 1.0, 1, 3000);
        $this->assertEquals(3000, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 1.0, 1, 3000));
    }

    /**
     * Nama eksplisit "Unlimited" — Rule 1 langsung kena.
     * 1GB/28hr, Rp79.000 => yield = ceil(79000 / (1*28)) = 2822
     */
    public function test_nama_eksplisit_unlimited(): void
    {
        $yield = $this->controller->calculateYield('Unlimited Harian 1GB/Hari', 1.0, 28, 79000);
        $this->assertEquals(2822, $yield);
        $this->assertTrue($this->callIsUnlimited('Unlimited Harian 1GB/Hari', 1.0, 28, 79000));
    }

    // ════════════════════════════════════════════════════════════════
    // Boundary Cases — Pengujian batas ambang threshold
    // ════════════════════════════════════════════════════════════════

    /**
     * Boundary: paket legit 14 hari, 1GB, Rp18.000
     * naiveYield = 18000 < 30000 threshold, jadi BUKAN unlimited.
     */
    public function test_boundary_legit_14_hari(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 1.0, 14, 18000);
        $this->assertEquals(18000, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 1.0, 14, 18000));
    }

    /**
     * Boundary: tepat di ambang Rp30.000/GB
     * 5GB/7hr, Rp150.000 => naiveYield = 30000, threshold pakai > (bukan >=)
     * Jadi tepat di ambang = BUKAN unlimited.
     */
    public function test_boundary_tepat_di_ambang(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 5.0, 7, 150000);
        $this->assertEquals(30000, $yield);
        $this->assertFalse($this->callIsUnlimited('Paket Data', 5.0, 7, 150000));
    }

    /**
     * Boundary: sedikit di atas ambang
     * 5GB/7hr, Rp150.005 => naiveYield = 30001, > threshold => unlimited
     * yield = ceil(150005 / (5 * 7)) = ceil(4286) = 4286
     */
    public function test_boundary_sedikit_di_atas_ambang(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 5.0, 7, 150005);
        $this->assertEquals(4286, $yield);
        $this->assertTrue($this->callIsUnlimited('Paket Data', 5.0, 7, 150005));
    }

    // ════════════════════════════════════════════════════════════════
    // Keyword Detection Cases — Pengujian Rule 1 (keyword matching)
    // ════════════════════════════════════════════════════════════════

    /**
     * Keyword 'fup' harus terdeteksi sebagai unlimited.
     */
    public function test_keyword_fup(): void
    {
        $this->assertTrue($this->callIsUnlimited('Paket FUP Harian', 1.0, 28, 78000));
    }

    /**
     * Keyword 'nonstop' harus terdeteksi sebagai unlimited.
     */
    public function test_keyword_nonstop(): void
    {
        $this->assertTrue($this->callIsUnlimited('Nonstop Internet', 2.0, 30, 80000));
    }

    // ════════════════════════════════════════════════════════════════
    // Edge Cases — Input tidak valid
    // ════════════════════════════════════════════════════════════════

    /**
     * GB <= 0 harus mengembalikan yield 0.
     */
    public function test_gb_nol(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 0.0, 30, 50000);
        $this->assertEquals(0, $yield);
    }

    /**
     * Price <= 0 harus mengembalikan yield 0.
     */
    public function test_harga_nol(): void
    {
        $yield = $this->controller->calculateYield('Paket Data', 5.0, 30, 0);
        $this->assertEquals(0, $yield);
    }
}
