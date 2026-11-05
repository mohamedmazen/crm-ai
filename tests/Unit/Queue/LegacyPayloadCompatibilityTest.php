<?php

namespace Tests\Unit\Queue;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\Broadcasting\Jobs\DispatchCampaignChunkJob;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Ecommerce\Jobs\BackfillStoreOrdersJob;
use App\Modules\Ecommerce\Jobs\SyncStoreCustomersJob;
use App\Modules\Ecommerce\Jobs\SyncStoreProductsJob;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jobs already sitting in Redis when a deploy adds a payload field must keep
 * unserializing. A promoted constructor property does NOT fall back to its
 * default during unserialize() (the constructor never runs), so new optional
 * fields are declared as class properties. These tests pin that.
 */
class LegacyPayloadCompatibilityTest extends TestCase
{
    /**
     * Build a payload shaped like one queued before the given properties existed.
     *
     * @param  array<string, string>  $properties  property name => serialized value
     */
    private function legacyPayload(string $class, array $properties): object
    {
        $body = '';

        foreach ($properties as $name => $serializedValue) {
            $body .= sprintf('s:%d:"%s";%s', strlen($name), $name, $serializedValue);
        }

        return unserialize(sprintf('O:%d:"%s":%d:{%s}', strlen($class), $class, count($properties), $body));
    }

    #[Test]
    public function jobs_queued_before_the_workspace_id_field_restore_without_error(): void
    {
        $jobs = [
            [IndexDocumentJob::class, ['documentId' => 'i:1;']],
            [SendCampaignMessageJob::class, ['campaignId' => 'i:1;', 'contactId' => 'i:2;']],
            [PublishSocialPostJob::class, ['postId' => 'i:1;']],
            [SyncStoreProductsJob::class, ['storeId' => 'i:1;', 'cursor' => 'N;', 'runStartedAt' => 'N;', 'seen' => 'i:0;']],
            [SyncStoreCustomersJob::class, ['storeId' => 'i:1;', 'cursor' => 'N;']],
            [BackfillStoreOrdersJob::class, ['storeId' => 'i:1;', 'cursor' => 'N;']],
        ];

        foreach ($jobs as [$class, $properties]) {
            $job = $this->legacyPayload($class, $properties);

            $this->assertInstanceOf($class, $job);
            $this->assertNull($job->workspaceId, $class.' must default workspaceId to null.');
        }
    }

    #[Test]
    public function legacy_campaign_chunks_restore_the_cursor_default_and_keep_their_contact_ids(): void
    {
        $job = $this->legacyPayload(DispatchCampaignChunkJob::class, [
            'campaignId' => 'i:7;',
            'contactIds' => 'a:2:{i:0;i:11;i:1;i:12;}',
        ]);

        $this->assertInstanceOf(DispatchCampaignChunkJob::class, $job);
        $this->assertSame(0, $job->afterId);
        $this->assertSame([11, 12], $job->contactIds);
    }
}
