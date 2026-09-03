<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\SearchSuggestionService;
use Mockery\MockInterface;

class SearchSuggestionTest extends TestCase
{
    // Note: We don't use RefreshDatabase if we just mock the service

    public function test_valid_suggestion_request()
    {
        $this->mock(SearchSuggestionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getSuggestions')
                 ->with('accounting')
                 ->once()
                 ->andReturn([
                     ['id' => '1', 'title' => 'Accounting Software', 'description' => 'Test']
                 ]);
        });

        $response = $this->getJson('/api/search/suggestions?q=accounting');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'query' => 'accounting',
                     'data' => [
                         ['id' => '1', 'title' => 'Accounting Software']
                     ]
                 ]);
    }

    public function test_fewer_than_2_characters_validation()
    {
        $response = $this->getJson('/api/search/suggestions?q=a');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['q']);
    }

    public function test_missing_q_parameter()
    {
        $response = $this->getJson('/api/search/suggestions');

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['q']);
    }

    public function test_query_longer_than_maximum_length()
    {
        $longQuery = str_repeat('a', 101);
        $response = $this->getJson('/api/search/suggestions?q=' . $longQuery);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['q']);
    }

    public function test_typesense_unavailable_graceful_fallback()
    {
        $this->mock(SearchSuggestionService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getSuggestions')
                 ->andReturn([]); // The service returns empty array on failure
        });

        $response = $this->getJson('/api/search/suggestions?q=test');

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => []
                 ]);
    }
}
