<?php

namespace Tests\Feature;

use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_and_international_formats_are_the_same_number(): void
    {
        WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, '081234567890');

        $this->assertTrue(WhatsAppSetting::allows('6281234567890'));
        $this->assertTrue(WhatsAppSetting::allows('081234567890'));
        $this->assertFalse(WhatsAppSetting::allows('6289999999999'));
    }

    /**
     * The whitelist previously read env() outside a config file, so a cached
     * config turned it into null and every sender got through.
     */
    public function test_whitelist_survives_config_caching(): void
    {
        WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, '6281234567890');
        config(['services.whatsapp.allowed_numbers' => null]);

        $this->assertFalse(WhatsAppSetting::allows('6289999999999'));
    }

    public function test_empty_or_star_allows_everyone(): void
    {
        WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, '*');
        $this->assertTrue(WhatsAppSetting::allows('6289999999999'));

        WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, '');
        config(['services.whatsapp.allowed_numbers' => null]);
        $this->assertTrue(WhatsAppSetting::allows('6289999999999'));
    }

    public function test_endpoint_normalizes_and_rejects_short_numbers(): void
    {
        $this->postJson('/api/whatsapp/settings', ['allowed_numbers' => '0812-3456-7890, 6285842041644'])
            ->assertOk()
            ->assertJson([
                'allowed_numbers' => '6281234567890,6285842041644',
                'allow_all' => false,
            ]);

        $this->postJson('/api/whatsapp/settings', ['allowed_numbers' => '0812'])
            ->assertStatus(422);

        // The rejected save must not have overwritten the good one.
        $this->getJson('/api/whatsapp/settings')
            ->assertJson(['allowed_numbers' => '6281234567890,6285842041644']);
    }

    public function test_blank_input_resets_to_allow_all(): void
    {
        WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, '6281234567890');

        $this->postJson('/api/whatsapp/settings', ['allowed_numbers' => ''])
            ->assertOk()
            ->assertJson(['allow_all' => true, 'allowed_numbers' => '']);
    }
}
