<?php
namespace Tests\Unit;
use App\Jobs\ProcessVideoJob;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
class TranscriptTranslationTest extends TestCase
{
    private function translate(callable $respond): array
    {
        $client = new class($respond) {
            public function __construct(private $respond) {}
            public function chat(): self { return $this; }
            public function create(array $request): object {
                $input=json_decode($request['messages'][1]['content'],true);
                if(count($input)!==1)throw new RuntimeException('Neighboring segments leaked into request');
                return (object)['choices'=>[(object)['message'=>(object)['content'=>json_encode(['translations'=>($this->respond)($input[0])])]]]];
            }
        };
        return (new ReflectionMethod(ProcessVideoJob::class,'translateUtterances'))->invoke(
            new ProcessVideoJob(1,1,'/test.mp4'),$client,[
                ['text'=>'سلام','start'=>1000,'end'=>2000,'actualSpeaker'=>'Named Speaker','words'=>[['text'=>'سلام']]],
                ['text'=>'شکریہ','start'=>2000,'end'=>3000]],'English');
    }
    public function test_each_segment_is_isolated_and_metadata_preserved(): void {
        $rows=$this->translate(fn($s)=>[['index'=>0,'text'=>$s['text']==='سلام'?'Hello':'Thank you']]);
        $this->assertSame(['Hello','Thank you'],array_column($rows,'text'));
        $this->assertSame([1000,2000],array_column($rows,'start'));
        $this->assertSame([2000,3000],array_column($rows,'end'));
        $this->assertSame('Named Speaker',$rows[0]['actualSpeaker']);
        $this->assertSame([],$rows[0]['words']);
    }
    public function test_missing_translation_cannot_fall_back_to_urdu(): void {
        $this->expectException(RuntimeException::class);$this->translate(fn()=>[]);
    }
    public function test_urdu_output_is_rejected(): void {
        $this->expectException(RuntimeException::class);$this->translate(fn()=>[['index'=>0,'text'=>'Hello سلام']]);
    }
    public function test_duplicate_indexes_are_rejected(): void {
        $this->expectException(RuntimeException::class);$this->translate(fn()=>[['index'=>0,'text'=>'Hello'],['index'=>0,'text'=>'Other']]);
    }
    public function test_wrong_index_is_rejected(): void {
        $this->expectException(RuntimeException::class);$this->translate(fn()=>[['index'=>1,'text'=>'Wrong segment']]);
    }
    public function test_english_sentence_cannot_be_saved_as_urdu(): void {
        $client=new class {
            public function chat(): self {return $this;}
            public function create(array $request): object {
                return (object)['choices'=>[(object)['message'=>(object)['content'=>'{"translations":[{"index":0,"text":"Thank you so much"}]}']]]];
            }
        };
        $this->expectException(RuntimeException::class);
        (new ReflectionMethod(ProcessVideoJob::class,'translateUtterances'))->invoke(
            new ProcessVideoJob(1,1,'/test.mp4'),$client,
            [['text'=>'Thank you so much','start'=>1000,'end'=>2000]],'Urdu');
    }
}
