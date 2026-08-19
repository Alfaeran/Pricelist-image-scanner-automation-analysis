<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_the_engine_as_running_when_it_answers(): void
    {
        Http::fake(['*/api/health' => Http::response(['status' => 'ok'], 200)]);

        $this->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('fastapi.running', true)
            ->assertJsonStructure([
                'version',
                'fastapi' => ['running', 'port', 'detail'],
                'queue' => ['running', 'pending', 'detail'],
            ]);
    }

    public function test_health_reports_the_engine_as_down_when_it_cannot_be_reached(): void
    {
        Http::fake(['*/api/health' => fn () => throw new \RuntimeException('connection refused')]);

        $this->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('fastapi.running', false);
    }

    public function test_health_still_answers_when_the_engine_returns_an_error_status(): void
    {
        Http::fake(['*/api/health' => Http::response('boom', 500)]);

        $this->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('fastapi.running', false);
    }

    public function test_restart_rejects_an_unknown_process(): void
    {
        $this->postJson('/api/system/restart', ['process' => 'rm-rf'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_restart_admits_it_cannot_act_on_processes_it_did_not_start(): void
    {
        // Nothing was started in the test process, so this must report failure
        // rather than claiming a restart that never happened.
        $this->postJson('/api/system/restart', ['process' => 'fastapi'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
    }
}
