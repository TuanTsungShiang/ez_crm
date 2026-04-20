<?php

namespace Tests\Feature\Api\V1\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegisterSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_returns_expected_structure(): void
    {
        $response = $this->getJson('/api/v1/auth/register/schema');

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'code' => 'S200'])
                 ->assertJsonStructure([
                     'data' => [
                         'fields' => [
                             ['name', 'label', 'type', 'required'],
                         ],
                         'links'           => ['terms', 'privacy'],
                         'oauth_providers' => [],
                     ],
                 ]);

        $fieldNames = collect($response->json('data.fields'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(
            ['name', 'email', 'password', 'password_confirmation', 'phone', 'agree_terms'],
            $fieldNames,
        );

        $this->assertContains('google', $response->json('data.oauth_providers'));
    }

    public function test_schema_endpoint_is_public(): void
    {
        $response = $this->getJson('/api/v1/auth/register/schema');

        $response->assertStatus(200);
    }
}
