<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\InAppChatService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatSanitizeDashesTest extends TestCase
{
    #[Test]
    public function it_turns_em_dashes_before_numbers_into_colons(): void
    {
        $service = app(InAppChatService::class);

        $this->assertSame(
            'letsgosocialuk: 104 followers',
            $service->sanitizeAssistantText("letsgosocialuk\u{2014} 104 followers"),
        );
    }

    #[Test]
    public function it_turns_en_dashes_into_commas_otherwise(): void
    {
        $service = app(InAppChatService::class);

        $this->assertSame(
            'Quiet week, then a spike',
            $service->sanitizeAssistantText("Quiet week\u{2013} then a spike"),
        );
    }

    #[Test]
    public function chat_page_uses_plain_remaining_copy_and_markdown(): void
    {
        $chat = file_get_contents(resource_path('js/pages/chat/Index.vue'));
        $markdown = file_get_contents(resource_path('js/lib/markdown.ts'));
        $service = file_get_contents(app_path('Services/Chat/InAppChatService.php'));

        $this->assertNotFalse($chat);
        $this->assertNotFalse($markdown);
        $this->assertNotFalse($service);
        $this->assertStringContainsString('questions left today', $chat);
        $this->assertStringNotContainsString('Remaining today', $chat);
        $this->assertStringNotContainsString('font-mono', $chat);
        $this->assertStringContainsString('linkHandles', $markdown);
        $this->assertStringContainsString('renderMixedLines', $markdown);
        $this->assertStringContainsString('Never use em dashes or en dashes', $service);
        $this->assertStringContainsString('sanitizeAssistantText', $service);
        $this->assertStringNotContainsString('—', $chat);
        $this->assertStringNotContainsString('–', $chat);
    }
}
