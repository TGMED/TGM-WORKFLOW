<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AddLinkHeadersForPreloadedAssets repeats Vite's entire modulepreload
     * graph as a Link header on every response. Vite already writes the same
     * hints as tags in the head, so it buys nothing, and on the dashboard it
     * grew large enough to overrun nginx's FastCGI header buffer and return
     * 502s. This holds the line if someone restores the starter-kit default.
     */
    public function test_responses_do_not_carry_a_preload_link_header(): void
    {
        $location = Location::factory()->create();
        $staff = User::factory()->create(['location_id' => $location->id]);

        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertHeaderMissing('Link');
    }

    /**
     * The signed-out pages carry a much smaller chunk graph, which is why they
     * never overran the buffer. Cover one so a regression is caught wherever it
     * is reintroduced.
     */
    public function test_guest_responses_do_not_carry_a_preload_link_header_either(): void
    {
        $this->get('/login')->assertOk()->assertHeaderMissing('Link');
    }

    /**
     * A guard rail rather than a precise budget. Header overflow shows up as a
     * 502 from the web server with nothing useful in the application log, so it
     * is worth catching here instead.
     */
    public function test_dashboard_response_headers_stay_well_inside_a_4kb_buffer(): void
    {
        $location = Location::factory()->create();
        $staff = User::factory()->create(['location_id' => $location->id]);

        $response = $this->actingAs($staff)->get('/dashboard');

        $bytes = 0;

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                // Name, colon, space and CRLF, as they go over the wire.
                $bytes += strlen((string) $name) + strlen((string) $value) + 4;
            }
        }

        $this->assertLessThan(2048, $bytes, "Dashboard response headers reached {$bytes} bytes.");
    }
}
