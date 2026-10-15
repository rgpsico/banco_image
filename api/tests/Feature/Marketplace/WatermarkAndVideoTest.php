<?php

namespace Tests\Feature\Marketplace;

use App\Enums\TenantRole;
use App\Models\Media;
use App\Modules\Marketplace\Services\WatermarkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class WatermarkAndVideoTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_watermarked_variants_are_derived_from_the_preview_and_differ_from_it(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        $original = Storage::disk('media')->get($media->path);

        app(WatermarkService::class)->generate($media);
        $media = $media->fresh('variants');

        $preview = $media->variants->firstWhere('kind.value', 'preview');
        $wm = $media->variants->firstWhere('kind.value', 'watermarked');
        $thumb = $media->variants->firstWhere('kind.value', 'wm_thumb');

        $this->assertNotNull($wm);
        $this->assertLessThanOrEqual(1200, max($wm->width, $wm->height));
        $this->assertLessThanOrEqual(480, max($thumb->width, $thumb->height));
        $this->assertNotSame(Storage::disk('media')->get($preview->path), Storage::disk('media')->get($wm->path)); // tem marca
        $this->assertSame($original, Storage::disk('media')->get($media->path));                                     // original intocado
    }

    public function test_watermark_falls_back_when_the_font_is_missing(): void
    {
        config(['mediahub.marketplace.watermark_font' => '/nao/existe.ttf']);
        $media = $this->realMediaFor($this->makeSeller());

        app(WatermarkService::class)->generate($media);

        $this->assertNotNull($media->fresh('variants')->variants->firstWhere('kind.value', 'watermarked'));
    }

    public function test_watermark_requires_a_preview(): void
    {
        $media = Media::factory()->create(); // sem variantes

        $this->expectException(\RuntimeException::class);
        app(WatermarkService::class)->generate($media);
    }

    public function test_video_gets_a_poster_thumbnail_and_duration_via_ffmpeg(): void
    {
        $ff = new Process(['ffmpeg', '-version']);
        $ff->run();
        if (! $ff->isSuccessful()) {
            $this->markTestSkipped('ffmpeg não instalado neste ambiente.');
        }

        $file = sys_get_temp_dir() . '/mh_test_' . uniqid() . '.mp4';
        $make = new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=3:size=320x240:rate=10', '-pix_fmt', 'yuv420p', $file]);
        $make->setTimeout(60)->run();
        $this->assertTrue($make->isSuccessful(), $make->getErrorOutput());

        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $res = $this->uploadBytes($editor, $tenant, file_get_contents($file), 'clipe.mp4', 'video/mp4', 20000);
        @unlink($file);

        $res->assertCreated()->assertJsonPath('data.type', 'video')->assertJsonPath('data.status', 'ready')->assertJsonPath('data.width', 320);
        $this->assertNotNull($res->json('data.thumbnail_url'));
        $this->assertNotNull($res->json('data.preview_url'));
        $this->assertSame(3, Media::withoutGlobalScope('tenant')->first()->duration_seconds);
    }

    public function test_fake_video_without_decodable_frames_stays_usable_without_thumbnail(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);

        $this->uploadBytes($editor, $tenant, $this->mp4Bytes(), 'falso.mp4', 'video/mp4', 1500)
            ->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.thumbnail_url', null);
    }
}
