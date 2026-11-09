<?php

namespace Tests\Feature;

use App\Support\MetaGraph;
use Tests\TestCase;

class MetaGraphVersionTest extends TestCase
{
    public function test_urls_follow_the_configured_graph_version(): void
    {
        config(['services.meta.graph_version' => 'v99.0']);

        $this->assertSame('v99.0', MetaGraph::version());
        $this->assertSame('https://graph.facebook.com/v99.0', MetaGraph::base());
        $this->assertSame('https://graph.facebook.com/v99.0/me', MetaGraph::url('me'));
        $this->assertSame('https://graph.facebook.com/v99.0/me', MetaGraph::url('/me'));
        $this->assertSame('https://www.facebook.com/v99.0', MetaGraph::dialog());
    }

    public function test_empty_config_falls_back_to_the_default_version(): void
    {
        config(['services.meta.graph_version' => '']);

        $this->assertSame('v27.0', MetaGraph::DEFAULT_VERSION);
        $this->assertSame(MetaGraph::DEFAULT_VERSION, MetaGraph::version());
        $this->assertSame('https://graph.facebook.com/'.MetaGraph::DEFAULT_VERSION, MetaGraph::base());
    }
}
