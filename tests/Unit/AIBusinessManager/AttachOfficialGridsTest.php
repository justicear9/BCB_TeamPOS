<?php

namespace Tests\Unit\AIBusinessManager;

use Modules\AIBusinessManager\Services\AiBusinessAssistantService;
use Modules\AIBusinessManager\Services\BusinessDataToolService;
use Modules\AIBusinessManager\Services\BusinessInsightContextService;
use Modules\AIBusinessManager\Services\OpenAiChatCompletionsService;
use PHPUnit\Framework\TestCase;

class AttachOfficialGridsTest extends TestCase
{
    private function service(): AiBusinessAssistantService
    {
        return new AiBusinessAssistantService(
            $this->createMock(BusinessInsightContextService::class),
            $this->createMock(OpenAiChatCompletionsService::class),
            $this->createMock(BusinessDataToolService::class)
        );
    }

    public function test_keeps_numeric_prose_when_attaching_grids(): void
    {
        $text = 'Airport averages 42 loaves/day (GH₵1,234.50 revenue). Cut Thursday bake by 10%.';
        $grid = "| Location | Qty |\n| --- | --- |\n| Airport | 420 |";

        $out = $this->service()->attachOfficialGrids($text, [$grid]);

        $this->assertStringContainsString('42', $out);
        $this->assertStringContainsString('GH₵1,234.50', $out);
        $this->assertStringContainsString('10%', $out);
        $this->assertStringContainsString($grid, $out);
    }

    public function test_strips_model_markdown_table_when_grids_present(): void
    {
        $text = "Pattern looks soft.\n\n| Shop | Qty |\n| --- | --- |\n| A | 1 |\n| B | 2 |\n";
        $grid = "OFFICIAL_GRID";

        $out = $this->service()->attachOfficialGrids($text, [$grid]);

        $this->assertStringNotContainsString('| Shop | Qty |', $out);
        $this->assertStringContainsString('Pattern looks soft.', $out);
        $this->assertStringContainsString('OFFICIAL_GRID', $out);
    }

    public function test_strips_model_aibm_chart_when_grids_present(): void
    {
        $text = "See trend.\n```aibm-chart\n{\"type\":\"bar\",\"data\":{\"labels\":[\"A\"],\"datasets\":[{\"data\":[1]}]}}\n```\n";
        $grid = "OFFICIAL_GRID";

        $out = $this->service()->attachOfficialGrids($text, [$grid]);

        $this->assertStringNotContainsString('aibm-chart', $out);
        $this->assertStringContainsString('See trend.', $out);
        $this->assertStringContainsString('OFFICIAL_GRID', $out);
    }

    public function test_no_grids_returns_text_unchanged(): void
    {
        $text = 'Airport averages 42 loaves/day.';
        $this->assertSame($text, $this->service()->attachOfficialGrids($text, []));
    }
}
