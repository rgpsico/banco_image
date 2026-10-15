<?php

namespace Tests\Feature\Media;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Modules\Media\Jobs\GenerateMediaVariants;
use App\Modules\Media\Services\MediaProcessor;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LargeImageTest extends TestCase
{
    use RefreshDatabase;

    private string $originalLimit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLimit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->originalLimit);
        parent::tearDown();
    }

    public function test_a_24_megapixel_photo_raises_the_memory_limit_instead_of_crashing_the_worker(): void
    {
        ini_set('memory_limit', '200M'); // o processo da fila ficava preso em 128M (aqui 200M só para o PHPUnit caber)

        app(MediaProcessor::class)->ensureMemoryFor(5712, 4284); // foto de celular/câmera atual (24 MP)

        $this->assertGreaterThan(300 * 1024 * 1024, (int) ini_get('memory_limit')); // passou a ~320 MB, o necessário para decodificar
    }

    public function test_small_images_leave_the_limit_alone(): void
    {
        ini_set('memory_limit', '512M');
        app(MediaProcessor::class)->ensureMemoryFor(1600, 1067);
        $this->assertSame('512M', ini_get('memory_limit'));
    }

    public function test_images_above_the_configured_ceiling_are_refused_with_a_clear_error(): void
    {
        ini_set('memory_limit', '256M');
        config(['mediahub.processing_memory_mb' => 300]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('grande demais');
        app(MediaProcessor::class)->ensureMemoryFor(8000, 6000);
    }

    public function test_a_crashed_job_never_leaves_the_media_processing_forever(): void
    {
        $media = Media::factory()->create(['status' => MediaStatus::Processing]);

        (new GenerateMediaVariants($media->id))->failed(new \RuntimeException('memória'));

        $this->assertSame(MediaStatus::Failed, Media::withoutGlobalScope('tenant')->find($media->id)->status);
    }
}
