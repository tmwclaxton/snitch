<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys');
        }

        app(ClientRepository::class)->createPersonalAccessGrantClient('MCP Tests', 'users');
    }

    public function test_oauth_authorization_server_metadata_is_exposed(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk()
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
                'response_types_supported',
                'code_challenge_methods_supported',
                'scopes_supported',
                'grant_types_supported',
            ])
            ->assertJsonFragment([
                'registration_endpoint' => url('/oauth/register'),
                'code_challenge_methods_supported' => ['S256'],
                'scopes_supported' => ['mcp:use'],
            ]);
    }

    public function test_oauth_protected_resource_metadata_for_mcp_path(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp');

        $response->assertOk()
            ->assertJsonStructure([
                'resource',
                'authorization_servers',
                'scopes_supported',
            ])
            ->assertJsonFragment([
                'resource' => url('/mcp'),
                'scopes_supported' => ['mcp:use'],
            ]);
    }

    public function test_dynamic_client_registration_accepts_claude_redirect_scheme(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude.ai',
            'redirect_uris' => ['claude://oauth/callback'],
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'client_id',
                'grant_types',
                'response_types',
                'redirect_uris',
                'scope',
                'token_endpoint_auth_method',
            ])
            ->assertJsonFragment([
                'redirect_uris' => ['claude://oauth/callback'],
                'scope' => 'mcp:use',
                'token_endpoint_auth_method' => 'none',
            ]);
    }

    public function test_unauthenticated_mcp_request_returns_resource_metadata_challenge(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        $response->assertUnauthorized();

        $wwwAuthenticate = (string) $response->headers->get('WWW-Authenticate');

        $this->assertStringContainsString('resource_metadata=', $wwwAuthenticate);
        $this->assertStringContainsString(
            url('/.well-known/oauth-protected-resource/mcp'),
            $wwwAuthenticate
        );
    }

    public function test_sanctum_bearer_token_still_authenticates_mcp_route(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createSanctumToken('mcp')->plainTextToken;

        $response = $this->withToken($plainTextToken)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        $response->assertSuccessful();
    }

    public function test_passport_bearer_token_authenticates_mcp_route(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('mcp-oauth-test', ['mcp:use'])->accessToken;

        $response = $this->withToken($accessToken)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        $response->assertSuccessful();
    }

    public function test_create_sanctum_token_for_agents_still_works(): void
    {
        $user = User::factory()->create();

        $token = $user->createSanctumToken('mcp');

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'mcp',
        ]);
    }
}
