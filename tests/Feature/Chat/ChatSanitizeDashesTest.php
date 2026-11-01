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
        $service = file_get_contents(app_path('Services/Chat/InAppChatService.php'));

        $this->assertNotFalse($service);
        $this->assertFileDoesNotExist(resource_path('js/pages/chat/Index.vue'));
        $this->assertStringContainsString('Never use em dashes or en dashes', $service);
        $this->assertStringContainsString('sanitizeAssistantText', $service);
    }
}
