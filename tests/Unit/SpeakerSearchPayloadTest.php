<?php
namespace Tests\Unit;
use App\Http\Controllers\VideoController;
use Tests\TestCase;
class SpeakerSearchPayloadTest extends TestCase
{
    public function test_typed_speaker_name_is_forwarded_as_speaker_filter(): void
    {
        $method = new \ReflectionMethod(VideoController::class, 'normalizeSearchPayload');
        $controller = (new \ReflectionClass(VideoController::class))->newInstanceWithoutConstructor();
        $result = $method->invoke($controller, ['search_type'=>'speaker', 'query'=>' Hafiz Naeem ']);
        $this->assertSame('Hafiz Naeem', $result['speaker']);
        $this->assertSame('simple', $result['search_mode']);
        $this->assertSame('speaker', $result['filter_type']);
    }
    public function test_explicit_speaker_name_takes_precedence(): void
    {
        $method = new \ReflectionMethod(VideoController::class, 'normalizeSearchPayload');
        $controller = (new \ReflectionClass(VideoController::class))->newInstanceWithoutConstructor();
        $result = $method->invoke($controller, ['search_type'=>'speaker', 'query'=>'other', 'speaker'=>'Sam Altman']);
        $this->assertSame('Sam Altman', $result['speaker']);
        $this->assertSame('Sam Altman', $result['query']);
    }
}
